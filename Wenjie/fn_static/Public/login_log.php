<?php
/**
 * 会员登录 / 进房日志（供房主后台「登录日志」展示）
 */

function feiniao_client_ip()
{
    $candidates = array(
        isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '',
        isset($_SERVER['HTTP_X_REAL_IP']) ? $_SERVER['HTTP_X_REAL_IP'] : '',
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    );
    foreach ($candidates as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            continue;
        }
        // XFF 可能是 "client, proxy"
        $parts = preg_split('/\s*,\s*/', $raw);
        foreach ($parts as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function feiniao_parse_device($ua)
{
    $ua = (string)$ua;
    if ($ua === '') {
        return '未知设备';
    }
    // 原生壳会在 UA 中附带 DeviceModel/iPhone_13_Pro；优先记录真实型号。
    $reported = isset($_SERVER['HTTP_X_DEVICE_MODEL']) ? (string)$_SERVER['HTTP_X_DEVICE_MODEL'] : '';
    if ($reported === '' && preg_match('/DeviceModel\/([A-Za-z0-9._-]+)/i', $ua, $m)) {
        $reported = str_replace('_', ' ', $m[1]);
    }
    $reported = trim(preg_replace('/[^A-Za-z0-9 ._+()\-]/', '', $reported));
    $aliases = array('ELE-AL00' => '华为 P30');
    if ($reported !== '') {
        $reported = isset($aliases[strtoupper($reported)]) ? $aliases[strtoupper($reported)] : $reported;
        return mb_substr($reported, 0, 128);
    }
    if (preg_match('/Android[^;)]*;\s*(?:[a-z]{2}[-_]?[A-Z]{2};\s*)?([^;)]+?)(?:\s+Build\/[^;)]+)?[;)]/i', $ua, $m)) {
        $model = trim(preg_replace('/\s+Build\/.*$/i', '', $m[1]));
        if ($model !== '' && !preg_match('/^(wv|mobile|browser)$/i', $model)) {
            $model = isset($aliases[strtoupper($model)]) ? $aliases[strtoupper($model)] : $model;
            return mb_substr($model, 0, 128);
        }
    }
    $os = '未知系统';
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = preg_match('/iPad/i', $ua) ? 'iPad' : 'iPhone';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/Windows NT/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'Mac';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }
    $browser = '浏览器';
    if (preg_match('/MicroMessenger/i', $ua)) {
        $browser = '微信';
    } elseif (preg_match('/Edg\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg\//i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'IE';
    }
    return $os . ' / ' . $browser;
}

function feiniao_ensure_login_log_table()
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `fn_login_log` (
      `id` bigint NOT NULL AUTO_INCREMENT,
      `roomid` int NOT NULL DEFAULT 0,
      `actor` varchar(64) NOT NULL DEFAULT '',
      `userid` varchar(64) NOT NULL DEFAULT '',
      `kind` varchar(16) NOT NULL DEFAULT 'member',
      `ip` varchar(64) NOT NULL DEFAULT '',
      `user_agent` varchar(512) NOT NULL DEFAULT '',
      `device` varchar(128) NOT NULL DEFAULT '',
      `location` varchar(128) NOT NULL DEFAULT '',
      `carrier` varchar(128) NOT NULL DEFAULT '',
      `created_at` datetime NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_room_time` (`roomid`,`created_at`),
      KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $ok = @db_query($sql, true);
    if ($ok !== false) {
        $ready = true;
        return true;
    }
    return false;
}

/** 轻量 IP 归属（失败则空，不拖垮登录） */
function feiniao_ip_geo_lookup($ip)
{
    $ip = trim((string)$ip);
    if ($ip === '' || $ip === '0.0.0.0' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        if (filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return array('location' => '内网/本地', 'carrier' => '-');
        }
        return array('location' => '', 'carrier' => '');
    }
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?lang=zh-CN&fields=status,country,regionName,city,isp,org';
    $ctx = stream_context_create(array(
        'http' => array('timeout' => 1.2, 'ignore_errors' => true),
    ));
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) {
        return array('location' => '', 'carrier' => '');
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || ($j['status'] ?? '') !== 'success') {
        return array('location' => '', 'carrier' => '');
    }
    $parts = array_filter(array(
        isset($j['country']) ? $j['country'] : '',
        isset($j['regionName']) ? $j['regionName'] : '',
        isset($j['city']) ? $j['city'] : '',
    ));
    $loc = implode(' ', $parts);
    $carrier = (string)(isset($j['isp']) ? $j['isp'] : (isset($j['org']) ? $j['org'] : ''));
    return array('location' => $loc, 'carrier' => $carrier);
}

/**
 * @param int $roomid
 * @param string $actor 展示名（用户名/房主账号）
 * @param string $userid
 * @param string $kind login|enter|owner
 */
function feiniao_record_login_log($roomid, $actor, $userid, $kind = 'login')
{
    if (!feiniao_ensure_login_log_table()) {
        return false;
    }
    $roomid = (int)$roomid;
    $actor = mb_substr((string)$actor, 0, 64);
    $userid = mb_substr((string)$userid, 0, 64);
    $kind = preg_replace('/[^a-z_]/', '', strtolower((string)$kind)) ?: 'login';
    $ip = feiniao_client_ip();
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $ua = mb_substr($ua, 0, 512);
    $device = feiniao_parse_device($ua);
    $geo = feiniao_ip_geo_lookup($ip);
    $now = date('Y-m-d H:i:s');

    // 防刷：同房同用户同 kind 同 IP 60 秒内只记一条
    $safeUid = function_exists('db_escape_string') ? db_escape_string($userid) : addslashes($userid);
    $safeIp = function_exists('db_escape_string') ? db_escape_string($ip) : addslashes($ip);
    $dup = get_query_val(
        'fn_login_log',
        'id',
        "`roomid`={$roomid} and `userid`='{$safeUid}' and `kind`='{$kind}' and `ip`='{$safeIp}' and `created_at` >= DATE_SUB(NOW(), INTERVAL 60 SECOND) order by id desc limit 1"
    );
    if ($dup) {
        return true;
    }

    return insert_query('fn_login_log', array(
        'roomid' => $roomid,
        'actor' => $actor !== '' ? $actor : $userid,
        'userid' => $userid,
        'kind' => $kind,
        'ip' => $ip,
        'user_agent' => $ua,
        'device' => $device,
        'location' => (string)($geo['location'] ?? ''),
        'carrier' => (string)($geo['carrier'] ?? ''),
        'created_at' => $now,
    )) !== false;
}
