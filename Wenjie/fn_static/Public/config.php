<?php
/**
 * F-10：禁止仓库内可用硬编码凭据。
 * 必须提供 Public/config.local.php，或 FEINIAO_ 系列 / DB_ 系列环境变量。
 */
$__feiniaoLocalCandidates = array(
    __DIR__ . '/config.local.php',
    // 站点可能跑 ROOT/Public，而 local 写在 h5/Public
    dirname(__DIR__) . '/h5/Public/config.local.php',
    dirname(__DIR__) . '/Public/config.local.php',
);
foreach ($__feiniaoLocalCandidates as $__feiniaoLocal) {
    if (@is_file($__feiniaoLocal)) {
        include $__feiniaoLocal;
        break;
    }
}

// 仅当 local 仍无 DB 时，才从站内 bridge/.env 兜底（禁止越出站点根，避免 open_basedir 警告）
if (!isset($db) || !is_array($db) || empty($db['host']) || empty($db['user']) || empty($db['name'])) {
    $__siteRoot = dirname(__DIR__); // .../feiniao9999 或 .../feiniao9999/h5
    if (basename($__siteRoot) === 'h5') {
        $__siteRoot = dirname($__siteRoot);
    }
    $__envCandidates = array(
        $__siteRoot . '/bridge/.env',
        dirname(__DIR__) . '/bridge/.env',
    );
    foreach ($__envCandidates as $__envFile) {
        // @ 抑制 open_basedir 探测警告；realpath 必须仍落在站点根下
        if (!@is_file($__envFile)) {
            continue;
        }
        $__real = @realpath($__envFile);
        if ($__real === false || strpos($__real, $__siteRoot . DIRECTORY_SEPARATOR) !== 0) {
            continue;
        }
        $__env = array();
        foreach (@file($__real, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $__line) {
            $__line = trim($__line);
            if ($__line === '' || $__line[0] === '#' || strpos($__line, '=') === false) {
                continue;
            }
            list($__ek, $__ev) = explode('=', $__line, 2);
            $__env[trim($__ek)] = trim($__ev, " \t\"'");
        }
        if (!isset($db) || !is_array($db)) {
            $db = array();
        }
        if (empty($db['host']) && !empty($__env['DB_HOST'])) {
            $db['host'] = $__env['DB_HOST'];
        }
        if (empty($db['user']) && !empty($__env['DB_USER'])) {
            $db['user'] = $__env['DB_USER'];
        }
        if (empty($db['pass']) && isset($__env['DB_PASSWORD'])) {
            $db['pass'] = $__env['DB_PASSWORD'];
        }
        if (empty($db['name']) && !empty($__env['DB_NAME'])) {
            $db['name'] = $__env['DB_NAME'];
        }
        break;
    }
}

if (!defined('ADMIN_USERNAME')) {
    $v = getenv('FEINIAO_ADMIN_USER');
    // 仅开房后台 kf 需要；缺省不阻断 H5 房间（由 config.local.php 或 env 配置）
    define('ADMIN_USERNAME', ($v !== false && $v !== '') ? $v : '');
}
if (!defined('ADMIN_PASSWORD')) {
    $v = getenv('FEINIAO_ADMIN_PASS');
    define('ADMIN_PASSWORD', ($v !== false && $v !== '') ? $v : '');
}
if (!defined('API_PASSWORD')) {
    $v = getenv('FEINIAO_API_PASSWORD');
    define('API_PASSWORD', ($v !== false && $v !== '') ? $v : '');
}

// S-09 / F-11：Session Cookie 加固；兼容反向代理 HTTPS
// App 后台挂起超过默认 1440s 后会话文件会被 GC 清掉，回前台整页打开 gamelist/room
// 会落到 ajaxMsg JSON 蓝屏。会员端会话拉长到 7 天，并随 Cookie 同步续期。
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$__sessLife = 7 * 24 * 3600;
@ini_set('session.gc_maxlifetime', (string) $__sessLife);
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(array(
        'lifetime' => $__sessLife,
        'path' => '/',
        'secure' => $__https,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
} else {
    session_set_cookie_params($__sessLife, '/; samesite=Lax', '', $__https, true);
}
session_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set("Asia/Shanghai");
header("Content-type:text/html;charset=utf-8");
$load = 5;
include_once("sql.php");
$console = "BLUE娱乐";
if (!isset($db) || !is_array($db)) {
    $db = array();
}
$db['host'] = isset($db['host']) ? $db['host'] : (getenv('FEINIAO_DB_HOST') ?: getenv('DB_HOST'));
$db['user'] = isset($db['user']) ? $db['user'] : (getenv('FEINIAO_DB_USER') ?: getenv('DB_USER'));
$db['pass'] = isset($db['pass']) ? $db['pass'] : (getenv('FEINIAO_DB_PASS') ?: getenv('DB_PASSWORD'));
$db['name'] = isset($db['name']) ? $db['name'] : (getenv('FEINIAO_DB_NAME') ?: getenv('DB_NAME'));
foreach (array('host', 'user', 'pass', 'name') as $__k) {
    if (!isset($db[$__k]) || $db[$__k] === '' || $db[$__k] === false) {
        http_response_code(500);
        die('Missing DB config: set config.local.php or FEINIAO_DB_* / DB_* env');
    }
}
$dbconn = db_connect($db['host'], $db['user'], $db['pass'], $db['name']);
require_once __DIR__ . '/open_state.php';
require_once __DIR__ . '/live_lag.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ledger.php';
// R-09：基础安全响应头（JSON API 仍可正常输出）
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * 飞单上游地址的唯一入口校验。房主后台也不能被用作访问内网的跳板。
 * 生产必须配置 FEINIAO_FLYORDER_HOSTS（逗号分隔的精确域名），否则拒绝请求。
 */
function feiniao_flyorder_site_validate($value, &$safe = null, &$reason = null)
{
    $safe = '';
    $reason = '';
    $url = trim((string) $value);
    if ($url === '' || strlen($url) > 512) {
        $reason = '地址为空或过长'; return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts) || strtolower((string) (isset($parts['scheme']) ? $parts['scheme'] : '')) !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
        $reason = '仅允许不带参数的 https 公网域名地址'; return false;
    }
    $host = strtolower(rtrim((string) $parts['host'], '.'));
    if (filter_var($host, FILTER_VALIDATE_IP)
        || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$/i', $host)) {
        $reason = '域名格式非法'; return false;
    }
    $allowValue = defined('FEINIAO_FLYORDER_HOSTS') ? FEINIAO_FLYORDER_HOSTS : getenv('FEINIAO_FLYORDER_HOSTS');
    $allow = array_filter(array_map('trim', explode(',', (string) $allowValue)));
    $allow = array_map(function ($item) { return strtolower(rtrim($item, '.')); }, $allow);
    if (empty($allow) || !in_array($host, $allow, true)) {
        $reason = '域名未在 FEINIAO_FLYORDER_HOSTS 白名单中'; return false;
    }
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records) || empty($records)) {
        $reason = '域名无法解析'; return false;
    }
    $hasPublicAddress = false;
    foreach ($records as $record) {
        $ip = isset($record['ip']) ? $record['ip'] : (isset($record['ipv6']) ? $record['ipv6'] : '');
        if ($ip === '') continue;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $reason = '域名解析到了内网或保留地址'; return false;
        }
        $hasPublicAddress = true;
    }
    if (!$hasPublicAddress) {
        $reason = '域名没有可用公网地址'; return false;
    }
    $safe = rtrim($url, '/');
    return true;
}
if (!isset($wx) || !is_array($wx)) {
    $wx = array();
}
$wx['ID'] = isset($wx['ID']) ? $wx['ID'] : (getenv('FEINIAO_WX_ID') ?: '');
$wx['key'] = isset($wx['key']) ? $wx['key'] : (getenv('FEINIAO_WX_KEY') ?: '');

$gmidAli=[];
$gmidAli[1]='pk10';   // 澳洲幸运10
$gmidAli[2]='xyft';   // 幸运飞艇
$gmidAli[3]='cqssc';  // 极速飞艇（历史 alias 保留）
$gmidAli[4]='xy28';   // 极速赛车（历史 alias 保留）
$gmidAli[5]='jnd28';  // 弹珠28（原加拿大28）
$gmidAli[6]='jsmt';   // 极速弹珠（原极速摩托）
$gmidAli[7]='jssc';   // 弹珠PK10
$gmidAli[8]='jsssc';  // 极速时时彩
$gmidAli[9]='azxy5';  // 澳洲幸运5
$gmidAli[10]='dzlhc'; // 弹珠六合彩

/** 玩法族：pk10 / pc28 / ssc / lhc */
function getGameKind($gameid){
    $id = (int)$gameid;
    if (in_array($id, array(5), true)) return 'pc28';
    if (in_array($id, array(8, 9), true)) return 'ssc';
    if ($id === 10) return 'lhc';
    return 'pk10';
}

function getGameBallCount($gameid){
    switch (getGameKind($gameid)) {
        case 'pc28': return 3;
        case 'ssc': return 5;
        case 'lhc': return 7;
        default: return 10;
    }
}

/** 弹珠类直播原始 FLV 地址 */
function feiniao_live_flv($gameId){
    $map = array(
        5 => 'https://2cvo356t.zvgcc.com/live/pc28_1080p.flv',   // 弹珠28
        6 => 'https://2cvo356t.zvgcc.com/live/pk1090_1080p.flv', // 极速弹珠
        7 => 'https://2cvo356t.zvgcc.com/live/qj10300_1080p.flv', // 弹珠PK10
        10 => 'https://2cvo356t.zvgcc.com/live/lhc_1080p.flv',   // 弹珠六合彩
    );
    $id = (int) $gameId;
    return isset($map[$id]) ? $map[$id] : '';
}

/** 本站直播播放页（避免第三方页在 App WebView iframe 里黑屏） */
function feiniao_live_url($gameId){
    $flv = feiniao_live_flv($gameId);
    if ($flv === '') return '';
    // 业务开盘仍只认 status=0/drawAt，播放路径不参与业务时钟。
    $srsStreams = array(5 => 'pc28', 6 => 'jsmt', 7 => 'pk10', 10 => 'lhc');
    $id = (int)$gameId;
    if (isset($srsStreams[$id])) {
        // iOS/Android/桌面：统一走本站 SRS HTTP-FLV + HLS
        $stream = $srsStreams[$id];
        $hls = '/live-srs-cdn/' . $stream . '.m3u8';
        return '/Style/live/player.html?v=20260815srshls&url=' . rawurlencode('/live-srs/' . $stream . '.flv')
            . '&hls=' . rawurlencode($hls);
    }
    // 其余第三方 FLV 会主动断开经本站转发的长连接，暂保留直连源站。
    // 业务开盘仍只认 status=0/drawAt，播放路径不参与业务时钟。
    return '/Style/live/player.html?v=20260802stableflv&url=' . rawurlencode($flv);
}

function feiniao_has_live($gameId){
    return feiniao_live_flv($gameId) !== '';
}

/**
 * 有直播玩法：建议提前封盘秒数（开奖前结束倒计时）。
 * 与「开奖结果入库后的动画 hold」是两回事，勿混用。
 */
function feiniao_live_draw_hold_seconds($gameId)
{
    $id = (int) $gameId;
    if (!feiniao_has_live($id)) {
        return 0;
    }
    if ($id === 7 || $id === 10) {
        return 60;
    }
    // 短周期：只提前封盘一小段
    return 12;
}

/**
 * 直播开奖动画 hold：API 号码往往早于画面落定。
 * 结果入库后这段时间内禁止开启下一轮（倒计时/下注/开盘喊话同步延后）。
 */
function feiniao_live_post_draw_hold_seconds($gameId)
{
    $id = (int) $gameId;
    if (!feiniao_has_live($id)) {
        return 0;
    }
    if ($id === 7) {
        return 55;
    }
    if ($id === 10) {
        return 70;
    }
    if ($id === 5) {
        return 35;
    }
    // 极速弹珠：只留短空档，避免直播已出下一期倒计时 App 还卡「开奖中」
    if ($id === 6) {
        return 12;
    }
    return 20;
}

/**
 * 直播开奖空档：publishing（含 status=drawing / Result）→ 开奖中；
 * AtmKai betting（status=live + market=open + drawAt）→ 立刻出倒计时。
 * 优先读采集器文件缓存（与视频同源），再读 DB market_state。
 */
function feiniao_live_post_draw_holding($gameTypeId, $termTime, $now = null, $nextAt = null)
{
    $gameTypeId = (int) $gameTypeId;
    if ($gameTypeId <= 0 || !feiniao_has_live($gameTypeId)) {
        return false;
    }
    $now = $now === null ? (function_exists('GetTtime') ? GetTtime() : time()) : (int) $now;
    $nextAt = $nextAt === null ? 0 : (int) $nextAt;

    // 1) 采集缓存：betting = 直播已出倒计时，空档必须结束
    if (function_exists('feiniao_live_phase_cache_get')) {
        $cache = feiniao_live_phase_cache_get($gameTypeId, 25);
        if (is_array($cache)) {
            $phase = isset($cache['phase']) ? strtolower(trim((string) $cache['phase'])) : '';
            if ($phase === 'betting') {
                return false;
            }
            if ($phase === 'publishing') {
                return true;
            }
        }
    }

    $nextTerm = '';
    $marketState = '';
    if (function_exists('feiniao_open_state_get')) {
        $st = feiniao_open_state_get($gameTypeId);
        if (is_array($st)) {
            if (!empty($st['next_term'])) {
                $nextTerm = trim((string) $st['next_term']);
            }
            if ($nextTerm === '' && !empty($st['market_term'])) {
                $nextTerm = trim((string) $st['market_term']);
            }
            $marketState = strtolower(trim((string) (isset($st['market_state']) ? $st['market_state'] : '')));
        }
    }
    if ($nextTerm === '') {
        $openRow = get_query_vals('fn_open', 'next_term,time,next_time', "type = {$gameTypeId} order by id desc limit 1");
        if ($openRow && !empty($openRow['next_term'])) {
            $nextTerm = trim((string) $openRow['next_term']);
        }
        if (($termTime === '' || $termTime === null) && $openRow && isset($openRow['time'])) {
            $termTime = $openRow['time'];
        }
        if ($nextAt <= 0 && $openRow && !empty($openRow['next_time'])) {
            $nextAt = (int) strtotime((string) $openRow['next_time']);
        }
    }

    // 2) DB 闸门 open → 结束空档（不再强卡期号，防格式不一致卡死）
    if ($marketState === 'open') {
        return false;
    }
    if (function_exists('feiniao_thirdparty_market_open')
        && feiniao_thirdparty_market_open($gameTypeId, $nextTerm)) {
        return false;
    }

    if (is_numeric($termTime) && (int) $termTime > 1000000000) {
        $termAt = (int) $termTime;
    } else {
        $termAt = strtotime((string) $termTime);
    }
    if ($termAt <= 0) {
        return ($marketState === 'pending' || $marketState === '');
    }
    $since = $now - $termAt;
    if ($since < 0 && $since > -30) {
        $since = 0;
    }
    $interval = function_exists('feiniao_feed_interval') ? (int) feiniao_feed_interval($gameTypeId) : 90;
    if ($interval <= 0) {
        $interval = 90;
    }
    if ($since > ($interval + 45)) {
        return false;
    }
    if ($since < 0) {
        return false;
    }
    return true;
}

/**
 * 聊天是否已出现「第 X 期已经开启」开盘喊话。
 * 注意：仅允许带 roomid 的查询；禁止全表扫（会导致房间页白屏/超时）。
 */
if (!function_exists('feiniao_chat_has_open_start')) {
function feiniao_chat_has_open_start($typeId, $term, $roomid = 0, $bypassCache = false)
{
    $typeId = (int) $typeId;
    $roomid = (int) $roomid;
    if ($roomid <= 0) {
        return false;
    }
    $termKey = trim((string) $term);
    if (function_exists('feiniao_chat_open_start_flagged') && feiniao_chat_open_start_flagged($typeId, $termKey, $roomid)) {
        return true;
    }
    // 仅缓存「已发送」命中；禁止缓存 false（否则高频采集会在 2s 内连喊多遍开盘）
    static $cache = array();
    $ck = $roomid . ':' . $typeId . ':' . $termKey;
    $now = time();
    if (!$bypassCache && isset($cache[$ck]) && !empty($cache[$ck]['hit']) && ($now - (int) $cache[$ck]['at']) < 30) {
        return true;
    }
    $gameType = isset($GLOBALS['gmidAli'][$typeId]) ? $GLOBALS['gmidAli'][$typeId] : '';
    if ($gameType === '') {
        return false;
    }
    $gameEsc = function_exists('db_escape_string') ? db_escape_string($gameType) : addslashes($gameType);
    $termEsc = function_exists('db_escape_string') ? db_escape_string($termKey) : addslashes($termKey);
    // 用大 id 窗口限制扫描范围，避免 content LIKE 拖垮整表
    $maxId = (int) get_query_val('fn_chat', 'id', "roomid = {$roomid} order by id desc limit 1");
    $minId = $maxId > 3000 ? ($maxId - 3000) : 0;
    $idFilter = $minId > 0 ? "id >= {$minId} and " : '';
    $id = get_query_val(
        'fn_chat',
        'id',
        "{$idFilter}roomid = {$roomid} and game = '{$gameEsc}' and (content like '%{$termEsc}%期已经开启%' or content like '%{$termEsc}% 期已经开启%') order by id desc limit 1"
    );
    $hit = !empty($id);
    if ($hit) {
        $cache[$ck] = array('at' => $now, 'hit' => true);
        if (function_exists('feiniao_chat_mark_open_start')) {
            feiniao_chat_mark_open_start($typeId, $termKey, $roomid);
        }
        if (count($cache) > 200) {
            $cache = array_slice($cache, -80, null, true);
        }
    }
    return $hit;
}
}

/** 标记某房某期已发开盘（文件旗标，采集并发去重） */
if (!function_exists('feiniao_chat_mark_open_start')) {
function feiniao_chat_mark_open_start($typeId, $term, $roomid)
{
    $typeId = (int) $typeId;
    $roomid = (int) $roomid;
    $termKey = preg_replace('/\W+/', '', (string) $term);
    $dir = __DIR__ . '/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($dir . "/open-start-{$typeId}-{$roomid}-{$termKey}.flag", (string) time(), LOCK_EX);
}
}

/** 开盘消息写入失败时撤销预占旗标，允许采集下一轮或 Bridge 回退补发。 */
if (!function_exists('feiniao_chat_clear_open_start')) {
function feiniao_chat_clear_open_start($typeId, $term, $roomid)
{
    $typeId = (int) $typeId;
    $roomid = (int) $roomid;
    $termKey = preg_replace('/\W+/', '', (string) $term);
    if ($typeId <= 0 || $roomid <= 0 || $termKey === '') {
        return;
    }
    @unlink(__DIR__ . "/runtime/open-start-{$typeId}-{$roomid}-{$termKey}.flag");
}
}

if (!function_exists('feiniao_chat_open_start_flagged')) {
function feiniao_chat_open_start_flagged($typeId, $term, $roomid)
{
    $typeId = (int) $typeId;
    $roomid = (int) $roomid;
    $termKey = preg_replace('/\W+/', '', (string) $term);
    $path = __DIR__ . "/runtime/open-start-{$typeId}-{$roomid}-{$termKey}.flag";
    if (!is_file($path)) {
        return false;
    }
    $ts = (int) @file_get_contents($path);
    return $ts > 0 && (time() - $ts) < 600;
}
}

/**
 * 统一期态：顶栏 / 聊天开盘喊话 / 下注闸门唯一依据。
 * status: open | drawing | sealed
 */
if (!function_exists('feiniao_round_status')) {
function feiniao_round_status($gameTypeId, $roomid = 0, $now = null)
{
    // 热路径短缓存：聊天轮询 + 下注闸门共用，1 秒内不重复重算
    static $rsCache = array();
    $gameTypeId = (int) $gameTypeId;
    $roomid = (int) $roomid;
    $now = $now === null ? (function_exists('GetTtime') ? GetTtime() : time()) : (int) $now;
    $ck = $gameTypeId . ':' . $roomid;
    if (isset($rsCache[$ck]) && ($now - (int) $rsCache[$ck]['at']) < 1) {
        $hit = $rsCache[$ck]['data'];
        if (is_array($hit)) {
            // 按缓存的 next_at 本地推秒，避免状态抖但减轻 CPU
            $nextAt = isset($hit['next_at']) ? (int) $hit['next_at'] : 0;
            $seal = isset($hit['seal']) ? (int) $hit['seal'] : 0;
            if ($nextAt > 0) {
                $raw = max(0, $nextAt - $now);
                $hit['raw_left'] = $raw;
                $hit['left'] = max(0, $raw - $seal);
            }
            return $hit;
        }
    }
    $computed = feiniao_round_status_uncached($gameTypeId, $roomid, $now);
    if (is_array($computed)) {
        $rsCache[$ck] = array('at' => $now, 'data' => $computed);
        if (count($rsCache) > 120) {
            $rsCache = array_slice($rsCache, -50, null, true);
        }
    }
    return $computed;
}

function feiniao_round_status_uncached($gameTypeId, $roomid = 0, $now = null)
{
    $gameTypeId = (int) $gameTypeId;
    $roomid = (int) $roomid;
    $now = $now === null ? (function_exists('GetTtime') ? GetTtime() : time()) : (int) $now;
    $out = array(
        'status' => 'sealed',
        'reason' => 'init',
        'term' => '',
        'next_term' => '',
        'next_at' => 0,
        'raw_left' => 0,
        'left' => 0,
        'seal' => 0,
        'term_time' => '',
        'live_holding' => false,
        'open_announced' => false,
        'code' => '',
    );
    $row = get_query_vals('fn_open', 'term,next_term,next_time,time,code', "type = {$gameTypeId} order by `id` desc limit 1");
    if (!$row) {
        $out['reason'] = 'missing_open_row';
        return $out;
    }
    $term = trim((string) (isset($row['term']) ? $row['term'] : ''));
    $nextTerm = trim((string) (isset($row['next_term']) ? $row['next_term'] : ''));
    $nextAt = strtotime((string) (isset($row['next_time']) ? $row['next_time'] : ''));
    $termTime = (string) (isset($row['time']) ? $row['time'] : '');
    $fengRaw = 0;
    if ($roomid > 0) {
        $fengRaw = get_query_val('fn_lottery' . $gameTypeId, 'fengtime', array('roomid' => $roomid));
    } else {
        $fengRaw = 0;
    }
    $seal = feiniao_effective_fengtime($gameTypeId, $fengRaw);
    $rawLeft = $nextAt > 0 ? max(0, $nextAt - $now) : 0;
    $left = max(0, $rawLeft - $seal);
    $intervalCap = function_exists('feiniao_feed_interval') ? (int) feiniao_feed_interval($gameTypeId) : 0;
    if ($intervalCap <= 0) {
        $intervalCap = in_array($gameTypeId, array(1, 2, 7, 9), true) ? 300 : 90;
    }
    $isLiveRound = function_exists('feiniao_has_live') && feiniao_has_live($gameTypeId);
    $maxBet = max(0, $intervalCap - $seal);
    // 直播实际期长以画面/API 的绝对开奖点为准，不能用名义期长截短，
    // 否则“直播秒数 − 后台 fengtime”的结果会被另一个上限改写。
    if (!$isLiveRound && $maxBet > 0 && $left > $maxBet) {
        $left = $maxBet;
    }
    // 直播画面/HLS 只影响播放器本身，绝不能阻塞 status=0 已写入的业务开盘。
    // 开盘与封盘的唯一时钟是 fn_open.next_time - 本房 fengtime。
    $liveHolding = false;
    $pendingStrict = ($term !== '' && function_exists('feiniao_open_state_is_pending')
        && feiniao_open_state_is_pending($gameTypeId, $term, true));
    $pendingDisplay = ($term !== '' && function_exists('feiniao_open_state_is_pending')
        && feiniao_open_state_is_pending($gameTypeId, $term, false));
    // 热路径不做聊天表扫描（会白屏）；开盘与顶栏一致性靠 should_announce_open 保证
    $openAnnounced = false;

    // live-phase 仅供展示/诊断；fn_open.next_time 是 status=0 推送落库的唯一业务时钟。
    // 禁止缓存回写期号或 drawAt，否则旧 phase 会让倒计时在 1 秒后跳回 5 秒。
    $liveCache = (function_exists('feiniao_live_phase_cache_get') ? feiniao_live_phase_cache_get($gameTypeId, 45) : null);
    // 资金盘口只用第三方 drawAt；禁止播放器/HLS 延迟改写倒计时。
    $liveLag = 0;
    if (is_array($liveCache) && isset($liveCache['phase'])) {
        // 不使用 next_at / next_term；它们可能属于上一期或播放器延迟后的快照。
    }

    // 本房闩锁只能作为旧状态兼容标记，不能覆盖本次 status=0 的绝对开奖点。
    $liveVisualReady = true;
    $roomLatchOpen = false;
    if (false && $roomid > 0 && $nextTerm !== '' && $isLiveRound
        && function_exists('feiniao_room_open_latch_get')) {
        $rl = feiniao_room_open_latch_get($gameTypeId, $roomid, $nextTerm);
        if (!is_array($rl) && function_exists('feiniao_chat_open_start_flagged')
            && feiniao_chat_open_start_flagged($gameTypeId, $nextTerm, $roomid)
            && function_exists('feiniao_room_open_latch_set')) {
            $peek = function_exists('feiniao_live_phase_cache_peek')
                ? feiniao_live_phase_cache_peek($gameTypeId) : null;
            $recApi = 0;
            $recLag = 0;
            if (is_array($peek)) {
                if (!empty($peek['next_at_ms'])) {
                    $recApi = (int) $peek['next_at_ms'];
                } elseif (!empty($peek['next_at'])) {
                    $recApi = (int) $peek['next_at'] * 1000;
                }
            }
            if ($recApi <= 0 && $nextAt > 0) {
                $recApi = $nextAt * 1000;
                if ($recApi <= 0) {
                    $recApi = $nextAt * 1000;
                }
            }
            if ($recApi > 0) {
                $rl = feiniao_room_open_latch_set($gameTypeId, $roomid, $nextTerm, $recApi, $recLag);
            }
        }
        if (is_array($rl) && function_exists('feiniao_room_open_latch_clock')) {
            $clk = feiniao_room_open_latch_clock($rl, $seal);
            if (is_array($clk) && (int) $clk['bet_close_at_ms'] > ($now * 1000)) {
                $nextAt = (int) floor(((int) $clk['effective_next_at_ms']) / 1000);
                $rawLeft = max(0, $nextAt - $now);
                $left = max(0, (int) floor((((int) $clk['bet_close_at_ms']) / 1000) - $now));
                $liveHolding = false;
                $pendingDisplay = false;
                $pendingStrict = false;
                $roomLatchOpen = ($left > 0);
            }
        }
    }

    $out['term'] = $term;
    $out['next_term'] = $nextTerm;
    $out['next_at'] = $nextAt;
    $out['raw_left'] = $rawLeft;
    $out['left'] = $left;
    $out['seal'] = $seal;
    $out['term_time'] = $termTime;
    $out['live_holding'] = $liveHolding;
    $out['open_announced'] = $openAnnounced;
    $out['code'] = (string) (isset($row['code']) ? $row['code'] : '');

    $chatOpenedNext = false;
    if ($roomid > 0 && $nextTerm !== '' && function_exists('feiniao_chat_has_open_start')) {
        $chatOpenedNext = feiniao_chat_has_open_start($gameTypeId, $nextTerm, $roomid);
    }
    // 封盘标记是同一期的最高优先级状态：一经写入，旧轮询/旧 drawAt 即使
    // 把 left 回写成正数，也绝不能让顶栏或下注从「封盘中」复活。
    // 新一期的 next_term 不同，不会命中这一个标记，因此不会妨碍真实 status=0 开盘。
    $markedSealed = ($roomid > 0 && $nextTerm !== '' && function_exists('feiniao_term_marked_sealed')
        && feiniao_term_marked_sealed($roomid, $gameTypeId, $nextTerm));
    if ($markedSealed) {
        if ($rawLeft > 0) {
            $out['status'] = 'sealed';
            $out['reason'] = 'marker_sealed';
            $out['left'] = $rawLeft;
            $out['raw_left'] = $rawLeft;
            return $out;
        }
        $out['status'] = 'drawing';
        $out['reason'] = 'marker_sealed';
        $out['left'] = 0;
        $out['display_term'] = $nextTerm;
        return $out;
    }

    // 业务开盘只认 status=0 已落库的未来绝对开奖点，不依赖视频遥测。
    if ($left > 0 && (
        $roomLatchOpen
        || (is_array($liveCache) && isset($liveCache['phase']) && $liveCache['phase'] === 'betting'
            && strtolower(trim((string) (isset($liveCache['market_state']) ? $liveCache['market_state'] : ''))) === 'open')
        || (function_exists('feiniao_thirdparty_market_open') && feiniao_thirdparty_market_open($gameTypeId, $nextTerm))
        || $chatOpenedNext
    )) {
        $out['status'] = 'open';
        $out['reason'] = $roomLatchOpen ? 'open_latched' : 'live_betting';
        $out['left'] = $left;
        $out['raw_left'] = $rawLeft;
        $out['next_at'] = $nextAt;
        return $out;
    }
    // 后处理（生图/聊天）慢不能覆盖真实的封盘窗口。只要仍未到开奖点且
    // 可下注截止已到，就必须显示「封盘中」；否则会出现视频还在倒数却显示「开奖中」。
    if ($left <= 0 && $rawLeft > 0) {
        $out['status'] = 'sealed';
        $out['reason'] = 'seal_window';
        $out['left'] = $rawLeft;
        return $out;
    }

    if ($pendingStrict || ($pendingDisplay && $rawLeft <= 0 && !$chatOpenedNext)) {
        $out['status'] = 'drawing';
        $out['reason'] = 'processing';
        $out['left'] = 0;
        return $out;
    }

    if ($left > 0 && !$pendingStrict) {
        $out['status'] = 'open';
        $out['reason'] = $chatOpenedNext ? 'chat_open' : 'betting_window';
        return $out;
    }

    // 可下注归零、距开奖仍有剩余 → 封盘中（倒计时用 rawLeft）
    if ($rawLeft > 0) {
        $out['status'] = 'sealed';
        $out['reason'] = 'sealed';
        $out['left'] = $rawLeft;
        return $out;
    }

    // next_time 已过：等开奖。聊天已开下一期时顶栏跟 next_term
    if (feiniao_has_live($gameTypeId) || $chatOpenedNext) {
        $out['status'] = 'drawing';
        $out['reason'] = $chatOpenedNext ? 'await_draw_next' : 'await_draw';
        $out['left'] = 0;
        $out['display_term'] = $nextTerm !== '' ? $nextTerm : $term;
        return $out;
    }

    $out['status'] = 'drawing';
    $out['reason'] = 'expired';
    $out['left'] = 0;
    $out['display_term'] = $nextTerm !== '' ? $nextTerm : $term;
    return $out;
}
}

/** 是否允许发送「第 X 期已经开启，请开始下注」——必须与本房 getOpenInfo 同一次开盘条件 */
if (!function_exists('feiniao_should_announce_open')) {
function feiniao_should_announce_open($gameTypeId, $term, $roomid = 0, $now = null)
{
    $gameTypeId = (int) $gameTypeId;
    $roomid = (int) $roomid;
    $term = trim((string) $term);
    $nowMs = (int) round(microtime(true) * 1000);
    if ($now !== null) {
        $nowMs = ((int) $now) * 1000;
    }

    $row = get_query_vals('fn_open', 'term,next_term,next_time,time', "type = {$gameTypeId} order by id desc limit 1");
    if (!$row) {
        return false;
    }
    $nextTerm = trim((string) (isset($row['next_term']) ? $row['next_term'] : ''));
    $a = ltrim($nextTerm, '0');
    $b = ltrim($term, '0');
    if ($a === '') {
        $a = '0';
    }
    if ($b === '') {
        $b = '0';
    }
    if ($nextTerm !== '' && $a !== $b) {
        return false;
    }
    if ($term !== '' && $nextTerm !== '' && function_exists('feiniao_terms_loosely_equal')
        && !feiniao_terms_loosely_equal($term, $nextTerm)) {
        return false;
    }
    if ($nextTerm !== '' && $roomid > 0 && feiniao_chat_has_open_start($gameTypeId, $nextTerm, $roomid)) {
        return false;
    }
    // Bridge 已封盘：禁止再喊「请开始下注」
    if ($roomid > 0 && $nextTerm !== '' && function_exists('feiniao_term_marked_sealed')
        && feiniao_term_marked_sealed($roomid, $gameTypeId, $nextTerm)) {
        return false;
    }

    // ★ 与顶栏/下注闸门同源：必须本房 getOpenInfo 已是 open 且 bet_close 仍在未来
    $code = '';
    if (isset($GLOBALS['gmidAli'][$gameTypeId])) {
        $code = (string) $GLOBALS['gmidAli'][$gameTypeId];
    }
    if ($code === '' || !function_exists('getOpenInfo')) {
        return false;
    }
    $prevRoom = isset($_SESSION['roomid']) ? $_SESSION['roomid'] : null;
    $prevGame = isset($_COOKIE['game']) ? $_COOKIE['game'] : null;
    $_SESSION['roomid'] = $roomid > 0 ? $roomid : (isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0);
    try {
        $info = getOpenInfo($code);
    } catch (Throwable $e) {
        $info = null;
        error_log('[feiniao] should_announce getOpenInfo failed: ' . $e->getMessage());
    }
    if ($prevRoom === null) {
        unset($_SESSION['roomid']);
    } else {
        $_SESSION['roomid'] = $prevRoom;
    }
    if ($prevGame === null) {
        // getOpenInfo 会写 $_COOKIE['game']；恢复原值避免污染采集进程
        if (isset($_COOKIE['game'])) {
            unset($_COOKIE['game']);
        }
    } else {
        $_COOKIE['game'] = $prevGame;
    }

    if (!is_array($info)) {
        return false;
    }
    $status = isset($info['draw_status']) ? (string) $info['draw_status'] : '';
    $closeMs = isset($info['bet_close_at_ms']) ? (int) $info['bet_close_at_ms'] : 0;
    $left = isset($info['letf_time']) ? (int) $info['letf_time'] : 0;
    if ($status !== 'open') {
        return false;
    }
    if (!($closeMs > $nowMs) || $left <= 0) {
        return false;
    }
    // 期号再对齐一次（展示期/完整期）
    $infoTerm = '';
    if (!empty($info['next_term'])) {
        $infoTerm = trim((string) $info['next_term']);
    } elseif (!empty($info['next_sn'])) {
        $infoTerm = trim((string) $info['next_sn']);
    }
    if ($term !== '' && $infoTerm !== '') {
        if (function_exists('feiniao_terms_loosely_equal')) {
            if (!feiniao_terms_loosely_equal($term, $infoTerm)) {
                return false;
            }
        } else {
            $ia = ltrim($infoTerm, '0');
            $ib = ltrim($term, '0');
            if (($ia === '' ? '0' : $ia) !== ($ib === '' ? '0' : $ib)) {
                return false;
            }
        }
    }
    return true;
}
}

/** 去掉聊天里的「点击 Live/Liv 直播/自播开奖」提示（含彩色 HTML、箭头装饰） */
function feiniao_strip_live_tip($html){
    $s = (string) $html;
    if ($s === '') return $s;
    // 整段带标签的提示
    $s = preg_replace('/<[^>]*>\s*点击\s*Liv?e?\s*[直自]播开奖[!！]*\s*<\/[^>]*>/iu', '', $s);
    // 纯文本提示
    $s = preg_replace('/点击\s*Liv?e?\s*[直自]播开奖[!！]*/iu', '', $s);
    $s = preg_replace('/Liv?e?\s*[直自]播开奖[!！]*/iu', '', $s);
    // 单独箭头/提示图
    $s = preg_replace('/<img[^>]*(?:live[_-]?tip|live[_-]?arrow|livetip|直播提示|直播箭头)[^>]*>/iu', '', $s);
    return $s;
}

function getGameTxtName($gameid=''){
    $gmidName=[];
    $gmidName[1]='澳洲幸运10';
    $gmidName[2]='幸运飞艇';
    $gmidName[3]='极速飞艇';
    $gmidName[4]='极速赛车';
    $gmidName[5]='弹珠28';
    $gmidName[6]='极速弹珠';
    $gmidName[7]='弹珠PK10';
    $gmidName[8]='极速时时彩';
    $gmidName[9]='澳洲幸运5';
    $gmidName[10]='弹珠六合彩';
    if(empty($gameid)) return $gmidName;
    else return (isset($gmidName[$gameid])?$gmidName[$gameid]:'----');
}

function getGameTxtNameByCode($code){
    $gimeid=getGameIdByCode($code);
    return getGameTxtName($gimeid);
}

/**
 * 房间介绍「彩种赔率」字段目录：与 bridge games.js oddsFieldsForGame 对齐。
 * 返回 [ ['key'=>..., 'label'=>..., 'sync'=>[...]], ... ]
 */
function feiniao_odds_catalog_for_kind($kind)
{
    $kind = strtolower((string) $kind);
    if ($kind === 'pc28') {
        return array(
            array('key' => 'dxds', 'label' => '大小 - 大小', 'sync' => array()),
            array('key' => 'tema', 'label' => '1-3球号 - 1-3球号', 'sync' => array()),
            array('key' => 'long', 'label' => '龙虎和 - 龙虎', 'sync' => array('hu')),
            array('key' => 'da', 'label' => '定位两面 - 定位两面', 'sync' => array('xiao', 'dan', 'shuang')),
            array('key' => 'he', 'label' => '龙虎和 - 和', 'sync' => array()),
            array('key' => 'dan', 'label' => '单双 - 单双', 'sync' => array('shuang')),
            array('key' => 'jida', 'label' => '极值大小 - 极值大小', 'sync' => array('jixiao')),
            array('key' => 'dadan', 'label' => '大小单双 - 大小单双', 'sync' => array('xiaodan', 'dashuang', 'xiaoshuang')),
            array('key' => '0027', 'label' => '和值 - 0,27', 'sync' => array()),
            array('key' => '0126', 'label' => '和值 - 1,26', 'sync' => array()),
            array('key' => '0225', 'label' => '和值 - 2,25', 'sync' => array()),
            array('key' => '0324', 'label' => '和值 - 3,24', 'sync' => array()),
            array('key' => '0423', 'label' => '和值 - 4,23', 'sync' => array()),
            array('key' => '0522', 'label' => '和值 - 5,22', 'sync' => array()),
            array('key' => '0621', 'label' => '和值 - 6,21', 'sync' => array()),
            array('key' => '0720', 'label' => '和值 - 7,20', 'sync' => array()),
            array('key' => '891819', 'label' => '和值 - 8,9,18,19', 'sync' => array()),
            array('key' => '10111617', 'label' => '和值 - 10,11,16,17', 'sync' => array()),
            array('key' => '1215', 'label' => '和值 - 12,15', 'sync' => array()),
            array('key' => '1314', 'label' => '和值 - 13,14', 'sync' => array()),
            array('key' => 'baozi', 'label' => '豹子 - 豹子', 'sync' => array()),
            array('key' => 'shunzi', 'label' => '顺子 - 顺子', 'sync' => array()),
            array('key' => 'duizi', 'label' => '对子 - 对子', 'sync' => array()),
        );
    }
    if ($kind === 'ssc') {
        return array(
            array('key' => 'da', 'label' => '两面 - 两面', 'sync' => array('xiao', 'dan', 'shuang', 'zongda', 'zongxiao', 'zongdan', 'zongshuang')),
            array('key' => 'tema', 'label' => '1-5球号 - 1-5球号', 'sync' => array()),
            array('key' => 'long', 'label' => '龙虎和 - 龙虎', 'sync' => array('hu')),
            array('key' => 'q_baozi', 'label' => '前三后三中三 - 豹子', 'sync' => array('z_baozi', 'h_baozi')),
            array('key' => 'he', 'label' => '龙虎和 - 和', 'sync' => array()),
            array('key' => 'q_shunzi', 'label' => '前三后三中三 - 顺子', 'sync' => array('z_shunzi', 'h_shunzi')),
            array('key' => 'q_duizi', 'label' => '前三后三中三 - 对子', 'sync' => array('z_duizi', 'h_duizi')),
            array('key' => 'q_banshun', 'label' => '前三后三中三 - 半顺', 'sync' => array('z_banshun', 'h_banshun')),
            array('key' => 'q_zaliu', 'label' => '前三后三中三 - 杂六', 'sync' => array('z_zaliu', 'h_zaliu')),
        );
    }
    if ($kind === 'lhc') {
        return array(
            array('key' => 'tema', 'label' => '特码A - 1-49', 'sync' => array()),
            array('key' => 'da', 'label' => '特码A - 特大', 'sync' => array()),
            array('key' => 'xiao', 'label' => '特码A - 特小', 'sync' => array()),
            array('key' => 'dan', 'label' => '特码A - 特单', 'sync' => array()),
            array('key' => 'shuang', 'label' => '特码A - 特双', 'sync' => array()),
        );
    }
    // pk10 默认
    return array(
        array('key' => 'tema', 'label' => '1-10车号 - 1-10车号', 'sync' => array()),
        array('key' => 'da', 'label' => '两面 - 两面', 'sync' => array('xiao', 'dan', 'shuang', 'long', 'hu')),
        array('key' => 'heda', 'label' => '冠亚军和大小 - 大', 'sync' => array()),
        array('key' => 'hedan', 'label' => '冠亚军和单双 - 单', 'sync' => array()),
        array('key' => 'he341819', 'label' => '冠亚军和 - 3,4,18,19', 'sync' => array()),
        array('key' => 'hexiao', 'label' => '冠亚军和大小 - 小', 'sync' => array()),
        array('key' => 'heshuang', 'label' => '冠亚军和单双 - 双', 'sync' => array()),
        array('key' => 'he561617', 'label' => '冠亚军和 - 5,6,16,17', 'sync' => array()),
        array('key' => 'he781415', 'label' => '冠亚军和 - 7,8,14,15', 'sync' => array()),
        array('key' => 'he9101213', 'label' => '冠亚军和 - 9,10,12,13', 'sync' => array()),
        array('key' => 'he11', 'label' => '冠亚军和 - 11', 'sync' => array()),
    );
}

/**
 * 从 fn_lottery 行生成房间介绍赔率列表。
 * 一行对应后台「赔率限额」一条规则（不展开 syncKeys），标签与后台一致。
 */
function feiniao_intro_odds_from_lottery($gameTypeId, $row)
{
    if (!is_array($row)) {
        return array();
    }
    $kind = getGameKind($gameTypeId);
    $catalog = feiniao_odds_catalog_for_kind($kind);
    $odds = array();
    foreach ($catalog as $f) {
        $key = isset($f['key']) ? (string) $f['key'] : '';
        if ($key === '' || !array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null) {
            continue;
        }
        $val = (float) $row[$key];
        if ($val <= 0) {
            continue;
        }
        $label = isset($f['label']) ? (string) $f['label'] : $key;
        $odds[] = array(
            'key' => $key,
            'label' => $label,
            'value' => $val,
        );
    }
    return $odds;
}

function getGameIdByCode($code=''){
    global $gmidAli;
    $typeNum=$gmidAli;
    $typeNum=array_flip($typeNum);
    if(empty($code)) return $typeNum;
    $key = strtolower(trim((string) $code));
    if (isset($typeNum[$key])) {
        return $typeNum[$key];
    }
    // 兼容 bridge code / 历史 alias（与极速赛车 xy28↔jssc_race 同一套）
    static $aliases = array(
        'jsft' => 3, 'cqssc' => 3,
        'jssc_race' => 4, 'xy28' => 4,
        'dz28' => 5, 'jnd28' => 5,
        'speed_ball' => 6, 'jsmt' => 6,
        'dzpk10' => 7, 'jssc' => 7,
        'aus_luck5' => 9, 'azxy5' => 9,
        'lhc' => 10, 'dzlhc' => 10,
    );
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    return isset($typeNum[$code]) ? $typeNum[$code] : '----';
}

/**
 * 业务日区间（对齐旧站）：当天 07:00 起，至次日 06:00 止。
 * 06:00 前仍算「昨天」业务日；06:00 后归零，07:00 起算新一天。
 * @return array [start, end, bizDate]
 */
function feiniao_business_day_range($now = null)
{
    $now = $now === null ? time() : (int)$now;
    $dayStart = '07:00:00';
    $dayEnd = '06:00:00';
    $his = (int)date('His', $now);
    $endHis = (int)str_replace(':', '', $dayEnd);
    if ($his < $endHis) {
        $start = date('Y-m-d ', $now - 86400) . $dayStart;
        $end = date('Y-m-d ', $now) . $dayEnd;
        $bizDate = date('Y-m-d', $now - 86400);
    } else {
        $start = date('Y-m-d ', $now) . $dayStart;
        $end = date('Y-m-d ', $now + 86400) . $dayEnd;
        $bizDate = date('Y-m-d', $now);
    }
    return array($start, $end, $bizDate);
}

/** 指定业务日（Y-m-d）对应的时间窗：day 07:00 ~ day+1 06:00 */
function feiniao_business_day_range_for_date($date)
{
    $day = date('Y-m-d', strtotime($date));
    $start = $day . ' 07:00:00';
    $end = date('Y-m-d', strtotime($day) + 86400) . ' 06:00:00';
    return array($start, $end, $day);
}

/** 全部订单表（含新彩种） */
function feiniao_order_tables()
{
    return array(
        'fn_order',
        'fn_pcorder',
        'fn_mtorder',
        'fn_jsscorder',
        'fn_jssscorder',
        'fn_sscorder',
        'fn_azxy5order',
        'fn_lhcorder',
    );
}

/** 每日用户统计表是否已部署；失败时保留旧订单汇总兜底。 */
function feiniao_daily_stats_ready()
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $q = db_query("SHOW TABLES LIKE 'fn_user_daily_stats'", true);
    $hasSummary = (bool)($q && db_fetch_array());
    $q2 = db_query("SHOW TABLES LIKE 'fn_user_daily_stat_items'", true);
    // 明细表存在后才允许切读，确保新订单一定能被应用层增量同步。
    $ready = $hasSummary && (bool)($q2 && db_fetch_array());
    return $ready;
}

function feiniao_daily_stats_total($roomid, $userid, $from = null, $to = null)
{
    if (!feiniao_daily_stats_ready()) return null;
    $where = "roomid = " . (int)$roomid . " and userid = '" . db_escape_string((string)$userid) . "'";
    if ($from !== null && $to !== null) {
        $where .= " and biz_date between '" . db_escape_string($from) . "' and '" . db_escape_string($to) . "'";
    }
    $row = get_query_vals('fn_user_daily_stats', 'sum(turnover) as turnover,sum(win_amount) as win_amount,sum(bet_count) as bet_count', $where);
    if (!$row) return array('liu' => 0.0, 'win' => 0.0, 'yk' => 0.0, 'count' => 0);
    $liu = (float)$row['turnover'];
    $win = (float)$row['win_amount'];
    return array('liu' => $liu, 'win' => $win, 'yk' => round($win - $liu, 2), 'count' => (int)$row['bet_count']);
}

/**
 * 将一笔订单的当前状态同步到每日统计。
 * 明细表保存上次贡献，更新时只写 delta，因此重复调用不会重复累计。
 * 调用方若在结算/下注事务内调用，统计更新与订单状态一起提交或回滚。
 */
function feiniao_daily_stats_reconcile_order($table, $orderId)
{
    if (!feiniao_daily_stats_ready()) return true;
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $orderId = (int)$orderId;
    if ($table === '' || $orderId <= 0) return false;
    $allowed = feiniao_order_tables();
    if (!in_array($table, $allowed, true)) return false;

    $row = get_query_vals($table, 'roomid,userid,addtime,money,status', array('id' => $orderId));
    $old = get_query_vals('fn_user_daily_stat_items', '*', array('order_table' => $table, 'order_id' => $orderId));
    $new = array('roomid' => 0, 'userid' => '', 'biz_date' => '', 'turnover' => 0.0, 'win_amount' => 0.0, 'bet_count' => 0);
    if ($row && (string)$row['userid'] !== '' && ((float)$row['status'] > 0 || (float)$row['status'] < 0)) {
        $at = strtotime((string)$row['addtime']);
        $new['roomid'] = (int)$row['roomid'];
        $new['userid'] = (string)$row['userid'];
        $new['biz_date'] = date('Y-m-d', $at - ((int)date('His', $at) < 70000 ? 86400 : 0));
        $new['turnover'] = (float)$row['money'];
        $new['win_amount'] = (float)$row['status'] > 0 ? (float)$row['status'] : 0.0;
        $new['bet_count'] = 1;
    }
    $oldVals = $old ? array(
        'roomid' => (int)$old['roomid'], 'userid' => (string)$old['userid'], 'biz_date' => (string)$old['biz_date'],
        'turnover' => (float)$old['turnover'], 'win_amount' => (float)$old['win_amount'], 'bet_count' => (int)$old['bet_count'],
    ) : array('roomid' => 0, 'userid' => '', 'biz_date' => '', 'turnover' => 0.0, 'win_amount' => 0.0, 'bet_count' => 0);

    if ($oldVals['bet_count'] > 0) {
        $dTurn = $new['turnover'] - $oldVals['turnover'];
        $dWin = $new['win_amount'] - $oldVals['win_amount'];
        $dCount = $new['bet_count'] - $oldVals['bet_count'];
        $where = "roomid={$oldVals['roomid']} and userid='" . db_escape_string($oldVals['userid']) . "' and biz_date='" . db_escape_string($oldVals['biz_date']) . "'";
        db_query("UPDATE `fn_user_daily_stats` SET turnover=turnover+({$dTurn}), win_amount=win_amount+({$dWin}), bet_count=bet_count+({$dCount}), updated_at=NOW() WHERE {$where}");
    }
    if ($new['bet_count'] > 0) {
        $uid = db_escape_string($new['userid']);
        db_query("INSERT INTO fn_user_daily_stats (roomid,userid,biz_date,turnover,win_amount,bet_count,updated_at) VALUES ({$new['roomid']},'{$uid}','{$new['biz_date']}',{$new['turnover']},{$new['win_amount']},{$new['bet_count']},NOW()) ON DUPLICATE KEY UPDATE turnover=turnover+VALUES(turnover),win_amount=win_amount+VALUES(win_amount),bet_count=bet_count+VALUES(bet_count),updated_at=NOW()");
        $uid = db_escape_string($new['userid']);
        db_query("INSERT INTO fn_user_daily_stat_items (order_table,order_id,roomid,userid,biz_date,turnover,win_amount,bet_count,updated_at) VALUES ('" . db_escape_string($table) . "',{$orderId},{$new['roomid']},'{$uid}','{$new['biz_date']}',{$new['turnover']},{$new['win_amount']},{$new['bet_count']},NOW()) ON DUPLICATE KEY UPDATE roomid=VALUES(roomid),userid=VALUES(userid),biz_date=VALUES(biz_date),turnover=VALUES(turnover),win_amount=VALUES(win_amount),bet_count=VALUES(bet_count),updated_at=NOW()");
    } elseif ($oldVals['bet_count'] > 0) {
        db_query("DELETE FROM fn_user_daily_stat_items WHERE order_table='" . db_escape_string($table) . "' AND order_id={$orderId}");
    }
    if ($new['userid'] !== '') {
        feiniao_user_stats_cache_forget($new['userid'], $new['roomid']);
    } elseif ($oldVals['userid'] !== '') {
        feiniao_user_stats_cache_forget($oldVals['userid'], $oldVals['roomid']);
    }
    return true;
}

/**
 * 汇总某房间/用户在时间窗内的流水与盈亏
 * @return array{liu:float,win:float,yk:float,count:int}
 */
function feiniao_sum_bets($roomid, $userid, $time0, $time1, $jiaOnlyFalse = false)
{
    $roomid = db_escape_string($roomid);
    $userid = $userid === null || $userid === '' ? null : db_escape_string($userid);
    $time0 = db_escape_string($time0);
    $time1 = db_escape_string($time1);
    $liu = 0.0;
    $win = 0.0;
    $cnt = 0;
    $jiaSql = $jiaOnlyFalse ? " and `jia` = 'false'" : '';
    // 业务日统计直接读增量表；非标准业务日或需要 jia 过滤时保留旧查询口径。
    if (!$jiaOnlyFalse && feiniao_daily_stats_ready()
        && substr((string)$time0, -8) === '07:00:00'
        && substr((string)$time1, -8) === '06:00:00') {
        $fromBiz = date('Y-m-d', strtotime($time0));
        $toBiz = date('Y-m-d', strtotime($time1) - 86400);
        $fast = feiniao_daily_stats_total($roomid, $userid, $fromBiz, $toBiz);
        if ($fast !== null) return $fast;
    }
    foreach (feiniao_order_tables() as $tb) {
        $userSql = $userid === null ? '' : " and userid = '{$userid}'";
        $base = "roomid = '{$roomid}'{$userSql} and (`addtime` between '{$time0}' and '{$time1}'){$jiaSql}";
        $liu += (float)get_query_val($tb, 'sum(`money`)', "{$base} and (status > 0 or status < 0)");
        $win += (float)get_query_val($tb, 'sum(`status`)', "{$base} and status > 0");
        $cnt += (int)get_query_val($tb, 'count(*)', "{$base} and (status > 0 or status < 0)");
    }
    return array('liu' => $liu, 'win' => $win, 'yk' => round($win - $liu, 2), 'count' => $cnt);
}

/** 按游戏汇总流水，供会员特殊回水按游戏比例计算。 */
function feiniao_sum_bets_by_game($roomid, $userid, $time0, $time1, $jiaOnlyFalse = false)
{
    $roomid = db_escape_string($roomid);
    $userid = db_escape_string($userid);
    $time0 = db_escape_string($time0);
    $time1 = db_escape_string($time1);
    $jiaSql = $jiaOnlyFalse ? " and `jia` = 'false'" : '';
    $out = array('pk10'=>0.0,'xyft'=>0.0,'jsft'=>0.0,'jssc_race'=>0.0,'jnd28'=>0.0,'jsmt'=>0.0,'jssc'=>0.0,'jsssc'=>0.0,'azxy5'=>0.0,'dzlhc'=>0.0,'cqssc'=>0.0);
    foreach (array(1=>'pk10',2=>'xyft',3=>'jsft',4=>'jssc_race',5=>'jnd28') as $type=>$code) {
        $base = "roomid='{$roomid}' and userid='{$userid}' and (`addtime` between '{$time0}' and '{$time1}'){$jiaSql} and (status > 0 or status < 0) and type={$type}";
        $out[$code] += (float)get_query_val('fn_order', 'sum(`money`)', $base);
    }
    foreach (array('fn_pcorder'=>'jnd28','fn_mtorder'=>'jsmt','fn_jsscorder'=>'jssc','fn_jssscorder'=>'jsssc','fn_sscorder'=>'cqssc','fn_azxy5order'=>'azxy5','fn_lhcorder'=>'dzlhc') as $table=>$code) {
        $base = "roomid='{$roomid}' and userid='{$userid}' and (`addtime` between '{$time0}' and '{$time1}'){$jiaSql} and (status > 0 or status < 0)";
        $out[$code] += (float)get_query_val($table, 'sum(`money`)', $base);
    }
    foreach ($out as $code=>$value) $out[$code] = round((float)$value, 2);
    return $out;
}

/** 用户今日盈亏/流水（业务日） */


/** 用户顶部积分缓存目录 */
function feiniao_user_stats_cache_dir()
{
    $dir = dirname(__FILE__) . '/runtime/user-stats';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function feiniao_user_stats_cache_path($userid, $roomid)
{
    $safeU = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $userid);
    $safeR = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $roomid);
    list(, , $biz) = feiniao_business_day_range();
    return feiniao_user_stats_cache_dir() . '/s_' . $safeR . '_' . $safeU . '_' . $biz . '.json';
}

/** 读取短缓存（默认 20 秒内有效；跨业务日文件名已隔离） */
function feiniao_user_stats_cache_get($userid, $roomid, $ttl = 20)
{
    $path = feiniao_user_stats_cache_path($userid, $roomid);
    if (!is_file($path)) {
        return null;
    }
    if ((time() - (int) @filemtime($path)) > (int) $ttl) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function feiniao_user_stats_cache_set($userid, $roomid, $data)
{
    if (!is_array($data)) {
        return false;
    }
    $path = feiniao_user_stats_cache_path($userid, $roomid);
    $keep = array(
        'user_money' => isset($data['user_money']) ? $data['user_money'] : '0.00',
        'user_earn' => isset($data['user_earn']) ? (string)(int)round((float)$data['user_earn']) : '0',
        'user_hs' => isset($data['user_hs']) ? $data['user_hs'] : '0.00',
        'user_ls' => isset($data['user_ls']) ? (string)(int)round((float)$data['user_ls']) : '0',
        'ts' => time(),
    );
    return @file_put_contents($path, json_encode($keep, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function feiniao_user_stats_cache_forget($userid, $roomid)
{
    $path = feiniao_user_stats_cache_path($userid, $roomid);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** 进房首屏统计：余额现查；盈亏/流水/回水优先短缓存，避免拖慢进房 */
function feiniao_user_stats_boot($userid, $roomid)
{
    $money = (int) get_query_val('fn_user', 'money', array('userid' => $userid, 'roomid' => $roomid));
    $out = array(
        'user_money' => number_format((float) $money, 2, '.', ''),
        'user_earn' => '0',
        'user_hs' => '0.00',
        'user_ls' => '0',
    );
    $cached = feiniao_user_stats_cache_get($userid, $roomid, 120);
    if (is_array($cached)) {
        if (isset($cached['user_earn'])) {
            $out['user_earn'] = $cached['user_earn'];
        }
        if (isset($cached['user_hs'])) {
            $out['user_hs'] = $cached['user_hs'];
        }
        if (isset($cached['user_ls'])) {
            $out['user_ls'] = $cached['user_ls'];
        }
    }
    return $out;
}


function feiniao_getinfo($userid)
{
    $roomid = isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0;
    // 顶部统计会在多个房间页面轮询；同一用户/房间/业务日短时间内复用结果，
    // 避免每次请求都扫描全部订单表。下注后的下一轮刷新最多延迟10秒。
    $cached = feiniao_user_stats_cache_get($userid, $roomid, 10);
    if (is_array($cached) && isset($cached['user_earn'], $cached['user_ls'])) {
        return array(
            'yk' => (float)$cached['user_earn'],
            'liu' => (int)$cached['user_ls'],
            'from' => '',
            'to' => '',
        );
    }
    list($time0, $time1) = feiniao_business_day_range();
    $stats = feiniao_sum_bets($roomid, $userid, $time0, $time1, false);
    $old = is_array($cached) ? $cached : array();
    feiniao_user_stats_cache_set($userid, $roomid, array(
        'user_money' => isset($old['user_money']) ? $old['user_money'] : '0.00',
        'user_earn' => (string)(int)round((float)$stats['yk']),
        'user_hs' => isset($old['user_hs']) ? $old['user_hs'] : '0.00',
        'user_ls' => (string)(int)round((float)$stats['liu']),
    ));
    return array('yk' => $stats['yk'], 'liu' => (int)$stats['liu'], 'from' => $time0, 'to' => $time1);
}

/** 确保 fn_user 有回水比例字段 — 热路径只检查，禁止 ALTER */
function feiniao_ensure_user_rebate_column()
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $marker = rtrim(sys_get_temp_dir(), '/') . '/feiniao_schema_user_rebate.ok';
    if (is_file($marker) && (time() - (int) @filemtime($marker)) < 3600) {
        $ok = true;
        return true;
    }
    $q = db_query("SHOW COLUMNS FROM `fn_user` LIKE 'rebate'", true);
    $row = $q ? db_fetch_array() : null;
    if ($row) {
        @file_put_contents($marker, '1');
        $ok = true;
        return true;
    }
    error_log('[feiniao] fn_user.rebate missing — run php Public/migrate_schema.php');
    $ok = false;
    return false;
}

/** 读取房主后台「回水设置」方案比例（个人比例为 0 时作默认） */
function feiniao_room_rebate_settings($roomid)
{
    $roomid = intval($roomid);
    static $colOk = null;
    if ($colOk === null) {
        $marker = rtrim(sys_get_temp_dir(), '/') . '/feiniao_schema_setting_rebate.ok';
        if (is_file($marker) && (time() - (int) @filemtime($marker)) < 3600) {
            $colOk = true;
        } else {
            $q = db_query("SHOW COLUMNS FROM `fn_setting` LIKE 'setting_rebate'", true);
            $has = $q ? db_fetch_array() : null;
            if ($has) {
                @file_put_contents($marker, '1');
                $colOk = true;
            } else {
                error_log('[feiniao] fn_setting.setting_rebate missing — run migrate_schema.php');
                $colOk = false;
            }
        }
    }
    // 1) MySQL（bridge 保存方案时会同步）
    $raw = $colOk ? get_query_val('fn_setting', 'setting_rebate', array('roomid' => $roomid)) : '';
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }
    // 2) H5 Public/rebate/{roomid}.json
    $candidates = array(
        dirname(__FILE__) . '/rebate/' . $roomid . '.json',
        dirname(dirname(__FILE__)) . '/Public/rebate/' . $roomid . '.json',
        dirname(dirname(__FILE__)) . '/h5/Public/rebate/' . $roomid . '.json',
        dirname(dirname(__FILE__)) . '/bridge/data/biz_' . $roomid . '_rebateSettings.json',
        '/www/wwwroot/feiniao9999/Public/rebate/' . $roomid . '.json',
        '/www/wwwroot/feiniao9999/h5/Public/rebate/' . $roomid . '.json',
        '/www/wwwroot/feiniao9999/bridge/data/biz_' . $roomid . '_rebateSettings.json',
    );
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }
    return array();
}

function feiniao_room_rebate_rate($roomid, $liu)
{
    $liu = (float)$liu;
    $json = feiniao_room_rebate_settings($roomid);
    if (!$json) {
        return 0.0;
    }
    $plans = array();
    if (isset($json['plans']) && is_array($json['plans'])) {
        $plans = $json['plans'];
    } elseif (isset($json['config']['plans']) && is_array($json['config']['plans'])) {
        $plans = $json['config']['plans'];
    }
    $matched = 0.0;
    foreach ($plans as $plan) {
        if (!is_array($plan) || empty($plan['enabled'])) {
            continue;
        }
        $from = isset($plan['from']) ? (float)$plan['from'] : 0;
        $to = isset($plan['to']) ? (float)$plan['to'] : 0;
        $percent = isset($plan['percent']) ? (float)$plan['percent'] : 0;
        if ($percent <= 0) {
            continue;
        }
        if ($liu >= $from && ($to <= 0 || $liu <= $to)) {
            if ($percent > $matched) {
                $matched = $percent;
            }
        }
    }
    return $matched;
}

function feiniao_room_rebate_auto($roomid)
{
    $json = feiniao_room_rebate_settings($roomid);
    return !empty($json['auto']);
}

/** 自助回水预览短缓存：只用于弹窗展示，领取时强制绕过。 */
function feiniao_huishui_preview_cache_path($userid, $roomid, $bizDate)
{
    $dir = dirname(__FILE__) . '/runtime/huishui';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $safeU = preg_replace('/[^0-9A-Za-z_-]/', '', (string)$userid);
    $safeR = preg_replace('/[^0-9A-Za-z_-]/', '', (string)$roomid);
    $safeD = preg_replace('/[^0-9-]/', '', (string)$bizDate);
    return $dir . '/p_' . $safeR . '_' . $safeU . '_' . $safeD . '.json';
}

/**
 * 自助回水预览
 * 算法：可返 = 今日流水 * 回水比例 / 100 − 今日已领
 * 回水积分 = 已回水（claimed）；可返积分 = 还可领（available）
 * 顶部「回水」与「回水积分」同为已回水金额
 */
function feiniao_huishui_info($userid, $roomid = null, $useCache = true)
{
    feiniao_ensure_user_rebate_column();
    $roomid = $roomid !== null ? $roomid : (isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0);
    $userid = (string)$userid;
    list($time0, $time1) = feiniao_business_day_range();
    $cachePath = feiniao_huishui_preview_cache_path($userid, $roomid, $time0);
    if ($useCache && is_file($cachePath) && (time() - (int)@filemtime($cachePath)) <= 2) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached) && isset($cached['available'])) {
            return $cached;
        }
    }
    $stats = feiniao_sum_bets($roomid, $userid, $time0, $time1, false);
    $liu = round((float)$stats['liu'], 2);
    $rate = (float)get_query_val('fn_user', 'rebate', array('userid' => $userid, 'roomid' => $roomid));
    $rateSource = 'user';
    if ($rate <= 0) {
        $rate = (float)feiniao_room_rebate_rate($roomid, $liu);
        $rateSource = 'room';
    }
    if ($rate < 0) {
        $rate = 0;
    }
    // 会员特殊回水：后台保存的按游戏比例优先于系统默认比例。
    $specialEnabled = false;
    $specialRate = 0.0;
    $specialByGame = array();
    $specialUser = get_query_vals('fn_user', 'special_rebate_enabled,special_rebate,special_rebate_games', array('userid'=>$userid, 'roomid'=>$roomid));
    if ($specialUser) {
        $specialEnabled = !empty($specialUser['special_rebate_enabled']);
        $specialRate = (float)$specialUser['special_rebate'];
        if (!empty($specialUser['special_rebate_games'])) {
            $parsedSpecial = json_decode($specialUser['special_rebate_games'], true);
            if (is_array($parsedSpecial)) $specialByGame = $parsedSpecial;
        }
    }

    // VIP≥60 回水加成（解锁「回水加成」特权后叠加）
    $vipBonusPct = 0.0;
    $vipLevel = 0;
    if (function_exists('feiniao_vip_effects')) {
        $fx = feiniao_vip_effects($userid, $roomid);
        $vipLevel = (int)$fx['vipLevel'];
        $vipBonusPct = (float)$fx['rebateBonusPct'];
        if ($vipBonusPct > 0) {
            $rate = round($rate + $vipBonusPct, 4);
            $rateSource = $rateSource . '+vip';
        }
    }
    if ($specialEnabled) {
        $gameLiu = feiniao_sum_bets_by_game($roomid, $userid, $time0, $time1, false);
        $totalDue = 0.0;
        foreach ($gameLiu as $gameCode=>$gameAmount) {
            $gameRate = array_key_exists($gameCode, $specialByGame) ? (float)$specialByGame[$gameCode] : $specialRate;
            if ($gameRate > 0) $totalDue += (float)$gameAmount * $gameRate / 100;
        }
        $totalDue = round($totalDue, 2);
        $rateSource = 'special-by-game';
    } else {
        $totalDue = round($liu * $rate / 100, 2);
    }
    $claimed = (float)get_query_val(
        'fn_marklog',
        'sum(`money`)',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and content like '%自助回水%' and (`addtime` between '" . db_escape_string($time0) . "' and '" . db_escape_string($time1) . "')"
    );
    if ($claimed < 0) {
        $claimed = 0;
    }
    $claimed = round($claimed, 2);
    $available = round(max(0, $totalDue - $claimed), 2);
    $result = array(
        'liu' => $liu,
        'rebatePoints' => $claimed,
        'rate' => $rate,
        'rateSource' => $rateSource,
        'vipLevel' => $vipLevel,
        'vipBonusPct' => $vipBonusPct,
        'totalDue' => $totalDue,
        'claimed' => $claimed,
        'available' => $available,
        'availablePoints' => $available,
        'from' => $time0,
        'to' => $time1,
    );
    @file_put_contents($cachePath, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $result;
}

/** 领取可返积分到余额（行锁 + 账本幂等，防并发双领） */
function feiniao_huishui_claim($userid, $roomid = null)
{
    feiniao_ensure_user_rebate_column();
    if (!function_exists('feiniao_ledger_credit')) {
        require_once dirname(__FILE__) . '/ledger.php';
    }
    $roomid = (int) ($roomid !== null ? $roomid : (isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0));
    $userid = (string) $userid;
    if ($roomid <= 0 || $userid === '') {
        return array('ok' => false, 'msg' => '参数错误', 'info' => array());
    }
    if (!feiniao_room_rebate_auto($roomid)) {
        return array('ok' => false, 'msg' => '自助回水未开启', 'info' => feiniao_huishui_info($userid, $roomid, false));
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return array('ok' => false, 'msg' => '系统繁忙(账本未就绪)', 'info' => feiniao_huishui_info($userid, $roomid));
    }
    try {
        feiniao_ledger_begin();
        $uidEsc = db_escape_string($userid);
        db_query("SELECT `id`,`money` FROM `fn_user` WHERE `userid`='{$uidEsc}' AND `roomid`={$roomid} LIMIT 1 FOR UPDATE");
        $user = get_query_vals('fn_user', 'id,money', array('userid' => $userid, 'roomid' => $roomid));
        if (!$user) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '用户不存在', 'info' => feiniao_huishui_info($userid, $roomid, false));
        }
        $info = feiniao_huishui_info($userid, $roomid, false);
        $amount = round((float) $info['available'], 2);
        if ($amount <= 0) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '暂无可领回水', 'info' => $info);
        }
        // 同一业务日、同一「已领水位+本次金额」只成功一次，防并发双领
        $payKey = 'HUISHUI:' . $roomid . ':' . preg_replace('/\W+/', '', $userid) . ':' . md5(
            (isset($info['from']) ? $info['from'] : '') . '|' .
            (isset($info['to']) ? $info['to'] : '') . '|' .
            sprintf('%.2f', (float) $info['claimed']) . '|' .
            sprintf('%.2f', $amount)
        );
        $r = feiniao_ledger_credit($userid, $roomid, $amount, $payKey, '自助回水', '上分', true);
        if ($r === false) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '回水入账失败', 'info' => feiniao_huishui_info($userid, $roomid, false));
        }
        if (!feiniao_ledger_commit()) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '回水提交失败', 'info' => feiniao_huishui_info($userid, $roomid, false));
        }
        $info = feiniao_huishui_info($userid, $roomid, false);
        $money = (float) get_query_val('fn_user', 'money', array('id' => $user['id']));
        return array(
            'ok' => true,
            'msg' => ($r === 'dup' ? '已领取' : '回水成功'),
            'amount' => $amount,
            'money' => $money,
            'info' => $info,
        );
    } catch (Throwable $e) {
        feiniao_ledger_rollback();
        error_log('[feiniao] huishui_claim: ' . $e->getMessage());
        return array('ok' => false, 'msg' => '回水失败', 'info' => feiniao_huishui_info($userid, $roomid, false));
    }
}

/** VIP0–80 默认方案（门槛=累计已处理上分） */
function feiniao_vip_max_level()
{
    return 80;
}

/** 晋级彩金领取有效天数（自达到该等级起算） */
function feiniao_vip_upgrade_expire_days()
{
    return 31;
}

/** VIP 等级对应图标文件名（素材按区间：0-9 / 10-19 / … / 80） */
function feiniao_vip_icon_file($level)
{
    $level = max(0, min(80, (int)$level));
    if ($level >= 80) {
        return '80.png';
    }
    $base = (int)(floor($level / 10) * 10);
    return $base . '-' . ($base + 9) . '.png';
}

function feiniao_vip_icon_url($level, $prefix = 'images/vip/')
{
    return rtrim($prefix, '/') . '/' . feiniao_vip_icon_file($level);
}

/** 房主 VIP 覆盖配置（彩金/周礼金/特权开关与解锁级） */
function feiniao_vip_room_settings($roomid = null)
{
    $roomid = $roomid !== null ? intval($roomid) : (isset($_SESSION['roomid']) ? intval($_SESSION['roomid']) : 0);
    static $colReady = null;
    if ($colReady === null) {
        $colReady = false;
        $q = db_query("SHOW COLUMNS FROM `fn_setting` LIKE 'setting_vip'", true);
        $has = $q ? db_fetch_array() : null;
        $colReady = !empty($has);
        // 热路径禁止 ALTER；缺列时按无覆盖配置处理
    }
    $out = array('levels' => array(), 'perks' => array());
    if ($roomid > 0 && $colReady) {
        $raw = get_query_val('fn_setting', 'setting_vip', array('roomid' => $roomid));
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                if (isset($json['levels']) && is_array($json['levels'])) {
                    $out['levels'] = $json['levels'];
                }
                if (isset($json['perks']) && is_array($json['perks'])) {
                    $out['perks'] = $json['perks'];
                }
            }
        }
    }
    return $out;
}

/** 底表特权目录（Excel 右侧解锁级；可由房主覆盖） */
function feiniao_vip_perk_catalog_base()
{
    return array(
        array('id' => 'badge', 'name' => '专属徽章', 'from' => 0, 'hint' => '聊天昵称旁显示 VIP 徽章'),
        array('id' => 'upgrade', 'name' => '彩金', 'from' => 10, 'hint' => '晋级奖励，每级限领一次，31天未领过期'),
        array('id' => 'priority', 'name' => '优先客服', 'from' => 30, 'hint' => '客服会话优先接待并标识'),
        array('id' => 'weekly', 'name' => '周礼金', 'from' => 40, 'hint' => '每周可在 VIP 页领取一次'),
        array('id' => 'frame', 'name' => '头像框', 'from' => 50, 'hint' => '聊天头像显示专属边框'),
        array('id' => 'namecolor', 'name' => '昵称高亮', 'from' => 50, 'hint' => '聊天室昵称金色高亮'),
        array('id' => 'entry', 'name' => '进房特效', 'from' => 60, 'hint' => '进入房间播放进场特效'),
        array('id' => 'anonymous', 'name' => '匿名投注', 'from' => 70, 'hint' => '聊天与确认卡隐藏真实昵称'),
        array('id' => 'gift', 'name' => '节日礼包', 'from' => 80, 'hint' => '房主开启节日活动后可领取'),
    );
}

/** VIP 特权目录（合并房主覆盖；disabled 的特权不出现） */
function feiniao_vip_perk_catalog($roomid = null)
{
    $roomid = $roomid !== null ? intval($roomid) : (isset($_SESSION['roomid']) ? intval($_SESSION['roomid']) : 0);
    $cfg = feiniao_vip_room_settings($roomid);
    $perkCfg = isset($cfg['perks']) && is_array($cfg['perks']) ? $cfg['perks'] : array();
    $out = array();
    foreach (feiniao_vip_perk_catalog_base() as $p) {
        $id = $p['id'];
        $ov = isset($perkCfg[$id]) && is_array($perkCfg[$id]) ? $perkCfg[$id] : array();
        if (array_key_exists('enabled', $ov) && !$ov['enabled']) {
            continue;
        }
        if (isset($ov['unlockLevel'])) {
            $p['from'] = max(0, min(80, (int)$ov['unlockLevel']));
        }
        $out[] = $p;
    }
    return $out;
}

/** 某 VIP 等级已解锁的特权 id 列表 */
function feiniao_vip_perks_for($level, $roomid = null)
{
    $level = (int)$level;
    $ids = array();
    foreach (feiniao_vip_perk_catalog($roomid) as $p) {
        if ($level >= (int)$p['from']) {
            $ids[] = $p['id'];
        }
    }
    return $ids;
}

function feiniao_vip_perk_unlock_level($perkId, $roomid = null)
{
    foreach (feiniao_vip_perk_catalog($roomid) as $p) {
        if ($p['id'] === $perkId) {
            return (int)$p['from'];
        }
    }
    return 999;
}

/** 已移除回水加成特权，恒为 0（兼容旧回水页字段） */
function feiniao_vip_rebate_bonus_pct($level)
{
    return 0.0;
}

function feiniao_vip_anon_label()
{
    return '匿名玩家';
}

/** 聊天/确认卡展示用昵称（订单仍用真实名） */
function feiniao_vip_chat_nickname($userid, $roomid, $realName)
{
    $fx = feiniao_vip_effects($userid, $roomid);
    if (!empty($fx['anonymous'])) {
        return feiniao_vip_anon_label();
    }
    return (string)$realName;
}

/**
 * 统一 VIP 房间效果（聊天/回水/客服复用）
 * 聊天热路径不写达级记录，避免轮询打爆连接。
 */
function feiniao_vip_effects($userid, $roomid = null)
{
    $roomid = $roomid !== null ? intval($roomid) : (isset($_SESSION['roomid']) ? intval($_SESSION['roomid']) : 0);
    $userid = (string)$userid;
    static $cache = array();
    $ck = $roomid . ':' . $userid;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $levels = feiniao_vip_levels($roomid);
    // 聊天/展示效果走 soft 缓存，避免轮询扫全订单表
    $totalTurnover = feiniao_vip_sum_turnover($userid, $roomid, true);
    $totalDeposit = feiniao_vip_sum_upmark($userid, $roomid);
    $vipLevel = feiniao_vip_level_from_metrics($totalTurnover, $totalDeposit, $levels);
    $row = feiniao_vip_find_level($vipLevel, $levels);
    $perks = feiniao_vip_perks_for($vipLevel, $roomid);
    $set = array_fill_keys($perks, true);
    $out = array(
        'vipLevel' => $vipLevel,
        'title' => isset($row['title']) ? (string)$row['title'] : ('VIP' . $vipLevel),
        'perks' => $perks,
        'hasBadge' => !empty($set['badge']),
        'priorityCs' => !empty($set['priority']),
        'nameColor' => !empty($set['namecolor']),
        'entryFx' => !empty($set['entry']),
        'frame' => !empty($set['frame']),
        'anonymous' => !empty($set['anonymous']),
        'rebateBonusPct' => 0.0,
        'festivalGift' => !empty($set['gift']),
        'badgeIcon' => feiniao_vip_icon_url($vipLevel, '/Templates/user/images/vip/'),
        'frameClass' => !empty($set['frame']) ? ('vip-frame vip-frame-' . min(8, (int)floor($vipLevel / 10))) : '',
        'totalTurnover' => $totalTurnover,
    );
    $cache[$ck] = $out;
    return $out;
}

/** 聊天消息附加 VIP 字段（同请求内按 userid 缓存） */
function feiniao_chat_vip_payload($userid, $roomid, &$cache = null)
{
    $userid = (string)$userid;
    $empty = array(
        'userid' => $userid,
        'vipLevel' => 0,
        'vipTitle' => '',
        'vipBadge' => false,
        'vipNameColor' => false,
        'vipFrame' => false,
        'vipAnonymous' => false,
        'vipBadgeIcon' => '',
        'vipFrameClass' => '',
        'vipPerks' => array(),
    );
    if ($userid === '' || $userid === 'system' || $userid === 'robot') {
        return $empty;
    }
    if (is_array($cache) && isset($cache[$userid])) {
        return $cache[$userid];
    }
    $fx = feiniao_vip_effects($userid, $roomid);
    $payload = array(
        'userid' => $userid,
        'vipLevel' => (int)$fx['vipLevel'],
        'vipTitle' => (string)$fx['title'],
        'vipBadge' => !empty($fx['hasBadge']),
        'vipNameColor' => !empty($fx['nameColor']),
        'vipFrame' => !empty($fx['frame']),
        'vipAnonymous' => !empty($fx['anonymous']),
        'vipBadgeIcon' => (string)$fx['badgeIcon'],
        'vipFrameClass' => (string)$fx['frameClass'],
        'vipPerks' => $fx['perks'],
    );
    if (is_array($cache)) {
        $cache[$userid] = $payload;
    }
    return $payload;
}


/** 进房首屏聊天：不做 VIP 查询，截断超长系统卡，避免整页 JSON 过大导致长时间白屏 */
function feiniao_chat_row_boot_fast($x, $chatType, $game)
{
    $nickname = isset($x['username']) ? (string) $x['username'] : '';
    $content = feiniao_strip_live_tip(isset($x['content']) ? $x['content'] : '');
    $chatTypeStr = (string) $chatType;
    $isSystemHtml = in_array($chatTypeStr, array('S1', 'S2', 'S3'), true)
        || (is_string($content) && (strpos($content, 'bet-card') !== false || stripos($content, '<img') !== false));
    if (!$isSystemHtml) {
        $content = htmlspecialchars((string) $content, ENT_QUOTES, 'UTF-8');
        $nickname = htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8');
    } elseif (is_string($content) && strlen($content) > 12000
        && strpos($content, 'fn-seal-card') === false
        && strpos($content, 'bet-card') === false) {
        // 仅截断非注单系统卡；结算/注单卡必须首屏保留。
        $content = '<div class="bet-card">[系统消息过长]</div>';
    }
    $headimg = isset($x['headimg']) ? (string) $x['headimg'] : '';
    if ($headimg !== '' && !preg_match('#^(https?:)?/|^data:image/#i', $headimg)) {
        $headimg = htmlspecialchars($headimg, ENT_QUOTES, 'UTF-8');
    }
    return array(
        'nickname' => $nickname,
        'headimg' => $headimg,
        'content' => $content,
        'addtime' => isset($x['addtime']) ? $x['addtime'] : '',
        'type' => $chatType,
        'game' => $game,
        'id' => isset($x['id']) ? $x['id'] : 0,
        'userid' => isset($x['userid']) ? $x['userid'] : '',
        'vipLevel' => 0,
        'vipAnonymous' => false,
    );
}

function feiniao_chat_row_with_vip($x, $chatType, $game, $roomid, &$cache = null)
{
    $vip = feiniao_chat_vip_payload(isset($x['userid']) ? $x['userid'] : '', $roomid, $cache);
    $nickname = isset($x['username']) ? $x['username'] : '';
    // 匿名投注：对他人隐藏真实昵称；本人气泡仍可用 userid 对齐
    if (!empty($vip['vipAnonymous'])) {
        $nickname = feiniao_vip_anon_label();
    }
    $content = feiniao_strip_live_tip(isset($x['content']) ? $x['content'] : '');
    // S-01：用户聊天文本入库/回传前不带标签；系统卡保留 HTML
    $chatTypeStr = (string) $chatType;
    $isSystemHtml = in_array($chatTypeStr, array('S1', 'S2', 'S3'), true)
        || (is_string($content) && (strpos($content, 'bet-card') !== false || stripos($content, '<img') !== false));
    if (!$isSystemHtml) {
        $content = htmlspecialchars((string) $content, ENT_QUOTES, 'UTF-8');
        $nickname = htmlspecialchars((string) $nickname, ENT_QUOTES, 'UTF-8');
    }
    $headimg = isset($x['headimg']) ? (string) $x['headimg'] : '';
    if ($headimg !== '' && !preg_match('#^(https?:)?/|^data:image/#i', $headimg)) {
        $headimg = htmlspecialchars($headimg, ENT_QUOTES, 'UTF-8');
    }
    return array_merge(array(
        'nickname' => $nickname,
        'headimg' => $headimg,
        'content' => $content,
        'addtime' => isset($x['addtime']) ? $x['addtime'] : '',
        'type' => $chatType,
        'game' => $game,
        'id' => $x['id'],
        'userid' => isset($x['userid']) ? $x['userid'] : '',
    ), $vip);
}

/** Excel 底表 + 房主覆盖后的等级列表 */
function feiniao_vip_levels($roomid = null)
{
    $roomid = $roomid !== null ? intval($roomid) : (isset($_SESSION['roomid']) ? intval($_SESSION['roomid']) : 0);
    $dataFile = dirname(__FILE__) . '/vip_levels.php';
    if (is_file($dataFile)) {
        include_once $dataFile;
    }
    $raw = function_exists('feiniao_vip_levels_raw') ? feiniao_vip_levels_raw() : array();
    $cfg = feiniao_vip_room_settings($roomid);
    $levelCfg = isset($cfg['levels']) && is_array($cfg['levels']) ? $cfg['levels'] : array();
    $perkCatalog = feiniao_vip_perk_catalog($roomid);
    $out = array();
    foreach ($raw as $r) {
        $lv = (int)$r['level'];
        $key = (string)$lv;
        $ov = isset($levelCfg[$key]) && is_array($levelCfg[$key])
            ? $levelCfg[$key]
            : (isset($levelCfg[$lv]) && is_array($levelCfg[$lv]) ? $levelCfg[$lv] : array());
        $upgradeBonus = isset($ov['upgradeBonus']) ? (float)$ov['upgradeBonus'] : (float)$r['upgradeBonus'];
        $weeklyGift = isset($ov['weeklyGift']) ? (float)$ov['weeklyGift'] : (float)$r['weeklyGift'];
        $deposit = isset($r['deposit']) ? (float)$r['deposit'] : (isset($r['threshold']) ? (float)$r['threshold'] : 0);
        $turnover = isset($r['turnover']) ? (float)$r['turnover'] : (float)$r['threshold'];
        $th = $turnover;
        $perks = array();
        foreach ($perkCatalog as $p) {
            $perks[] = array(
                'id' => $p['id'],
                'name' => $p['name'],
                'unlocked' => $lv >= (int)$p['from'],
                'from' => (int)$p['from'],
                'hint' => isset($p['hint']) ? $p['hint'] : '',
            );
        }
        $out[] = array(
            'level' => $lv,
            'title' => (string)$r['title'],
            'deposit' => $deposit,
            'turnover' => $turnover,
            'threshold' => $th,
            'upgradeBonus' => $upgradeBonus,
            'weeklyGift' => $weeklyGift,
            'birthdayGift' => 0,
            'perks' => $perks,
            'desc' => '达到对应流水和充值积分门槛解锁。晋级彩金每级限领一次且 31 天未领过期；周礼金每周可领一次。VIP 按房间隔离。',
        );
    }
    return $out;
}

/** 兼容旧调用名 */
function feiniao_vip_default_levels()
{
    return feiniao_vip_levels(isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0);
}

/** VIP 相关表 — 热路径只检查，禁止 CREATE/ALTER */
function feiniao_ensure_vip_tables()
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $marker = rtrim(sys_get_temp_dir(), '/') . '/feiniao_schema_vip_tables.ok';
    if (is_file($marker) && (time() - (int) @filemtime($marker)) < 3600) {
        $ok = true;
        return true;
    }
    $need = array('fn_vip_claim', 'fn_vip_festival', 'fn_vip_reach');
    foreach ($need as $tbl) {
        $q = db_query("SHOW TABLES LIKE '" . db_escape_string($tbl) . "'", true);
        $has = $q ? db_fetch_array() : null;
        if (!$has) {
            error_log('[feiniao] missing table ' . $tbl . ' — run php Public/migrate_schema.php');
            $ok = false;
            return false;
        }
    }
    @file_put_contents($marker, '1');
    $ok = true;
    return true;
}

/** 懒记录用户达到各等级的时间（用于晋级奖 31 天过期） */
function feiniao_vip_mark_reach($userid, $roomid, $vipLevel)
{
    feiniao_ensure_vip_tables();
    $userid = (string)$userid;
    $roomid = intval($roomid);
    $vipLevel = max(0, min(feiniao_vip_max_level(), (int)$vipLevel));
    if ($userid === '' || $userid === 'robot' || $userid === 'system' || $vipLevel < 1) {
        return;
    }
    static $marked = array();
    $ck = $roomid . ':' . $userid . ':' . $vipLevel;
    if (isset($marked[$ck])) {
        return;
    }
    $marked[$ck] = true;
    // 仅补当前级记录；历史级用同一次到达时间近似（首次观察到该级时写入）
    $exists = get_query_val(
        'fn_vip_reach',
        'id',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and vip_level = '" . db_escape_string($vipLevel) . "'"
    );
    if ($exists) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    for ($lv = 1; $lv <= $vipLevel; $lv++) {
        $eid = get_query_val(
            'fn_vip_reach',
            'id',
            "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and vip_level = '" . db_escape_string($lv) . "'"
        );
        if ($eid) {
            continue;
        }
        $nid = 0;
        insert_query('fn_vip_reach', array(
            'roomid' => $roomid,
            'userid' => $userid,
            'vip_level' => $lv,
            'reached_at' => $now,
        ), $nid);
    }
}

function feiniao_vip_reach_at($userid, $roomid, $level)
{
    feiniao_ensure_vip_tables();
    $level = (int)$level;
    if ($level <= 0) {
        return null;
    }
    $at = get_query_val(
        'fn_vip_reach',
        'reached_at',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and vip_level = '" . db_escape_string($level) . "'"
    );
    return $at ? (string)$at : null;
}

function feiniao_vip_upgrade_expired($userid, $roomid, $level)
{
    $at = feiniao_vip_reach_at($userid, $roomid, $level);
    if (!$at) {
        return false;
    }
    $ts = strtotime($at);
    if (!$ts) {
        return false;
    }
    return (time() - $ts) > (feiniao_vip_upgrade_expire_days() * 86400);
}

/** 房间进行中的节日活动（status=1 且时间窗口内） */
function feiniao_vip_festival_active($roomid, $now = null)
{
    feiniao_ensure_vip_tables();
    $roomid = intval($roomid);
    $now = $now ? (string)$now : date('Y-m-d H:i:s');
    $rows = array();
    select_query(
        'fn_vip_festival',
        '*',
        "roomid = '" . db_escape_string($roomid) . "' and status = 1 and start_at <= '" . db_escape_string($now) . "' and end_at >= '" . db_escape_string($now) . "' order by id desc"
    );
    while ($row = db_fetch_array()) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * 会员可见的节日活动（含领取状态）
 */
function feiniao_vip_festivals_for_user($userid, $roomid, $vipLevel = null)
{
    feiniao_ensure_vip_tables();
    $userid = (string)$userid;
    $roomid = intval($roomid);
    if ($vipLevel === null) {
        $vipLevel = (int)feiniao_vip_effects($userid, $roomid)['vipLevel'];
    } else {
        $vipLevel = (int)$vipLevel;
    }
    $needVip = feiniao_vip_perk_unlock_level('gift', $roomid);
    $unlocked = $vipLevel >= $needVip && $needVip < 999;
    $active = feiniao_vip_festival_active($roomid);
    $out = array();
    foreach ($active as $row) {
        $fid = (int)$row['id'];
        $periodKey = 'festival_' . $fid;
        $claimed = (bool)get_query_val(
            'fn_vip_claim',
            'id',
            "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and claim_type = 'festival' and period_key = '" . db_escape_string($periodKey) . "'"
        );
        $money = (float)$row['money'];
        $out[] = array(
            'id' => $fid,
            'title' => (string)$row['title'],
            'money' => $money,
            'startAt' => (string)$row['start_at'],
            'endAt' => (string)$row['end_at'],
            'claimed' => $claimed,
            'canClaim' => $unlocked && !$claimed && $money > 0,
            'needVip' => $needVip,
        );
    }
    return $out;
}

function feiniao_vip_week_key($now = null)
{
    $now = $now === null ? time() : (int)$now;
    list($time0) = feiniao_business_day_range($now);
    $ts = strtotime($time0);
    return date('o', $ts) . 'W' . date('W', $ts);
}

/** 累计充值/礼金积分，VIP 等级和报表使用同一口径 */
function feiniao_vip_sum_upmark($userid, $roomid)
{
    $userid = (string)$userid;
    $roomid = intval($roomid);
    $sumUp = (float)get_query_val(
        'fn_upmark',
        'sum(`money`)',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and type = '上分' and status in ('已处理','同意') and (jia is null or jia = '' or jia = 'false' or jia = '0')"
    );
    $sumLog = (float)get_query_val(
        'fn_marklog',
        'sum(`money`)',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and type = '上分' and content not like '%VIP%' and content not like '%自助回水%' and content not like '%周礼%' and content not like '%节日%' and content not like '%彩金%'"
    );
    // VIP 礼金由 fn_vip_claim 记录，单独加回，避免被普通充值过滤条件排除或重复计算。
    $sumVip = (float)get_query_val(
        'fn_vip_claim',
        'sum(`money`)',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "'"
    );
    return round(max(0, max($sumUp, $sumLog)) + max(0, $sumVip), 2);
}

/**
 * 有效投注流水（已结算，排除 robot/system），用于定级。
 * 与会员端展示的流水保持同一口径；后台的 jia 标记只控制假人列表，不能让
 * 已登录会员的流水在 VIP 页面变成 0。
 * $soft=true（聊天热路径）：优先读缓存；过期仍可复用陈旧值，避免轮询打爆连接。
 */
function feiniao_vip_sum_turnover($userid, $roomid, $soft = false)
{
    $userid = (string)$userid;
    $roomid = intval($roomid);
    if ($userid === '' || $userid === 'robot' || $userid === 'system') {
        return 0.0;
    }
    static $reqCache = array();
    $ck = $roomid . ':' . $userid;
    if (isset($reqCache[$ck])) {
        return $reqCache[$ck];
    }

    $dailyTotal = feiniao_daily_stats_total($roomid, $userid);
    if ($dailyTotal !== null) {
        $reqCache[$ck] = round(max(0, (float)$dailyTotal['liu']), 2);
        return $reqCache[$ck];
    }

    $cacheDir = sys_get_temp_dir() . '/fn_vip_liu';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/' . md5($ck) . '.json';
    $freshTtl = 600;   // 10 分钟新鲜
    $staleTtl = 7200;  // 2 小时内聊天可复用陈旧值
    $staleLiu = null;
    if (is_file($cacheFile)) {
        $age = time() - (int)@filemtime($cacheFile);
        $raw = @file_get_contents($cacheFile);
        $j = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($j) && isset($j['liu'])) {
            $staleLiu = round(max(0, (float)$j['liu']), 2);
            // 页面首屏允许复用极短缓存，避免 VIP/个人中心同时扫全部订单表；
            // 5 秒内仍保持同一等级口径，过期后自动重新统计。
            if ($age >= 0 && $age < 60) {
                $reqCache[$ck] = $staleLiu;
                return $staleLiu;
            }
            if ($soft && $age >= 0 && $age < $staleTtl) {
                $reqCache[$ck] = $staleLiu;
                return $staleLiu;
            }
        }
    }

    $uidEsc = db_escape_string($userid);
    $roomEsc = db_escape_string($roomid);
    $parts = array();
    foreach (feiniao_order_tables() as $tb) {
        $parts[] = "SELECT COALESCE(SUM(`money`),0) AS s FROM `{$tb}` WHERE roomid = '{$roomEsc}' AND userid = '{$uidEsc}' AND (status > 0 OR status < 0)";
    }
    $liu = 0.0;
    $ok = false;
    if ($parts) {
        $sql = 'SELECT COALESCE(SUM(s),0) AS total FROM (' . implode(' UNION ALL ', $parts) . ') _fn_vip_liu';
        $q = db_query($sql, true);
        if ($q) {
            $row = db_fetch_array();
            if ($row && isset($row['total'])) {
                $liu = (float)$row['total'];
                $ok = true;
            }
        }
    }
    if (!$ok && $staleLiu !== null) {
        $reqCache[$ck] = $staleLiu;
        return $staleLiu;
    }
    $liu = round(max(0, $liu), 2);
    @file_put_contents($cacheFile, json_encode(array('liu' => $liu, 'ts' => time())), LOCK_EX);
    $reqCache[$ck] = $liu;
    return $liu;
}

function feiniao_vip_level_from_total($total, $levels = null)
{
    $levels = $levels !== null ? $levels : feiniao_vip_levels();
    $cur = 0;
    foreach ($levels as $row) {
        if ($total + 1e-9 >= (float)$row['threshold']) {
            $cur = (int)$row['level'];
        }
    }
    return $cur;
}

/** 按流水 + 充值积分共同计算等级，两项均达到门槛才算达级。 */
function feiniao_vip_level_from_metrics($totalTurnover, $totalDeposit, $levels = null)
{
    $levels = $levels !== null ? $levels : feiniao_vip_levels();
    $totalTurnover = max(0, (float)$totalTurnover);
    $totalDeposit = max(0, (float)$totalDeposit);
    $cur = 0;
    foreach ($levels as $row) {
        $turnoverOk = $totalTurnover + 1e-9 >= (float)$row['threshold'];
        $depositNeed = isset($row['deposit']) ? (float)$row['deposit'] : 0.0;
        if ($turnoverOk && $totalDeposit + 1e-9 >= $depositNeed) {
            $cur = (int)$row['level'];
        }
    }
    return $cur;
}

function feiniao_vip_find_level($level, $levels = null)
{
    $levels = $levels !== null ? $levels : feiniao_vip_levels();
    $level = (int)$level;
    foreach ($levels as $row) {
        if ((int)$row['level'] === $level) {
            return $row;
        }
    }
    return $levels[0];
}

function feiniao_vip_claimed_map($userid, $roomid)
{
    feiniao_ensure_vip_tables();
    $map = array('upgrade' => array(), 'weekly' => array());
    select_query(
        'fn_vip_claim',
        '*',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "'"
    );
    while ($row = db_fetch_array()) {
        $type = (string)$row['claim_type'];
        $key = (string)$row['period_key'];
        if ($type === 'upgrade') {
            $map['upgrade'][$key] = true;
        } elseif ($type === 'weekly') {
            $map['weekly'][$key] = true;
        }
    }
    return $map;
}

function feiniao_vip_info($userid, $roomid = null, $viewLevel = null)
{
    feiniao_ensure_vip_tables();
    $roomid = $roomid !== null ? $roomid : (isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0);
    $userid = (string)$userid;
    $levels = feiniao_vip_levels($roomid);
    $totalTurnover = feiniao_vip_sum_turnover($userid, $roomid);
    $totalUp = feiniao_vip_sum_upmark($userid, $roomid);
    $vipLevel = feiniao_vip_level_from_metrics($totalTurnover, $totalUp, $levels);
    feiniao_vip_mark_reach($userid, $roomid, $vipLevel);
    $curRow = feiniao_vip_find_level($vipLevel, $levels);
    $nextRow = null;
    foreach ($levels as $row) {
        if ((int)$row['level'] === $vipLevel + 1) {
            $nextRow = $row;
            break;
        }
    }
    $need = 0.0;
    $progress = 1.0;
    if ($nextRow) {
        $need = max(0, round((float)$nextRow['threshold'] - $totalTurnover, 2));
        $span = max(0.01, (float)$nextRow['threshold'] - (float)$curRow['threshold']);
        $progress = max(0, min(1, ($totalTurnover - (float)$curRow['threshold']) / $span));
    }
    if ($viewLevel === null || $viewLevel === '') {
        $viewLevel = $vipLevel;
    }
    $viewLevel = max(0, min(feiniao_vip_max_level(), (int)$viewLevel));
    $view = feiniao_vip_find_level($viewLevel, $levels);
    $claimed = feiniao_vip_claimed_map($userid, $roomid);
    $weekKey = feiniao_vip_week_key();
    $upgradeKey = (string)$viewLevel;
    $unlocked = $vipLevel >= $viewLevel;
    $upgradeBonus = (float)$view['upgradeBonus'];
    $weeklyGift = (float)$view['weeklyGift'];
    $upgradePerkFrom = feiniao_vip_perk_unlock_level('upgrade', $roomid);
    $weeklyPerkFrom = feiniao_vip_perk_unlock_level('weekly', $roomid);
    $upgradeExpired = $unlocked && $viewLevel > 0 && feiniao_vip_upgrade_expired($userid, $roomid, $viewLevel);
    $canUpgrade = $unlocked
        && $viewLevel >= $upgradePerkFrom
        && $upgradeBonus > 0
        && empty($claimed['upgrade'][$upgradeKey])
        && !$upgradeExpired;
    $canWeekly = $unlocked
        && $viewLevel === $vipLevel
        && $vipLevel >= $weeklyPerkFrom
        && $weeklyGift > 0
        && empty($claimed['weekly'][$weekKey]);
    $money = (float)get_query_val('fn_user', 'money', array('userid' => $userid, 'roomid' => $roomid));
    $festivals = feiniao_vip_festivals_for_user($userid, $roomid, $vipLevel);
    $effects = feiniao_vip_effects($userid, $roomid);
    $reachAt = feiniao_vip_reach_at($userid, $roomid, $viewLevel);
    $expireAt = null;
    if ($reachAt) {
        $expireAt = date('Y-m-d H:i:s', strtotime($reachAt) + feiniao_vip_upgrade_expire_days() * 86400);
    }

    $list = array();
    foreach ($levels as $row) {
        $list[] = array(
            'level' => (int)$row['level'],
            'title' => $row['title'],
            'deposit' => isset($row['deposit']) ? (float)$row['deposit'] : 0,
            'turnover' => isset($row['turnover']) ? (float)$row['turnover'] : (float)$row['threshold'],
            'threshold' => (float)$row['threshold'],
            'upgradeBonus' => (float)$row['upgradeBonus'],
            'weeklyGift' => (float)$row['weeklyGift'],
            'birthdayGift' => 0,
            'reached' => $vipLevel >= (int)$row['level'],
        );
    }

    return array(
        'vipLevel' => $vipLevel,
        'viewLevel' => $viewLevel,
        'title' => $curRow['title'],
        'viewTitle' => $view['title'],
        'totalUp' => $totalTurnover,
        'totalTurnover' => $totalTurnover,
        'totalDeposit' => $totalUp,
        'need' => $need,
        'progress' => round($progress, 4),
        'nextLevel' => $nextRow ? (int)$nextRow['level'] : null,
        'nextThreshold' => $nextRow ? (float)$nextRow['threshold'] : null,
        'money' => $money,
        'weekKey' => $weekKey,
        'levels' => $list,
        'effects' => $effects,
        'festivals' => $festivals,
        'view' => array(
            'level' => (int)$view['level'],
            'title' => $view['title'],
            'deposit' => isset($view['deposit']) ? (float)$view['deposit'] : 0,
            'turnover' => isset($view['turnover']) ? (float)$view['turnover'] : (float)$view['threshold'],
            'threshold' => (float)$view['threshold'],
            'upgradeBonus' => $upgradeBonus,
            'weeklyGift' => $weeklyGift,
            'birthdayGift' => 0,
            'desc' => $view['desc'],
            'perks' => $view['perks'],
            'unlocked' => $unlocked,
            'upgradeClaimed' => !empty($claimed['upgrade'][$upgradeKey]),
            'upgradeExpired' => $upgradeExpired,
            'upgradeExpireAt' => $expireAt,
            'weeklyClaimed' => !empty($claimed['weekly'][$weekKey]),
            'canUpgrade' => $canUpgrade,
            'canWeekly' => $canWeekly,
        ),
    );
}

/** VIP 领取：claim 占位 + 账本加款必须同一事务 */
function feiniao_vip_claim_atomic($userid, $roomid, $amount, $claimType, $periodKey, $content, $vipLevel)
{
    if (!function_exists('feiniao_ledger_credit')) {
        require_once dirname(__FILE__) . '/ledger.php';
    }
    if (!feiniao_ensure_vip_tables()) {
        return array('ok' => false, 'msg' => '请先执行 migrate_schema.php');
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return array('ok' => false, 'msg' => '系统繁忙(账本未就绪)');
    }
    $amount = round((float) $amount, 2);
    $roomid = (int) $roomid;
    $userid = (string) $userid;
    if ($amount <= 0 || $userid === '' || $roomid <= 0) {
        return array('ok' => false, 'msg' => '领取失败');
    }
    $payKey = 'VIP:' . preg_replace('/\W+/', '', $claimType) . ':' . $roomid . ':' .
        preg_replace('/\W+/', '', $userid) . ':' . preg_replace('/\W+/', '', (string) $periodKey);
    try {
        feiniao_ledger_begin();
        $uidEsc = db_escape_string($userid);
        db_query("SELECT `id` FROM `fn_user` WHERE `userid`='{$uidEsc}' AND `roomid`={$roomid} LIMIT 1 FOR UPDATE");
        $exists = get_query_val(
            'fn_vip_claim',
            'id',
            "roomid = {$roomid} and userid = '{$uidEsc}' and claim_type = '" . db_escape_string($claimType) . "' and period_key = '" . db_escape_string($periodKey) . "'"
        );
        if ($exists) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '已领取过');
        }
        $claimId = 0;
        $ins = insert_query('fn_vip_claim', array(
            'roomid' => $roomid,
            'userid' => $userid,
            'claim_type' => $claimType,
            'vip_level' => (int) $vipLevel,
            'money' => $amount,
            'period_key' => $periodKey,
            'addtime' => 'now()',
        ), $claimId);
        if ($ins === false || (int) $claimId <= 0) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '领取失败，请重试');
        }
        $r = feiniao_ledger_credit($userid, $roomid, $amount, $payKey, $content, '上分', true);
        if ($r === false) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '入账失败');
        }
        if (!feiniao_ledger_commit()) {
            feiniao_ledger_rollback();
            return array('ok' => false, 'msg' => '提交失败');
        }
        return array('ok' => true, 'dup' => ($r === 'dup'), 'claimId' => (int) $claimId);
    } catch (Throwable $e) {
        feiniao_ledger_rollback();
        error_log('[feiniao] vip_claim_atomic: ' . $e->getMessage());
        return array('ok' => false, 'msg' => '入账失败');
    }
}

/** @deprecated 保留兼容，转发到原子领取 */
function feiniao_vip_credit_after_claim($userid, $roomid, $amount, $claimType, $periodKey, $content, $claimId)
{
    // 旧路径已拆事务；若仍被调用则仅补账（claim 已存在）
    if (!function_exists('feiniao_ledger_credit')) {
        require_once dirname(__FILE__) . '/ledger.php';
    }
    if (!feiniao_ledger_pay_key_ready()) {
        return array('ok' => false, 'msg' => '系统繁忙(账本未就绪)');
    }
    $payKey = 'VIP:' . preg_replace('/\W+/', '', $claimType) . ':' . (int) $roomid . ':' .
        preg_replace('/\W+/', '', (string) $userid) . ':' . preg_replace('/\W+/', '', (string) $periodKey);
    $r = feiniao_ledger_credit($userid, (int) $roomid, (float) $amount, $payKey, $content, '上分');
    if ($r === false) {
        return array('ok' => false, 'msg' => '入账失败');
    }
    return array('ok' => true, 'dup' => ($r === 'dup'));
}

function feiniao_vip_claim($userid, $roomid, $claimType, $viewLevel, $festivalId = 0)
{
    feiniao_ensure_vip_tables();
    $userid = (string)$userid;
    $roomid = intval($roomid);
    $claimType = (string)$claimType;
    $viewLevel = max(0, min(feiniao_vip_max_level(), (int)$viewLevel));
    $festivalId = intval($festivalId);

    if ($claimType === 'festival') {
        $info = feiniao_vip_info($userid, $roomid, $viewLevel);
        $needVip = feiniao_vip_perk_unlock_level('gift', $roomid);
        if ((int)$info['vipLevel'] < $needVip) {
            return array('ok' => false, 'msg' => '需 VIP' . $needVip . ' 及以上才能领取节日礼包', 'info' => $info);
        }
        $target = null;
        foreach ($info['festivals'] as $f) {
            if ((int)$f['id'] === $festivalId) {
                $target = $f;
                break;
            }
        }
        if (!$target) {
            return array('ok' => false, 'msg' => '活动不存在或未开始', 'info' => $info);
        }
        if (!empty($target['claimed'])) {
            return array('ok' => false, 'msg' => '该节日礼包已领取', 'info' => $info);
        }
        if (empty($target['canClaim'])) {
            return array('ok' => false, 'msg' => '暂不可领取', 'info' => $info);
        }
        $amount = (float)$target['money'];
        $periodKey = 'festival_' . $festivalId;
        $content = 'VIP节日礼包:' . $target['title'];
        $user = get_query_vals('fn_user', '*', array('userid' => $userid, 'roomid' => $roomid));
        if (!$user) {
            return array('ok' => false, 'msg' => '用户不存在', 'info' => $info);
        }
        $exists = get_query_val(
            'fn_vip_claim',
            'id',
            "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and claim_type = 'festival' and period_key = '" . db_escape_string($periodKey) . "'"
        );
        if ($exists) {
            return array('ok' => false, 'msg' => '已领取过', 'info' => feiniao_vip_info($userid, $roomid, $viewLevel));
        }
        $cr = feiniao_vip_claim_atomic($userid, $roomid, $amount, 'festival', $periodKey, $content, (int)$info['vipLevel']);
        if (empty($cr['ok'])) {
            return array('ok' => false, 'msg' => isset($cr['msg']) ? $cr['msg'] : '领取失败', 'info' => feiniao_vip_info($userid, $roomid, $viewLevel));
        }
        $info = feiniao_vip_info($userid, $roomid, $viewLevel);
        $money = (float)get_query_val('fn_user', 'money', array('id' => $user['id']));
        return array('ok' => true, 'msg' => '领取成功 +' . number_format($amount, 2, '.', ''), 'amount' => $amount, 'money' => $money, 'info' => $info);
    }

    if ($claimType !== 'upgrade' && $claimType !== 'weekly') {
        return array('ok' => false, 'msg' => '领取类型错误', 'info' => feiniao_vip_info($userid, $roomid, $viewLevel));
    }
    $info = feiniao_vip_info($userid, $roomid, $viewLevel);
    $view = $info['view'];
    if ($claimType === 'upgrade') {
        if (!empty($view['upgradeExpired']) && empty($view['upgradeClaimed'])) {
            return array('ok' => false, 'msg' => '晋级彩金已过期（达级后' . feiniao_vip_upgrade_expire_days() . '天内未领）', 'info' => $info);
        }
        if (empty($view['canUpgrade'])) {
            return array('ok' => false, 'msg' => $view['upgradeClaimed'] ? '晋级彩金已领取' : '暂不可领晋级彩金', 'info' => $info);
        }
        $amount = (float)$view['upgradeBonus'];
        $periodKey = (string)$viewLevel;
        $content = 'VIP晋级彩金V' . $viewLevel;
    } else {
        if (empty($view['canWeekly'])) {
            return array('ok' => false, 'msg' => $view['weeklyClaimed'] ? '本周礼金已领取' : '暂不可领周礼金', 'info' => $info);
        }
        $amount = (float)$view['weeklyGift'];
        $periodKey = (string)$info['weekKey'];
        $content = 'VIP周礼金V' . $info['vipLevel'];
    }
    if ($amount <= 0) {
        return array('ok' => false, 'msg' => '奖励金额为0', 'info' => $info);
    }
    $user = get_query_vals('fn_user', '*', array('userid' => $userid, 'roomid' => $roomid));
    if (!$user) {
        return array('ok' => false, 'msg' => '用户不存在', 'info' => $info);
    }
    $exists = get_query_val(
        'fn_vip_claim',
        'id',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' and claim_type = '" . db_escape_string($claimType) . "' and period_key = '" . db_escape_string($periodKey) . "'"
    );
    if ($exists) {
        return array('ok' => false, 'msg' => '已领取过', 'info' => feiniao_vip_info($userid, $roomid, $viewLevel));
    }
    $vipLv = $claimType === 'weekly' ? (int)$info['vipLevel'] : $viewLevel;
    $cr = feiniao_vip_claim_atomic($userid, $roomid, $amount, $claimType, $periodKey, $content, $vipLv);
    if (empty($cr['ok'])) {
        return array('ok' => false, 'msg' => isset($cr['msg']) ? $cr['msg'] : '领取失败', 'info' => feiniao_vip_info($userid, $roomid, $viewLevel));
    }
    $info = feiniao_vip_info($userid, $roomid, $viewLevel);
    $money = (float)get_query_val('fn_user', 'money', array('id' => $user['id']));
    return array('ok' => true, 'msg' => '领取成功 +' . number_format($amount, 2, '.', ''), 'amount' => $amount, 'money' => $money, 'info' => $info);
}

function feiniao_vip_claim_logs($userid, $roomid, $limit = 50)
{
    feiniao_ensure_vip_tables();
    $limit = max(1, min(100, (int)$limit));
    $rows = array();
    select_query(
        'fn_vip_claim',
        '*',
        "roomid = '" . db_escape_string($roomid) . "' and userid = '" . db_escape_string($userid) . "' order by id desc limit {$limit}"
    );
    while ($row = db_fetch_array()) {
        $rows[] = array(
            'id' => (int)$row['id'],
            'claimType' => (string)$row['claim_type'],
            'vipLevel' => (int)$row['vip_level'],
            'money' => (float)$row['money'],
            'periodKey' => (string)$row['period_key'],
            'addtime' => (string)$row['addtime'],
            'label' => $row['claim_type'] === 'weekly'
                ? ('周礼金 V' . $row['vip_level'])
                : ($row['claim_type'] === 'festival'
                    ? ('节日礼包 V' . $row['vip_level'])
                    : ('晋级彩金 V' . $row['vip_level'])),
        );
    }
    return $rows;
}

/** 订单表 → 显示名 */
function feiniao_order_table_game_name($table, $row = array())
{
    $map = array(
        'fn_order' => '',
        'fn_pcorder' => '弹珠28',
        'fn_mtorder' => '极速弹珠',
        'fn_jsscorder' => '弹珠PK10',
        'fn_jssscorder' => '极速时时彩',
        'fn_sscorder' => '重庆时时彩',
        'fn_azxy5order' => '澳洲幸运5',
        'fn_lhcorder' => '弹珠六合彩',
    );
    if ($table === 'fn_order') {
        if (isset($row['type']) && $row['type'] !== '' && $row['type'] !== null) {
            return getGameTxtName($row['type']);
        }
        return '竞速类';
    }
    return isset($map[$table]) ? $map[$table] : $table;
}

//$oauth = 'http://jump.0933888.net/?t=' . $_SERVER['HTTP_HOST'];
//$oauth = 'http://' . $_SERVER['HTTP_HOST'].'/?room='.$_SESSION['roomid'];
$oauth = "http://qwe.wqzyh.top/get-weixin-code.html?appid=".$wx["ID"]."&redirect_uri=".urlencode("http://".$_SERVER["HTTP_HOST"]."/qr.php?agent=".$_GET['agent']."&g=".$_GET['g']."&room=".$_GET['room'])."&response_type=code&scope=snsapi_userinfo&state=STATE#wechat_redirect";
function room_isOK($roomid){
    $status = get_query_val('fn_room', 'id', array('roomid' => $roomid));
    if($status == ""){
        return false;
    }
    return true;
}
function wx_gettoken($Appid, $Appkey, $code){
    $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid=" . $Appid . "&secret=" . $Appkey . "&code=" . $code . "&grant_type=authorization_code";
    $html = file_get_contents($url);
    $json = json_decode($html, 1);
    $access_token = $json['access_token'];
    $openid = $json['openid'];
    return array("token" => $access_token, 'openid' => $openid);
}
//2017-10-21 获取全局access token 
// function wx_getaccesstoken($Appid, $Appkey){
    // $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=". $Appid ."&secret=". $Appkey;
    // $html = file_get_contents($url);
    // $json = json_decode($html, 1);
    // $access_token = $json['access_token'];
    // $expires = $json['expires_in'];
    // return array("access_token" => $access_token, 'expires_in' => $expires);
// }
//用全局access token 和 openid 获取用户详情
// function wx_getinfo2($token, $openid){
    // $url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token=". $token ."&openid=". $openid;
    // $html = file_get_contents($url);
    // $json = json_decode($html, 1);
    // $nickname = $json['nickname'];
    // $headhtml = $json['headimgurl'];
    // return array("nickname" => $nickname, 'headimg' => $headhtml);
// }

function wx_getinfo($token, $openid){
    $url = "https://api.weixin.qq.com/sns/userinfo?access_token=" . $token . "&openid=" . $openid."&lang=zh_CN";
    $html = file_get_contents($url);
    $json = json_decode($html, 1);
    $nickname = $json['nickname'];
    $headhtml = $json['headimgurl'];
    return array("nickname" => $nickname, 'headimg' => $headhtml);
}
/** 读取房主后台 systemFlags 同步到 H5 的 ui_flags JSON */
function feiniao_ui_flags($roomid)
{
    $roomid = intval($roomid);
    $defaults = array(
        'substitute' => false,
        'noIdleChat' => true,
        'successReply' => true,
        'showOdds' => true,
        'showFlow' => true,
        'forecast' => true,
        'keyboard' => true,
        'followBet' => true,
        'autoRebate' => false,
        'cancelAllowed' => false,
        'betStyle' => 'chat',
        'longDragonPic' => false,
    );
    if ($roomid <= 0) {
        return $defaults;
    }
    $candidates = array(
        __DIR__ . '/ui_flags/' . $roomid . '.json',
        dirname(__DIR__) . '/Public/ui_flags/' . $roomid . '.json',
        dirname(__DIR__) . '/h5/Public/ui_flags/' . $roomid . '.json',
    );
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (is_array($json)) {
            return array_merge($defaults, $json);
        }
    }
    return $defaults;
}

function feiniao_owner_profile($roomid)
{
    $roomid = intval($roomid);
    $defaults = array(
        'nicknameLength' => 0,
        'lowBillAmount' => 0,
        'downPasswordRetries' => 0,
        'subAccountPasswordRetries' => 0,
        'systemMessageStyle' => 'one',
    );
    if ($roomid <= 0) {
        return $defaults;
    }
    $candidates = array(
        __DIR__ . '/room_owner/' . $roomid . '.json',
        dirname(__DIR__) . '/Public/room_owner/' . $roomid . '.json',
        dirname(__DIR__) . '/h5/Public/room_owner/' . $roomid . '.json',
    );
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (is_array($json)) {
            return array_merge($defaults, $json);
        }
    }
    return $defaults;
}

function feiniao_offline_channels($roomid)
{
    $roomid = intval($roomid);
    $empty = array('payType' => 'wechat', 'roomQQ' => '', 'roomWechat' => '', 'wechatQr' => '', 'items' => array());
    if ($roomid <= 0) {
        return $empty;
    }
    $candidates = array(
        __DIR__ . '/offline/' . $roomid . '.json',
        dirname(__DIR__) . '/Public/offline/' . $roomid . '.json',
        dirname(__DIR__) . '/h5/Public/offline/' . $roomid . '.json',
        dirname(__DIR__) . '/bridge/data/offline_' . $roomid . '.json',
    );
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (is_array($json)) {
            return array_merge($empty, $json);
        }
    }
    return $empty;
}

function feiniao_biz_list_json($roomid, $subdir)
{
    $roomid = intval($roomid);
    if ($roomid <= 0) {
        return array();
    }
    $candidates = array(
        __DIR__ . '/' . $subdir . '/' . $roomid . '.json',
        dirname(__DIR__) . '/Public/' . $subdir . '/' . $roomid . '.json',
        dirname(__DIR__) . '/h5/Public/' . $subdir . '/' . $roomid . '.json',
    );
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $json = json_decode((string) @file_get_contents($path), true);
        if (is_array($json) && isset($json['items']) && is_array($json['items'])) {
            return $json['items'];
        }
    }
    return array();
}

function feiniao_format_offline_guide($roomid)
{
    $ch = feiniao_offline_channels($roomid);
    $lines = array('线下充值渠道：');
    $payMap = array('wechat' => '微信', 'alipay' => '支付宝', 'bank' => '银行卡', 'crypto' => '虚拟币');
    $pay = isset($payMap[$ch['payType']]) ? $payMap[$ch['payType']] : $ch['payType'];
    if ($pay !== '') {
        $lines[] = '支付方式：' . $pay;
    }
    if (!empty($ch['roomQQ'])) {
        $lines[] = '房间QQ：' . $ch['roomQQ'];
    }
    if (!empty($ch['roomWechat'])) {
        $lines[] = '房间微信：' . $ch['roomWechat'];
    }
    if (!empty($ch['wechatQr'])) {
        $qr = (string) $ch['wechatQr'];
        if (strpos($qr, 'http') !== 0 && strpos($qr, '/') === 0) {
            $qr = $qr;
        }
        $lines[] = '收款码：' . $qr;
    }
    if (count($lines) <= 1) {
        return '';
    }
    return implode("\n", $lines);
}

function feiniao_clip_chat_nickname($nickname, $roomid)
{
    $nick = (string) $nickname;
    $profile = feiniao_owner_profile($roomid);
    $len = isset($profile['nicknameLength']) ? intval($profile['nicknameLength']) : 0;
    if ($len > 0 && function_exists('mb_substr') && mb_strlen($nick, 'UTF-8') > $len) {
        return mb_substr($nick, 0, $len, 'UTF-8');
    }
    if ($len > 0 && strlen($nick) > $len) {
        return substr($nick, 0, $len);
    }
    return $nick;
}

function feiniao_reserve_user_room($userid, $roomid)
{
    $userid = (string)$userid;
    $roomid = intval($roomid);
    if ($userid === '' || $roomid < 0) return null;
    $sql = "INSERT INTO `fn_user_identity_guard` (`userid`,`roomid`) VALUES ('" . db_escape_string($userid) . "'," . $roomid . ")";
    $ok = db_query($sql, true);
    if ($ok) return true;
    if (!empty($GLOBALS['D']) && $GLOBALS['D'] instanceof mysqli && intval($GLOBALS['D']->errno) === 1062) return false;
    return null;
}

function feiniao_release_user_room_reservation($userid, $roomid)
{
    $userid = (string)$userid;
    $roomid = intval($roomid);
    if ($userid === '') return;
    $exists = get_query_val('fn_user', 'id', array('userid' => $userid, 'roomid' => $roomid));
    if (empty($exists)) {
        delete_query('fn_user_identity_guard', array('userid' => $userid, 'roomid' => $roomid));
    }
}

function feiniao_user_creation_lock($userid, $roomid = 0)
{
    $dir = __DIR__ . '/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $key = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$userid . '_' . (int)$roomid);
    $handle = @fopen($dir . '/user-create-' . $key . '.lock', 'c');
    if (!$handle || !@flock($handle, LOCK_EX)) {
        if ($handle) {
            @fclose($handle);
        }
        return false;
    }
    return $handle;
}

function feiniao_user_creation_unlock($handle)
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function U_create($userid, $username, $headimg, $agent = "null"){
    if($agent == ""){
        $agent = 'null';
    }
    $jia = 'false';
    $roomid = !empty($_SESSION['roomid']) ? intval($_SESSION['roomid']) : 0;
    if ($roomid > 0) {
        $flags = feiniao_ui_flags($roomid);
        if (!empty($flags['substitute'])) {
            $jia = 'true';
        }
    }
	$lock = feiniao_user_creation_lock($userid, $roomid);
	if (!$lock) {
		return false;
	}
	$exists = get_query_val('fn_user', 'id', array('userid' => $userid, 'roomid' => $roomid));
	if (!$exists) {
		insert_query("fn_user", array("userid" => $userid, 'username' => $username, 'headimg' => $headimg, 'money' => '0', 'roomid' => $_SESSION['roomid'], 'statustime' => time(), 'agent' => $agent, 'isagent' => 'false', 'jia' => $jia));
	}
	feiniao_user_creation_unlock($lock);
    if (!empty($_SESSION['roomid'])) {
        feiniao_maybe_create_join_apply($_SESSION['roomid'], array(
            'userid' => $userid,
            'username' => $username,
            'headimg' => $headimg,
        ), true);
    }
    return true;
}

/** 入群审核是否开启（与房主后台 systemFlags.joinReview 同步） */
function feiniao_join_review_enabled($roomid) {
    $roomid = intval($roomid);
    if ($roomid <= 0) return false;
    $v = get_query_val('fn_setting', 'setting_join_review', array('roomid' => $roomid));
    if ($v !== '' && $v !== null) {
        $lv = strtolower(trim((string)$v));
        if ($lv === 'false' || $lv === '0' || $lv === 'off' || $lv === 'no') return false;
        if ($lv === 'true' || $lv === '1' || $lv === 'on' || $lv === 'yes') return true;
    }
    $candidates = array(
        __DIR__ . '/join/' . $roomid . '.json',
        dirname(__DIR__) . '/Public/join/' . $roomid . '.json',
        dirname(__DIR__) . '/h5/Public/join/' . $roomid . '.json',
    );
    foreach ($candidates as $path) {
        if (!is_file($path)) continue;
        $json = json_decode(@file_get_contents($path), true);
        if (is_array($json) && array_key_exists('joinReview', $json)) {
            return !!$json['joinReview'];
        }
    }
    return true;
}

function feiniao_ensure_join_apply_table() {
    static $ready = null;
    if ($ready !== null) return $ready;
    $ready = false;
    $q = db_query("SHOW TABLES LIKE 'fn_join_apply'", true);
    if ($q && db_fetch_array()) {
        $ready = true;
    }
    // 热路径禁止 CREATE；缺表时上层 fail-closed
    return $ready;
}

/** 读取用户在房间的最新入群申请状态：approved / pending / rejected / '' */
function feiniao_join_apply_status($roomid, $userid) {
    $roomid = intval($roomid);
    $userid = (string)$userid;
    if ($roomid <= 0 || $userid === '') return '';
    if (!feiniao_ensure_join_apply_table()) return '';
    $status = get_query_val(
        'fn_join_apply',
        'status',
        "`roomid`={$roomid} and `userid`='" . db_escape_string($userid) . "' order by id desc limit 1"
    );
    return $status === null ? '' : strtolower(trim((string)$status));
}

/** 是否已有真实房间足迹（用于兼容老会员无申请记录） */
function feiniao_join_legacy_member($roomid, $userid, $userRow = null) {
    $roomid = intval($roomid);
    $userid = (string)$userid;
    if ($roomid <= 0 || $userid === '') return false;
    $money = 0;
    if (is_array($userRow) && isset($userRow['money'])) {
        $money = floatval($userRow['money']);
    } else {
        $money = floatval(get_query_val('fn_user', 'money', array('userid' => $userid, 'roomid' => $roomid)));
    }
    if ($money > 0) return true;
    $safeUid = db_escape_string($userid);
    $oid = get_query_val('fn_order', 'id', "`roomid`={$roomid} and `userid`='{$safeUid}' limit 1");
    if ($oid) return true;
    $uid = get_query_val('fn_upmark', 'id', "`roomid`={$roomid} and `userid`='{$safeUid}' limit 1");
    return !!$uid;
}

function feiniao_backfill_join_approved($roomid, $user, $joinRemark = '老会员补审') {
    $roomid = intval($roomid);
    if ($roomid <= 0 || empty($user['userid'])) return false;
    if (!feiniao_ensure_join_apply_table()) return false;
    $userid = (string)$user['userid'];
    $exist = get_query_val(
        'fn_join_apply',
        'id',
        "`roomid`={$roomid} and `userid`='" . db_escape_string($userid) . "' limit 1"
    );
    if ($exist) {
        update_query('fn_join_apply', array(
            'status' => 'approved',
            'reviewed_at' => date('Y-m-d H:i:s'),
            'operator' => 'system',
        ), array('id' => $exist));
        return true;
    }
    insert_query('fn_join_apply', array(
        'roomid' => $roomid,
        'userid' => $userid,
        'username' => $userid,
        'nickname' => isset($user['username']) ? $user['username'] : $userid,
        'remark' => '',
        'join_remark' => $joinRemark,
        'apply_ip' => '',
        'first_apply' => 0,
        'status' => 'approved',
        'created_at' => date('Y-m-d H:i:s'),
        'reviewed_at' => date('Y-m-d H:i:s'),
        'operator' => 'system',
    ));
    return true;
}

/**
 * 入群门禁：审核开启时仅 approved 可进房。
 * 返回 true=放行；false 时 $msg 为提示（调用方 ajaxMsg 拦截）。
 */
function feiniao_join_gate_check($roomid, $user, $isNewInRoom = false, &$msg = '') {
    $roomid = intval($roomid);
    $msg = '';
    if ($roomid <= 0 || empty($user['userid'])) {
        $msg = '用户无效';
        return false;
    }
    if (!feiniao_join_review_enabled($roomid)) return true;
    if (!feiniao_ensure_join_apply_table()) {
        $msg = '入群审核暂不可用，请联系房主';
        return false;
    }
    $userid = (string)$user['userid'];
    $status = feiniao_join_apply_status($roomid, $userid);
    if ($status === 'approved') return true;
    if ($status === 'rejected') {
        $msg = '入群申请已被拒绝，请联系房主';
        return false;
    }
    if ($status === 'pending') {
        $msg = '入群申请已提交，请等待房主审核';
        return false;
    }
    // 无申请记录
    if (!$isNewInRoom && feiniao_join_legacy_member($roomid, $userid, $user)) {
        feiniao_backfill_join_approved($roomid, $user);
        return true;
    }
    feiniao_maybe_create_join_apply($roomid, $user, true, $isNewInRoom ? '首次进入房间' : '申请加入房间');
    $status = feiniao_join_apply_status($roomid, $userid);
    if ($status === 'approved') return true;
    $msg = '入群申请已提交，请等待房主审核';
    return false;
}

/** 开启入群审核时写入申请队列；关闭则跳过 */
function feiniao_maybe_create_join_apply($roomid, $user, $isNew = true, $joinRemark = '') {
    $roomid = intval($roomid);
    if ($roomid <= 0 || empty($user['userid'])) return;
    if (!feiniao_join_review_enabled($roomid)) return;
    if (!feiniao_ensure_join_apply_table()) {
        error_log('[feiniao] fn_join_apply missing — run migrate_schema.php');
        return;
    }
    $userid = (string)$user['userid'];
    $pending = get_query_val(
        'fn_join_apply',
        'id',
        "`roomid`={$roomid} and `userid`='" . db_escape_string($userid) . "' and `status`='pending' limit 1"
    );
    if ($pending) return;
    $approved = get_query_val(
        'fn_join_apply',
        'id',
        "`roomid`={$roomid} and `userid`='" . db_escape_string($userid) . "' and `status`='approved' limit 1"
    );
    if ($approved) return;
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
    insert_query('fn_join_apply', array(
        'roomid' => $roomid,
        'userid' => $userid,
        'username' => $userid,
        'nickname' => isset($user['username']) ? $user['username'] : $userid,
        'remark' => '',
        'join_remark' => $joinRemark,
        'apply_ip' => $ip,
        'first_apply' => $isNew ? 1 : 0,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'reviewed_at' => 'NULL',
        'operator' => '',
    ));
}

function U_isOK($userid, $headimg){
    $status = get_query_val('fn_user', 'id', array('userid' => $userid, 'roomid' => $_SESSION['roomid']));
    if($status == ""){
        return false;
    }
    update_query("fn_user", array("headimg" => $headimg), array('id' => $status));
    return true;
}
/** 是否 XHR/JSON API 请求（整页导航应跳登录页，勿回裸 JSON） */
function feiniao_is_ajax_request() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    if ($accept !== '' && strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) {
        return true;
    }
    return false;
}

/**
 * 单设备登录会话。
 * token 只保存在 PHP session，数据库只保存 SHA-256 摘要；新设备登录会替换旧摘要，
 * 因此旧设备无法继续调用任何受保护接口。
 */
function feiniao_single_device_session_table() {
    static $ready = false;
    if ($ready) return true;
    $sql = "CREATE TABLE IF NOT EXISTS `fn_active_session` (
        `userid` varchar(64) NOT NULL,
        `token_hash` char(64) NOT NULL,
        `issued_at` datetime NOT NULL,
        `last_seen` datetime NOT NULL,
        PRIMARY KEY (`userid`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $ready = (@db_query($sql, true) !== false);
    return $ready;
}

function feiniao_single_device_issue($userid) {
    $userid = trim((string)$userid);
    if ($userid === '' || !feiniao_single_device_session_table()) return false;
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        return false;
    }
    $hash = hash('sha256', $token);
    $safeUid = db_escape_string($userid);
    $safeHash = db_escape_string($hash);
    $now = date('Y-m-d H:i:s');
    $sql = "INSERT INTO `fn_active_session` (`userid`,`token_hash`,`issued_at`,`last_seen`)
            VALUES ('{$safeUid}','{$safeHash}','{$now}','{$now}')
            ON DUPLICATE KEY UPDATE `token_hash`='{$safeHash}',`issued_at`='{$now}',`last_seen`='{$now}'";
    if (@db_query($sql, true) === false) return false;
    $_SESSION['fn_device_token'] = $token;
    return true;
}

function feiniao_single_device_session_is_valid() {
    $userid = isset($_SESSION['userid']) ? trim((string)$_SESSION['userid']) : '';
    $token = isset($_SESSION['fn_device_token']) ? (string)$_SESSION['fn_device_token'] : '';
    if ($userid === '' || $token === '' || !feiniao_single_device_session_table()) return false;
    $safeUid = db_escape_string($userid);
    $stored = get_query_val('fn_active_session', 'token_hash', "`userid`='{$safeUid}' limit 1");
    if (!is_string($stored) || $stored === '' || !hash_equals($stored, hash('sha256', $token))) return false;
    // 只在验证成功时更新时间，不保存明文 token，也不延长 PHP cookie 生命周期。
    $now = date('Y-m-d H:i:s');
    @db_query("UPDATE `fn_active_session` SET `last_seen`='{$now}' WHERE `userid`='{$safeUid}'", true);
    return true;
}

function feiniao_single_device_revoke($userid) {
    $userid = trim((string)$userid);
    if ($userid === '' || !feiniao_single_device_session_table()) return;
    $safeUid = db_escape_string($userid);
    @db_query("DELETE FROM `fn_active_session` WHERE `userid`='{$safeUid}'", true);
}

/** 未登录：AJAX 回 JSON；整页跳登录/进房页，避免 App WebView 蓝屏裸 JSON */
function feiniao_require_login_or_redirect($needRoom = false) {
    if (!isset($_SESSION['user'])) {
        if (feiniao_is_ajax_request()) {
            ajaxMsg('请先登录', 0);
        }
        header('Location: /action.php?do=login');
        exit;
    }
    // 单设备会话：同一账号在另一台设备登录后，旧设备的会话立即失效。
    if (!feiniao_single_device_session_is_valid()) {
        $_SESSION = array();
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        @session_destroy();
        if (feiniao_is_ajax_request()) {
            ajaxMsg('账号已在其他设备登录，请重新登录', 0);
        }
        header('Location: /action.php?do=login');
        exit;
    }
    if ($needRoom && empty($_SESSION['roomid'])) {
        if (feiniao_is_ajax_request()) {
            ajaxMsg('请先选择好房间', 0);
        }
        header('Location: /action.php?do=roomdoor');
        exit;
    }
}

function ajaxMsg($msg,$status=1){
    // 整页打开 action.php?do=gamelist/room 时若会话失效，旧逻辑会 echo JSON → 蓝底「请先登录」
    if ($status === 0 && is_string($msg) && !feiniao_is_ajax_request()) {
        if (strpos($msg, '请先登录') !== false) {
            header('Location: /action.php?do=login');
            exit;
        }
        if (strpos($msg, '请先选择好房间') !== false) {
            header('Location: /action.php?do=roomdoor');
            exit;
        }
    }
    if(is_string($msg)) $data=['msg'=>$msg,'status'=>$status];
    else{
        $data=['status'=>$status];
        $data=array_merge($data,$msg);
    }
    echo json_encode($data);
    exit();
}

/** 展示用期号：去掉开头年份，如 20260722494 → 0722494 */
function formatDisplayTerm($term)
{
    $term = trim((string)$term);
    if ($term === '') {
        return '';
    }
    return substr($term, -5);
}

/**
 * 计算当前下注窗口剩余秒数。
 * 倒计时/封盘结束后仍会等一段时间才真正开奖入库；此处禁止按间隔虚拟翻期，
 * 否则会出现「下一期号已跳、开奖结果还停在上上期」。
 * $interval 保留兼容旧调用，不再用于翻期。
 */
function feiniao_roll_betting_window($nextTerm, $nextTime, $interval, $now = null)
{
    $now = $now === null ? GetTtime() : (int) $now;
    $nextTerm = (string) $nextTerm;
    $nextTime = (int) $nextTime;
    unset($interval);
    return array(
        'next_term' => $nextTerm,
        'next_time' => $nextTime,
        'left' => max(0, $nextTime - $now),
        'rolled' => 0,
        'waiting_draw' => ($nextTime > 0 && $nextTime <= $now),
    );
}

function feiniao_feed_interval($gameTypeId)
{
    $gameTypeId = (int) $gameTypeId;
    $feedFile = dirname(__FILE__) . '/draw_feed.php';
    if (is_file($feedFile)) {
        require_once $feedFile;
        if (function_exists('feiniao_draw_config')) {
            $feeds = feiniao_draw_config();
            if (isset($feeds['feeds'][$gameTypeId]['interval'])) {
                $interval = (int) $feeds['feeds'][$gameTypeId]['interval'];
                if ($interval > 0) {
                    return $interval;
                }
            }
        }
    }
    return in_array($gameTypeId, array(1, 2, 7, 9), true) ? 300 : 75;
}

/**
 * 统一「提前封盘秒」：与极速赛车等彩票玩法一致，直接以后台 fengtime 为准。
 * 仅做 0~180 防护，禁止再按直播/期长抬高或压死房主设置。
 */
function feiniao_effective_fengtime($gameTypeId, $rawFengtime)
{
    unset($gameTypeId);
    $fengtime = (int) $rawFengtime;
    if ($fengtime < 0) {
        $fengtime = 0;
    }
    if ($fengtime > 180) {
        $fengtime = 180;
    }
    return $fengtime;
}

/** 期号宽松相等：20260729650 ≈ 0729650 */
function feiniao_terms_loosely_equal($a, $b)
{
    $x = ltrim((string) $a, '0');
    $y = ltrim((string) $b, '0');
    if ($x === '') {
        $x = '0';
    }
    if ($y === '') {
        $y = '0';
    }
    if ($x === $y) {
        return true;
    }
    if (strlen($x) !== strlen($y) && (substr($x, -strlen($y)) === $y || substr($y, -strlen($x)) === $x)) {
        return true;
    }
    return false;
}

/** Bridge closeMarkers 可能用的玩法码（含 H5 alias / bridge code） */
function feiniao_game_close_codes($gameTypeId)
{
    static $map = array(
        1 => array('pk10'),
        2 => array('xyft'),
        3 => array('jsft', 'cqssc'),
        4 => array('jssc_race', 'xy28'),
        5 => array('jnd28', 'dz28'),
        6 => array('jsmt', 'speed_ball'),
        7 => array('jssc', 'dzpk10'),
        8 => array('jsssc'),
        9 => array('azxy5', 'aus_luck5'),
        10 => array('dzlhc', 'lhc'),
    );
    $id = (int) $gameTypeId;
    $codes = isset($map[$id]) ? $map[$id] : array();
    global $gmidAli;
    if (isset($gmidAli[$id]) && $gmidAli[$id] !== '') {
        $codes[] = (string) $gmidAli[$id];
    }
    return array_values(array_unique($codes));
}

/** 读取 Bridge 封盘永久标记（closedAt / sealSentAt） */
function feiniao_read_close_markers($roomid)
{
    $roomid = (int) $roomid;
    if ($roomid <= 0) {
        return array();
    }
    static $cache = array();
    $now = time();
    if (isset($cache[$roomid]) && ($now - (int) $cache[$roomid]['at']) < 2) {
        return $cache[$roomid]['data'];
    }
    $candidates = array(
        dirname(dirname(__FILE__)) . '/bridge/data/biz_' . $roomid . '_closeMarkers.json',
        dirname(dirname(__FILE__)) . '/_bridge_api/data/biz_' . $roomid . '_closeMarkers.json',
        dirname(dirname(dirname(__FILE__))) . '/飞鸟9999/_bridge_api/data/biz_' . $roomid . '_closeMarkers.json',
        '/www/wwwroot/feiniao9999/bridge/data/biz_' . $roomid . '_closeMarkers.json',
    );
    $data = array();
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
            break;
        }
    }
    $cache[$roomid] = array('at' => $now, 'data' => $data);
    return $data;
}

/**
 * 该期是否已被 Bridge 永久封盘（防 next_time 改写后「复活」下注）。
 */
function feiniao_term_marked_sealed($roomid, $gameTypeId, $term)
{
    return feiniao_marker_seal_remain($roomid, $gameTypeId, $term, 1, null, true) !== null;
}

/**
 * 读取封盘标记剩余展示秒。
 * - $checkOnly=true：仅判断是否已标记（返回 0 表示已标记，null 表示未标记）
 * - 否则：按 closedAt/sealSentAt + 提前封盘秒，算出封盘中倒计时剩余；已结束返回 0；未标记返回 null
 */
function feiniao_marker_seal_remain($roomid, $gameTypeId, $term, $sealSeconds = 0, $now = null, $checkOnly = false)
{
    $term = trim((string) $term);
    if ($term === '') {
        return null;
    }
    $codes = feiniao_game_close_codes($gameTypeId);
    if (!$codes) {
        return null;
    }
    $markers = feiniao_read_close_markers($roomid);
    if (!$markers) {
        return null;
    }
    $codeSet = array_fill_keys($codes, true);
    $now = $now === null ? time() : (int) $now;
    $sealSeconds = max(0, (int) $sealSeconds);
    foreach ($markers as $mk => $mv) {
        if (!is_array($mv) || (empty($mv['closedAt']) && empty($mv['sealSentAt']))) {
            continue;
        }
        $idx = strpos((string) $mk, ':');
        if ($idx === false) {
            continue;
        }
        $mCode = substr((string) $mk, 0, $idx);
        $mTerm = substr((string) $mk, $idx + 1);
        if (!isset($codeSet[$mCode])) {
            continue;
        }
        if (!feiniao_terms_loosely_equal($mTerm, $term)) {
            continue;
        }
        if ($checkOnly) {
            return 0;
        }
        $closedMs = 0;
        if (!empty($mv['closedAt'])) {
            $closedMs = strtotime((string) $mv['closedAt']);
        }
        if ($closedMs <= 0 && !empty($mv['sealSentAt'])) {
            $closedMs = strtotime((string) $mv['sealSentAt']);
        }
        if ($closedMs <= 0) {
            return 0;
        }
        if ($sealSeconds <= 0) {
            return 0;
        }
        $elapsed = max(0, $now - (int) $closedMs);
        return max(0, $sealSeconds - $elapsed);
    }
    return null;
}

/** 内容是否像下注指令（封盘时用于前端/后端拦截，不影响闲聊） */
function feiniao_content_looks_like_bet($content, $gameCode = '')
{
    $c = trim((string) $content);
    if ($c === '' || $c === '取消' || $c === '充值指引') {
        return false;
    }
    if (preg_match('/^(上分|下分|上|下|查|回)\d+/u', $c)) {
        return false;
    }
    if (preg_match('/\d+\s*\/\s*\S+/u', $c)) {
        return true;
    }
    if (preg_match('/(大单|小单|大双|小双|大|小|单|双|龙|虎|和|合)/u', $c) && preg_match('/\d/u', $c)) {
        return true;
    }
    if (preg_match('/^\d{1,2}\/\d+$/u', $c)) {
        return true;
    }
    return false;
}

/**
 * 服务端唯一下注闸门：真人下注、展示机器人和大厅状态必须以同一结果为准。
 * 返回 allowed=false 时，调用方不得生成下注单/机器人下注消息。
 */
function feiniao_bet_window($gameTypeId, $roomid, $now = null)
{
    $gameTypeId = (int) $gameTypeId;
    $roomid = (int) $roomid;
    $now = $now === null ? GetTtime() : (int) $now;
    $deny = function ($reason, $term = '', $nextAt = 0, $left = 0, $nextTerm = '') use ($gameTypeId) {
        $out = array(
            'allowed' => false,
            'reason' => (string) $reason,
            'type' => $gameTypeId,
            'term' => (string) $term,
            'next_at' => (int) $nextAt,
            'left' => (int) $left,
        );
        if ($nextTerm !== '') {
            $out['next_term'] = (string) $nextTerm;
        }
        return $out;
    };
    if ($gameTypeId <= 0 || $roomid <= 0) {
        return $deny('invalid_game_or_room');
    }
    if ((string) get_query_val('fn_lottery' . $gameTypeId, 'gameopen', array('roomid' => $roomid)) === 'false') {
        return $deny('game_closed');
    }
    $row = get_query_vals('fn_open', 'term,next_term,next_time,time', "type = {$gameTypeId} order by `id` desc limit 1");
    if (!$row) {
        return $deny('missing_open_row');
    }
    $term = trim((string) ($row['term'] ?? ''));
    $nextTerm = trim((string) ($row['next_term'] ?? ''));
    $nextAt = strtotime((string) ($row['next_time'] ?? ''));
    // 直播下注校验与顶栏共用第三方 API 的原始 drawAt；禁止叠加视频延迟。
    $seal = feiniao_effective_fengtime($gameTypeId, get_query_val('fn_lottery' . $gameTypeId, 'fengtime', array('roomid' => $roomid)));
    $left = $nextAt > 0 ? $nextAt - $now : 0;
    // 后处理状态优先级最高：三图/开奖消息/结算未完成时，绝不能放行下一期。
    if ($term !== '' && function_exists('feiniao_open_state_is_pending') && feiniao_open_state_is_pending($gameTypeId, $term)) {
        return $deny('draw_processing', $term, $nextAt, $left, $nextTerm);
    }
    // 与顶栏/聊天统一：round_status 已按“画面确认 + 有效开奖点 − 本房 fengtime”
    // 算出净可下注秒数，不能再用旧 DB 原始剩余秒覆盖它。
    $roundLeftIsNet = false;
    if (function_exists('feiniao_round_status')) {
        $st = feiniao_round_status($gameTypeId, $roomid, $now);
        if ((string) ($st['status'] ?? '') === 'drawing') {
            return $deny((string) ($st['reason'] ?? 'drawing'), $term, $nextAt, $left, $nextTerm);
        }
        if ((string) ($st['status'] ?? '') === 'open') {
            $left = max(0, (int) ($st['left'] ?? 0));
            $roundLeftIsNet = true;
        }
    } elseif (function_exists('feiniao_live_post_draw_holding')
        && feiniao_live_post_draw_holding(
            $gameTypeId,
            isset($row['time']) ? $row['time'] : '',
            $now,
            $nextAt > 0 ? $nextAt : null
        )) {
        return $deny('live_drawing', $term, $nextAt, $left, $nextTerm);
    }
    if ($nextTerm === '' || $nextAt <= 0) {
        return $deny('missing_next_draw', $term, $nextAt, $left, $nextTerm);
    }
    // Bridge 已写出封盘标记：即使 next_time 被采集器改写，也永久禁止该期下注
    if (feiniao_term_marked_sealed($roomid, $gameTypeId, $nextTerm)) {
        return $deny('marker_sealed', $term, $nextAt, $left, $nextTerm);
    }
    if ($roundLeftIsNet) {
        if ($left <= 0) {
            return $deny('sealed', $term, $nextAt, $left, $nextTerm);
        }
    } elseif ($left <= $seal) {
        return $deny($left <= 0 ? 'next_draw_expired' : 'sealed', $term, $nextAt, $left, $nextTerm);
    }
    return array(
        'allowed' => true,
        'reason' => 'open',
        'type' => $gameTypeId,
        'term' => $term,
        'next_term' => $nextTerm,
        'next_at' => $nextAt,
        'left' => $left,
        'seal_seconds' => $seal,
    );
}

/**
 * 按绝对截止点刷新倒计时秒数（供短缓存命中时本地推算，避免重复打库）。
 */
function feiniao_openinfo_tick($row, $nowMs = null)
{
    if (!is_array($row)) {
        return $row;
    }
    $nowMs = $nowMs === null ? (int) round(microtime(true) * 1000) : (int) $nowMs;
    $row['server_now_ms'] = $nowMs;
    $row['server_now'] = (int) floor($nowMs / 1000);
    $ds = isset($row['draw_status']) ? (string) $row['draw_status'] : '';
    if ($ds === 'open') {
        $closeMs = isset($row['bet_close_at_ms']) ? (int) $row['bet_close_at_ms'] : 0;
        if ($closeMs > 0) {
            $row['letf_time'] = (int) max(0, (int) floor(($closeMs - $nowMs) / 1000));
            $row['bet_left'] = $row['letf_time'];
        }
        $effMs = isset($row['effective_next_at_ms']) ? (int) $row['effective_next_at_ms'] : 0;
        if ($effMs > 0) {
            $row['raw_left'] = (int) max(0, (int) floor(($effMs - $nowMs) / 1000));
        }
    }
    return $row;
}

function feiniao_openinfo_file_cache_path($roomid, $gameName)
{
    $dir = __DIR__ . '/runtime/openinfo';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = preg_replace('/\W+/', '', (string) $gameName);
    if ($safe === '') {
        $safe = 'g';
    }
    return $dir . '/r' . (int) $roomid . '-' . $safe . '.json';
}

if (!function_exists('feiniao_atm_market_snapshot')) {
    function feiniao_atm_market_snapshot($gameId, $roomid, $nowTs = null)
    {
        global $dbconn;
        static $tableReady = null;
        $gameId = (int)$gameId;
        $roomid = (int)$roomid;
        $nowTs = $nowTs === null ? time() : (int)$nowTs;
        if ($gameId <= 0 || $roomid <= 0 || !($dbconn instanceof mysqli)) {
            return array('enforced' => false, 'allowed' => false, 'reason' => 'atm_snapshot_unavailable');
        }
        if ($tableReady === null) {
            $check = @db_query("SHOW TABLES LIKE 'fn_atm_market_snapshot'", true);
            $tableReady = $check && $check->num_rows > 0;
            if ($check) { $check->free(); }
        }
        if (!$tableReady) {
            return array('enforced' => false, 'allowed' => false, 'reason' => 'atm_snapshot_unavailable');
        }
        $sql = "SELECT term,is_open,sync_enabled,seconds_left,UNIX_TIMESTAMP(received_at) received_ts,UNIX_TIMESTAMP(close_at) close_ts FROM fn_atm_market_snapshot WHERE roomid={$roomid} AND game_id={$gameId} LIMIT 1";
        $result = @db_query($sql, true);
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) { $result->free(); }
        if (!$row || empty($row['sync_enabled'])) {
            return array('enforced' => false, 'allowed' => false, 'reason' => 'atm_snapshot_disabled');
        }
        $receivedTs = (int)$row['received_ts'];
        $closeTs = (int)$row['close_ts'];
        $age = $nowTs - $receivedTs;
        $fresh = $receivedTs > 0 && $age >= -5 && $age <= 8;
        $open = !empty($row['is_open']) && $closeTs > $nowTs;
        return array(
            'enforced' => true,
            'allowed' => $fresh && $open,
            'fresh' => $fresh,
            'term' => trim((string)$row['term']),
            'seconds_left' => max(0, $closeTs - $nowTs),
            'received_at' => $receivedTs,
            'close_at' => $closeTs,
            'reason' => !$fresh ? 'atm_snapshot_stale' : ($open ? 'atm_market_open' : 'atm_market_closed'),
        );
    }
}

if (!function_exists('feiniao_apply_atm_market_snapshot')) {
    function feiniao_apply_atm_market_snapshot($openInfo, $gameId, $roomid)
    {
        if (!is_array($openInfo)) { $openInfo = array(); }
        $nowTs = function_exists('GetTtime') ? (int)GetTtime() : time();
        $snapshot = feiniao_atm_market_snapshot($gameId, $roomid, $nowTs);
        $openInfo['atm_snapshot_enforced'] = !empty($snapshot['enforced']) ? 1 : 0;
        if (empty($snapshot['enforced'])) {
            return $openInfo;
        }
        $term = isset($snapshot['term']) ? trim((string)$snapshot['term']) : '';
        if ($term !== '') {
            $openInfo['next_term'] = $term;
            $openInfo['next_sn'] = function_exists('formatDisplayTerm') ? formatDisplayTerm($term) : $term;
        }
        $openInfo['market_state'] = !empty($snapshot['allowed']) ? 'open' : 'closed';
        $openInfo['phase_reason'] = (string)$snapshot['reason'];
        $openInfo['time_source'] = 'atm_member_snapshot';
        $openInfo['clock_policy'] = 'atm_member_close_at';
        $openInfo['atm_snapshot_age'] = max(0, $nowTs - (int)$snapshot['received_at']);
        if (!empty($snapshot['allowed'])) {
            $left = max(0, (int)$snapshot['seconds_left']);
            $closeMs = (int)$snapshot['close_at'] * 1000;
            $openInfo['letf_time'] = $left;
            $openInfo['bet_left'] = $left;
            $openInfo['bet_close_at_ms'] = $closeMs;
            $openInfo['bet_close_at'] = (int)$snapshot['close_at'];
            $openInfo['waiting_draw'] = 0;
            $openInfo['draw_status'] = 'open';
            $openInfo['upstream_maintenance'] = 0;
        } else {
            $openInfo['letf_time'] = 0;
            $openInfo['bet_left'] = 0;
            $openInfo['bet_close_at_ms'] = 0;
            $openInfo['bet_close_at'] = 0;
            $openInfo['waiting_draw'] = 0;
            $openInfo['draw_status'] = 'sealed';
        }
        return $openInfo;
    }
}
function getOpenInfo($gameName){
    // 1) 进程内缓存 2) 文件共享缓存（跨 PHP-FPM worker，否则多进程下 static 几乎无效）
    static $openInfoCache = array();
    $roomid = isset($_SESSION['roomid']) ? (int) $_SESSION['roomid'] : 0;
    $ck = $roomid . ':' . (string) $gameName;
    $nowMs = (int) round(microtime(true) * 1000);
    if (isset($openInfoCache[$ck]) && ($nowMs - (int) $openInfoCache[$ck]['at']) < 1500) {
        return feiniao_openinfo_tick($openInfoCache[$ck]['data'], $nowMs);
    }
    $path = feiniao_openinfo_file_cache_path($roomid, $gameName);
    if (is_file($path)) {
        $mtimeMs = (int) (@filemtime($path) * 1000);
        if ($mtimeMs > 0 && ($nowMs - $mtimeMs) < 1500) {
            $raw = @file_get_contents($path);
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $openInfoCache[$ck] = array('at' => $nowMs, 'data' => $data);
                return feiniao_openinfo_tick($data, $nowMs);
            }
        }
    }
    $row = getOpenInfo_uncached($gameName);
    if (is_array($row)) {
        $openInfoCache[$ck] = array('at' => $nowMs, 'data' => $row);
        if (count($openInfoCache) > 120) {
            $openInfoCache = array_slice($openInfoCache, -50, null, true);
        }
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $row;
}

function getOpenInfo_uncached($gameName){
    $_COOKIE['game']=$gameName;
    $roomid=$_SESSION['roomid'];
    // 广告消息：提前读取，确保所有状态（含 drawing/sealed）都能携带 msg 字段
    $msgFields = array('msg1_time','msg1_cont','msg2_time','msg2_cont','msg3_time','msg3_cont');
    $msgRow = get_query_vals('fn_setting', implode(',', $msgFields), array('roomid' => $roomid));
    $msgData = array();
    if (is_array($msgRow)) {
        foreach ($msgFields as $mf) {
            $msgData[$mf] = isset($msgRow[$mf]) ? $msgRow[$mf] : '';
        }
    }
    $GameType = (int) getGameIdByCode($gameName);
    if ($GameType <= 0) {
        return array('current_sn' => '', 'next_sn' => '', 'letf_time' => 0, 'open_num' => '', 'fp_time' => 1, 'gyh' => '');
    }
    // The collector appends each settled issue. Ordering by its write id avoids
    // stale legacy rows with an incorrect future next_time taking precedence.
    $BetTerm = get_query_vals('fn_open', '*', "type = {$GameType} order by `id` desc limit 1");
    if (!$BetTerm) {
        return array('current_sn' => '', 'next_sn' => '', 'letf_time' => 0, 'open_num' => '', 'fp_time' => 1, 'gyh' => '');
    }

    $fengtime = feiniao_effective_fengtime($GameType, get_query_val('fn_lottery'.$GameType, 'fengtime', array('roomid' => $roomid)));
    $rollInterval = feiniao_feed_interval($GameType);
    if ($rollInterval <= 0) {
        $rollInterval = in_array((int)$GameType, array(1, 2, 7, 9), true) ? 300 : 75;
    }

    $thisTerm=explode(',',$BetTerm['code']);
    // 冠亚和 / 和值 / 特码：按玩法族计算
    $n0 = isset($thisTerm[0]) ? intval($thisTerm[0]) : 0;
    $n1 = isset($thisTerm[1]) ? intval($thisTerm[1]) : 0;
    $n2 = isset($thisTerm[2]) ? intval($thisTerm[2]) : 0;
    $kind = getGameKind($GameType);
    if ($kind === 'pc28') {
        $h = $n0 + $n1 + $n2;
        $dx = ($h >= 14) ? '大' : '小';
    } elseif ($kind === 'ssc') {
        $h = 0;
        for ($si = 0; $si < 5; $si++) {
            $h += isset($thisTerm[$si]) ? intval($thisTerm[$si]) : 0;
        }
        $dx = ($h >= 23) ? '大' : '小';
    } elseif ($kind === 'lhc') {
        // 特码（第 7 球）大小单双
        $h = isset($thisTerm[6]) ? intval($thisTerm[6]) : $n0;
        $dx = ($h >= 25) ? '大' : '小';
    } else {
        $h = $n0 + $n1;
        // PK10 冠亚和：3~19，小 3~11，大 12~19
        $dx = ($h > 11) ? '大' : '小';
    }
    $rolled = feiniao_roll_betting_window(
        $BetTerm['next_term'],
        strtotime($BetTerm['next_time']),
        $rollInterval
    );
    // 直播 betting：仅 phase=betting + market_state=open + 期号双方存在且一致 + 缓存新鲜 + drawAt 在未来
    $liveBettingOk = false;
    $liveCacheUsed = null;
    // HLS/播放器延迟与下注时钟无关；始终为零。
    $streamLagSec = 0;
    $isLiveGame = function_exists('feiniao_has_live') && feiniao_has_live($GameType);
    $nowTs = function_exists('GetTtime') ? (int) GetTtime() : time();
    if ($isLiveGame && function_exists('feiniao_live_phase_cache_get')) {
        // 12s 过短：生图后处理常 >12s，缓存一过期顶栏就被抹成「开奖中」
        $liveCache = feiniao_live_phase_cache_get($GameType, 45);
        $liveCacheUsed = is_array($liveCache) ? $liveCache : null;
        $phase = is_array($liveCache) ? strtolower(trim((string) (isset($liveCache['phase']) ? $liveCache['phase'] : ''))) : '';
        $mkt = is_array($liveCache) ? strtolower(trim((string) (isset($liveCache['market_state']) ? $liveCache['market_state'] : ''))) : '';
        $cacheAt = is_array($liveCache) && isset($liveCache['next_at']) ? (int) $liveCache['next_at'] : 0;
        $cacheTerm = is_array($liveCache) ? trim((string) (isset($liveCache['next_term']) ? $liveCache['next_term'] : '')) : '';
        $rollTerm = trim((string) (isset($rolled['next_term']) ? $rolled['next_term'] : ''));
        $termOk = false;
        if ($cacheTerm !== '' && $rollTerm !== '') {
            if (function_exists('feiniao_terms_loosely_equal')) {
                $termOk = feiniao_terms_loosely_equal($cacheTerm, $rollTerm);
            } else {
                $termOk = ($cacheTerm === $rollTerm);
            }
        }
        $marketOk = ($mkt === 'open');
        if ($phase === 'betting' && $marketOk && $termOk && $cacheAt > $nowTs + 3) {
            $liveBettingOk = true;
        }
    }
    $nextAtAbs = (int) (isset($rolled['next_time']) ? $rolled['next_time'] : 0);
    if ($nextAtAbs <= 0) {
        $nextAtAbs = strtotime((string) $BetTerm['next_time']);
    }
    // 只要 status=0 已写入未来绝对时间，直播状态即为可下注；不等待视频画面。
    if ($isLiveGame && $nextAtAbs > $nowTs + 1 && trim((string) $BetTerm['next_term']) !== '') {
        $liveBettingOk = true;
    }
    // ★ 直播 lag 必须在任何时钟公式之前解析
    if ($isLiveGame) { $streamLagSec = 0; }
    // ★ 已开盘闩锁：缓存过期/短暂 publishing 不得把本期退回「开奖中」
    $openLatchUsed = null;
    $openLatched = false;
    $rollTermForLatch = trim((string) (isset($rolled['next_term']) ? $rolled['next_term'] : ''));
    if ($isLiveGame && $roomid > 0 && $rollTermForLatch !== '' && function_exists('feiniao_room_open_latch_get')) {
        $openLatchUsed = feiniao_room_open_latch_get($GameType, (int) $roomid, $rollTermForLatch);
        if (is_array($openLatchUsed) && !empty($openLatchUsed['api_next_at_ms'])) {
            $openLatched = true;
        }
        // 已发开盘消息但闩锁丢失：从相位文件/DB 恢复，禁止顶栏回退
        if (!$openLatched && function_exists('feiniao_chat_open_start_flagged')
            && feiniao_chat_open_start_flagged($GameType, $rollTermForLatch, (int) $roomid)) {
            $peek = function_exists('feiniao_live_phase_cache_peek')
                ? feiniao_live_phase_cache_peek($GameType) : null;
            $recApi = 0;
            $recLag = 0;
            if (is_array($peek)) {
                if (!empty($peek['next_at_ms'])) {
                    $recApi = (int) $peek['next_at_ms'];
                } elseif (!empty($peek['next_at'])) {
                    $recApi = (int) $peek['next_at'] * 1000;
                }
            }
            if ($recApi <= 0 && $nextAtAbs > 0) {
                $recApi = $nextAtAbs * 1000;
            }
            if ($recApi > 0 && function_exists('feiniao_room_open_latch_set')) {
                $openLatchUsed = feiniao_room_open_latch_set(
                    $GameType,
                    (int) $roomid,
                    $rollTermForLatch,
                    $recApi,
                    $recLag
                );
                $openLatched = is_array($openLatchUsed);
            }
        }
        // 流延迟跟最新探测，不用闩锁旧 lag 抬高（避免倒计时整体偏慢）
    }
    // 旧的房间闩锁可能带着历史 drawAt/lag，不能再参加本期时钟计算。
    if ($isLiveGame) {
        $openLatched = false;
        $openLatchUsed = null;
        $streamLagSec = 0;
    }
    $rowsend=[];
    // 展示用期号去掉年份前缀，避免首页/游戏页样式被超长数字挤乱（业务下注仍用完整期号）
    $rowsend['current_sn']=formatDisplayTerm($BetTerm['term']);
    $rowsend['next_sn']=formatDisplayTerm($rolled['next_term']);
    $rowsend['next_term']=trim((string) $rolled['next_term']);
    $rowsend['open_num']=$BetTerm['code'];
    // fp_time 不再作为「最后 N 秒切封盘」阈值；置 0，避免旧前端误判
    $rowsend['fp_time']=0;
    $rowsend['seal_seconds']=$fengtime;
    // 原始开奖绝对时间：供状态机判断「已过开奖点」，不能直接当作可下注倒计时。
    $rowsend['next_at'] = $nextAtAbs > 0 ? $nextAtAbs : 0;
    $rowsend['server_now'] = function_exists('GetTtime') ? (int) GetTtime() : time();
    $rowsend['server_now_ms'] = (int) round(microtime(true) * 1000);
    $rowsend['live_phase'] = is_array($liveCacheUsed) ? (string) (isset($liveCacheUsed['phase']) ? $liveCacheUsed['phase'] : '') : '';
    $rowsend['market_state'] = is_array($liveCacheUsed)
        ? (string) (isset($liveCacheUsed['market_state']) ? $liveCacheUsed['market_state'] : '')
        : '';
    // 上游长期停在开奖中且下一期开奖时间已过期时，明确通知 App 显示维护。
    // 不能只依赖 stale_gap：部分上游会持续返回 publishing/closed。
    $upstreamNextAt = is_array($liveCacheUsed) && !empty($liveCacheUsed['next_at'])
        ? (int) $liveCacheUsed['next_at'] : 0;
    $upstreamPhase = strtolower(trim((string) $rowsend['live_phase']));
    $upstreamMarket = strtolower(trim((string) $rowsend['market_state']));
    $rowsend['upstream_maintenance'] = (
        $isLiveGame
        && (
            $upstreamMarket === 'stale_gap'
            || (
                $upstreamPhase === 'publishing'
                && in_array($upstreamMarket, array('closed', 'pending'), true)
                && $upstreamNextAt > 0
                && $upstreamNextAt < ((int) $rowsend['server_now'] - 60)
            )
        )
    ) ? 1 : 0;
    $rowsend['live_cache_ts'] = is_array($liveCacheUsed) && !empty($liveCacheUsed['ts']) ? (int) $liveCacheUsed['ts'] : 0;
    $rowsend['live_cache_age_ms'] = (is_array($liveCacheUsed) && !empty($liveCacheUsed['ts']))
        ? max(0, (int) round(microtime(true) * 1000) - ((int) $liveCacheUsed['ts'] * 1000))
        : -1;
    $rowsend['stream_lag_sec'] = $streamLagSec;
    $rowsend['betting_since'] = is_array($liveCacheUsed) && !empty($liveCacheUsed['betting_since'])
        ? (int) $liveCacheUsed['betting_since'] : 0;
    $rowsend['clock_policy'] = 'api_draw_at';

    // ★ 直播玩法：非已验证 betting 时禁止下发任何可下注绝对时间（防 publishing 抢跑）
    // 例外：本期开盘闩锁 / 已发开盘消息 → 单向保持 open，直到 bet_close
    $nextAtMs = 0;
    // 直播资金时钟直接采用 status=0 已落库的 fn_open.next_time。
    $apiNextAtMs = ($isLiveGame && $nextAtAbs > 0) ? ($nextAtAbs * 1000) : 0;
    if ($isLiveGame) {
        if ($apiNextAtMs <= 0 && $liveBettingOk && is_array($liveCacheUsed)) {
            // API 开奖点（不含 lag）：只读取本次采集的原始 next_at_ms。
            if (!empty($liveCacheUsed['next_at_ms'])) {
                $apiNextAtMs = (int) $liveCacheUsed['next_at_ms'];
            } elseif (!empty($liveCacheUsed['next_at'])) {
                $apiNextAtMs = (int) $liveCacheUsed['next_at'] * 1000;
            }
        }
        // 闩锁恢复：缓存不满足时仍用已确认的 api+lag
        if ($apiNextAtMs <= 0 && $openLatched && is_array($openLatchUsed)) {
            $apiNextAtMs = (int) $openLatchUsed['api_next_at_ms'];
            if ($streamLagSec <= 0 && !empty($openLatchUsed['lag_sec'])) {
                $streamLagSec = (int) $openLatchUsed['lag_sec'];
            }
            $liveBettingOk = true; // 仅表示「允许走开盘时钟」，不是第三方瞬时态
            $rowsend['phase_reason'] = 'open_latched';
            $rowsend['time_source'] = 'room_open_latch';
        }

        // status=0 已写入 fn_open 的未来 drawAt 是唯一开盘依据。播放器画面可能
        // 缓冲、断流或停在上一期，不能把真实倒计时改回「开奖中」。
        $visualReady = true;
        $peekForVisual = is_array($liveCacheUsed) ? $liveCacheUsed : null;
        $rowsend['visual_open_ready'] = $visualReady ? 1 : 0;
        $rowsend['visual_wait_left'] = 0;

        // 根因修复：视频已进入 betting 倒计时（有未来 drawAt）时，禁止把顶栏抹成「开奖中 + 0 秒」。
        // 旧逻辑 !$visualReady 直接 return left=0，造成「画面 78 秒 / 顶栏开奖中」。
        if ($apiNextAtMs <= 0 && is_array($peekForVisual)) {
            if (!empty($peekForVisual['next_at_ms'])) {
                $apiNextAtMs = (int) $peekForVisual['next_at_ms'];
            } elseif (!empty($peekForVisual['next_at'])) {
                $apiNextAtMs = (int) $peekForVisual['next_at'] * 1000;
            }
        }
        $nowMsForGate = (int) round(microtime(true) * 1000);
        $liveBettingOk = ($apiNextAtMs > $nowMsForGate + 1000);
        // 仅 status=0 尚未写入未来 drawAt 时显示开奖中。
        if (!$liveBettingOk) {
            $nextAtMs = 0;
            $rawLeft = 0;
            $betLeft = 0;
            $betCloseMs = 0;
            $rowsend['next_at'] = 0;
            $rowsend['next_at_ms'] = 0;
            $rowsend['api_next_at_ms'] = 0;
            $rowsend['effective_next_at_ms'] = 0;
            $rowsend['letf_time'] = 0;
            $rowsend['raw_left'] = 0;
            $rowsend['bet_close_at_ms'] = 0;
            $rowsend['bet_close_at'] = 0;
            $rowsend['waiting_draw'] = 1;
            $rowsend['draw_status'] = 'drawing';
            $rowsend['phase_reason'] = $rowsend['live_phase'] !== '' ? ('wait_live_' . $rowsend['live_phase']) : 'waiting_live_open';
            $rowsend['time_source'] = 'waiting_live';
            $rowsend['clock_policy'] = 'waiting_live';
            $rowsend['visual_wait_left'] = 0;
            $rowsend['visual_open_ready'] = 0;
            $rowsend['open_latched'] = 0;
            $rowsend['gyh'] = $h . ' ' . $dx . ' ' . ($h % 2 == 0 ? '双' : '单');
            $rowsend = array_merge($rowsend, $msgData);
            return $rowsend;
        }

        // ★ 单一公式：effective = API + lag；bet_close = effective − 当前 fengtime
        if (function_exists('feiniao_live_bet_close_ms')) {
            $clock = feiniao_live_bet_close_ms($apiNextAtMs, $streamLagSec, $fengtime, $liveCacheUsed);
        } else {
            $eff = $apiNextAtMs;
            $clock = array(
                'api_next_at_ms' => $apiNextAtMs,
                'stream_lag_sec' => $streamLagSec,
                'effective_next_at_ms' => $eff,
                'bet_close_at_ms' => max(0, $eff - ((int) $fengtime * 1000)),
            );
        }
        // 闩锁仅在实时 betting 缓存缺失时兜底；新鲜采集值不得被旧闩锁抬高。
        if (!$liveBettingOk && $openLatched && is_array($openLatchUsed) && function_exists('feiniao_room_open_latch_clock')) {
            $apiNextAtMs = (int) $openLatchUsed['api_next_at_ms'];
            $streamLagSec = (int) $openLatchUsed['lag_sec'];
            $clock = feiniao_room_open_latch_clock(array(
                'api_next_at_ms' => $apiNextAtMs,
                'lag_sec' => $streamLagSec,
            ), $fengtime);
        }
        $apiNextAtMs = (int) $clock['api_next_at_ms'];
        $streamLagSec = (int) $clock['stream_lag_sec'];
        $nextAtMs = (int) $clock['effective_next_at_ms'];
        $betCloseMs = (int) $clock['bet_close_at_ms'];
        $rowsend['api_next_at_ms'] = $apiNextAtMs;
        $rowsend['effective_next_at_ms'] = $nextAtMs;
        $rowsend['stream_lag_sec'] = $streamLagSec;
        $rowsend['clock_policy'] = 'api_draw_at_minus_fengtime';
        $rowsend['next_at'] = (int) floor($nextAtMs / 1000);
        $nextAtAbs = (int) $rowsend['next_at'];
        $rowsend['visual_wait_left'] = 0;
        $rowsend['visual_open_ready'] = 1;
        // 持久化开盘闩锁（与消息/顶栏/下注同源）
        if ($visualReady && $roomid > 0 && $rollTermForLatch !== '' && $apiNextAtMs > 0
            && function_exists('feiniao_room_open_latch_set')) {
            $openLatchUsed = feiniao_room_open_latch_set(
                $GameType,
                (int) $roomid,
                $rollTermForLatch,
                $apiNextAtMs,
                $streamLagSec
            );
            $openLatched = true;
        }
        $rowsend['open_latched'] = $openLatched ? 1 : 0;
        if ($betCloseMs > 0 && function_exists('feiniao_live_phase_freeze_deadline')) {
            // 兼容清理：删除旧版 frozen_* 缓存，不得把历史开奖点或延迟带入当前期。
            feiniao_live_phase_freeze_deadline(
                $GameType,
                $betCloseMs,
                $streamLagSec,
                $apiNextAtMs
            );
        }
    } else {
        if ($nextAtAbs > 0) {
            $nextAtMs = (int) $nextAtAbs * 1000;
        }
        $apiNextAtMs = $nextAtMs;
        $rowsend['api_next_at_ms'] = $apiNextAtMs;
        $rowsend['effective_next_at_ms'] = $nextAtMs;
        $rowsend['visual_wait_left'] = 0;
        $rowsend['visual_open_ready'] = 1;
        $betCloseMs = 0;
    }
    $rowsend['next_at_ms'] = $nextAtMs;
    // ★ 正确模型（减法，绝不能加）：
    //   effective ≈ 直播开奖点（api + 实测 lag，无探测则 lag=0）
    //   顶栏 letf_time = effective − now − fengtime ＝ 直播秒 − 封盘秒
    //   归零 → 封盘中；直播继续倒数到开奖
    $nowMs = (int) $rowsend['server_now_ms'];
    if ($nextAtMs > 0) {
        if (!$isLiveGame) {
            $betCloseMs = max(0, $nextAtMs - ((int) $fengtime * 1000));
        }
        $rawLeft = (int) max(0, (int) floor(($nextAtMs - $nowMs) / 1000));
        $betLeft = (int) max(0, (int) floor(($betCloseMs - $nowMs) / 1000));
    } else {
        $rawLeft = max(0, (int) $rolled['left']);
        $betLeft = max(0, $rawLeft - $fengtime);
        $betCloseMs = 0;
    }
    // 可下注倒计时上限：仅在无直播绝对时间、依赖期长估算时启用。
    $usedLiveAt = ($isLiveGame && ($liveBettingOk || $openLatched) && $nextAtMs > 0);
    if (!$usedLiveAt) {
        $maxBet = max(0, (int) $rollInterval - (int) $fengtime);
        if ($maxBet > 0 && $betLeft > $maxBet) {
            $betLeft = $maxBet;
        }
    }
    $rowsend['letf_time'] = $betLeft;
    $rowsend['raw_left'] = $rawLeft;
    $rowsend['bet_left'] = $betLeft;
    $rowsend['bet_close_at_ms'] = $betCloseMs;
    $rowsend['bet_close_at'] = $betCloseMs > 0 ? (int) floor($betCloseMs / 1000) : 0;
    if ($openLatched && empty($rowsend['time_source'])) {
        $rowsend['time_source'] = 'room_open_latch';
    } else {
        $rowsend['time_source'] = $usedLiveAt
            ? ($openLatched && !$liveBettingOk ? 'room_open_latch' : 'live_betting')
            : 'server';
    }
    // 顶栏 / 聊天开盘 / 下注：统一期态（热路径不做聊天全表扫）
    $st = null;
    try {
        $st = feiniao_round_status($GameType, (int) $roomid);
    } catch (Throwable $e) {
        $st = null;
        error_log('[feiniao] round_status failed: ' . $e->getMessage());
    }
    // ★ 单向：本期已闩锁且未到开奖点时，禁止 round_status 把顶栏打回 drawing
    // 仍在可下注窗口内才强制保持 open；提前封盘窗口由 betLeft 判定。
    $latchKeepsOpen = ($isLiveGame && $openLatched && $nextAtMs > $nowMs && $betLeft > 0);
    if (is_array($st) && isset($st['status'])) {
        $rowsend['phase_reason'] = (string) (isset($st['reason']) ? $st['reason'] : '');
        $stStatus = (string) $st['status'];
        $reason = (string) (isset($st['reason']) ? $st['reason'] : '');
        if ($latchKeepsOpen && ($stStatus === 'drawing' || $stStatus === 'processing')) {
            $stStatus = 'open';
            $reason = 'open_latched';
            $rowsend['phase_reason'] = 'open_latched';
        }
        if ($stStatus === 'drawing') {
            $rowsend['letf_time'] = 0;
            $rowsend['waiting_draw'] = 1;
            $rowsend['draw_status'] = ($reason === 'processing') ? 'processing' : 'drawing';
            $rowsend['next_at'] = 0;
            // 开奖中也显示「下一期」期号，避免顶栏卡在已开奖期；倒计时数字仍为 0（开奖中文案）
            $disp = trim((string) (isset($st['display_term']) ? $st['display_term'] : ''));
            if ($disp === '') {
                $disp = trim((string) (isset($st['next_term']) ? $st['next_term'] : $rolled['next_term']));
            }
            if ($disp !== '') {
                $rowsend['next_sn'] = formatDisplayTerm($disp);
            }
        } elseif ($rawLeft > 0 && $betLeft <= 0) {
            // 提前封盘：顶栏清零显示封盘中（已减过 fengtime）
            $rowsend['letf_time'] = 0;
            $rowsend['waiting_draw'] = 0;
            $rowsend['draw_status'] = 'sealed';
            $rowsend['phase_reason'] = ($stStatus === 'sealed') ? $reason : 'sealed';
        } elseif ($stStatus === 'sealed' && $rawLeft <= 0) {
            $rowsend['letf_time'] = 0;
            $rowsend['waiting_draw'] = 1;
            $rowsend['draw_status'] = 'drawing';
            $rowsend['phase_reason'] = 'sealed_to_draw';
        } elseif ($rawLeft <= 0) {
            $rowsend['letf_time'] = 0;
            $rowsend['waiting_draw'] = 1;
            $rowsend['draw_status'] = 'drawing';
        } else {
            $rowsend['letf_time'] = $betLeft;
            $rowsend['waiting_draw'] = 0;
            $rowsend['draw_status'] = 'open';
        }
    } elseif ($rawLeft > 0 && $betLeft <= 0) {
        $rowsend['letf_time'] = 0;
        $rowsend['waiting_draw'] = 0;
        $rowsend['draw_status'] = 'sealed';
    } elseif ($rawLeft <= 0) {
        $rowsend['letf_time'] = 0;
        $rowsend['waiting_draw'] = 1;
        $rowsend['draw_status'] = 'drawing';
    } elseif ($betLeft <= 0) {
        $rowsend['letf_time'] = 0;
        $rowsend['waiting_draw'] = 0;
        $rowsend['draw_status'] = 'sealed';
    } else {
        $rowsend['letf_time'] = $betLeft;
        $rowsend['waiting_draw'] = 0;
        $rowsend['draw_status'] = 'open';
    }
    $rowsend['gyh']=$h.' '.$dx.' '.($h%2==0?'双':'单');
    // 结果等待仅在第三方尚未给出 betting/open 时生效；一旦 source 已开盘，
    // 不允许旧的结果动画 hold 覆盖该事件。
    if (function_exists('feiniao_has_live') && feiniao_has_live($GameType)
        && empty($rowsend['draw_status']) === false
        && $rowsend['draw_status'] !== 'sealed'
        && strtolower((string) (isset($rowsend['live_phase']) ? $rowsend['live_phase'] : '')) !== 'betting'
        && !($rawLeft > 0 && $betLeft <= 0)
        && empty($latchKeepsOpen)
        && function_exists('feiniao_live_post_draw_holding')
        && feiniao_live_post_draw_holding($GameType, $BetTerm['time'], null, $nextAtAbs > 0 ? $nextAtAbs : null)) {
        $rowsend['letf_time'] = 0;
        $rowsend['waiting_draw'] = 1;
        $rowsend['draw_status'] = 'drawing';
        $rowsend['phase_reason'] = 'live_holding';
        $rowsend['next_at'] = 0;
        $nextDisp = trim((string) $rolled['next_term']);
        if ($nextDisp !== '') {
            $rowsend['next_sn'] = formatDisplayTerm($nextDisp);
        }
    }
    // ★ 非 open：清空可下注截止，避免前端继续数字倒数
    $ds = isset($rowsend['draw_status']) ? (string) $rowsend['draw_status'] : '';
    if ($ds !== 'open') {
        $rowsend['letf_time'] = 0;
        $rowsend['bet_close_at_ms'] = 0;
        $rowsend['bet_close_at'] = 0;
        $rowsend['next_at_ms'] = 0;
        if ($ds === 'drawing' || $ds === 'processing' || $ds === 'waiting_live_open'
            || (isset($rowsend['waiting_draw']) && (int) $rowsend['waiting_draw'] === 1)) {
            $rowsend['effective_next_at_ms'] = 0;
            $rowsend['next_at'] = 0;
        }
    }
    $rowsend = feiniao_apply_atm_market_snapshot($rowsend, $GameType, $roomid);
    // 广告消息：合并预读的 msg 字段
    $rowsend = array_merge($rowsend, $msgData);

    // 广告消息已由 bridge timer 统一处理，PHP 端不再重复插入

    return $rowsend;
}

function GetTtime(){
    static $offset = null;
    static $offsetAt = 0;
    $localNow = time();
    // Cache remote clock offset — calling :1323 on every chat poll caused left_time jitter.
    if ($offset !== null && ($localNow - $offsetAt) < 60) {
        return $localNow + (int) $offset;
    }
    $ctx = stream_context_create(array('http' => array('timeout' => 0.4)));
    $data = @file_get_contents('http://127.0.0.1:1323', false, $ctx);
    $remoteNow = $data ? strtotime(trim($data)) : false;
    // 时间守护进程偏差过大时宁用本机时间，否则整站倒计时恒为 0 → 永久封盘/开奖中跳变
    if ($remoteNow !== false && abs($remoteNow - $localNow) <= 10){
        $offset = $remoteNow - $localNow;
        $offsetAt = $localNow;
        return $localNow + (int) $offset;
    }
    $offset = 0;
    $offsetAt = $localNow;
    return $localNow;
}

function getRoomOwnerInfo($roomid = null){
    $roomid = $roomid !== null ? (int)$roomid : (int)(isset($_SESSION['roomid']) ? $_SESSION['roomid'] : 0);
    $nickname = '';
    $avatar = '';
    $files = array(
        dirname(__FILE__) . '/room_owner/' . $roomid . '.json',
        dirname(dirname(__FILE__)) . '/bridge/data/owner_profile_' . $roomid . '.json',
        '/www/wwwroot/feiniao9999/Public/room_owner/' . $roomid . '.json',
        '/www/wwwroot/feiniao9999/h5/Public/room_owner/' . $roomid . '.json',
        '/www/wwwroot/feiniao9999/bridge/data/owner_profile_' . $roomid . '.json',
    );
    if ($roomid > 0) {
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $json = json_decode((string)@file_get_contents($file), true);
            if (!is_array($json)) {
                continue;
            }
            if ($nickname === '') {
                $nickname = trim((string)(isset($json['chatNickname']) ? $json['chatNickname'] : ''));
            }
            if ($avatar === '') {
                $avatar = trim((string)(isset($json['avatar']) ? $json['avatar'] : ''));
            }
            if ($nickname !== '' && $avatar !== '') {
                break;
            }
        }
    }
    if ($nickname === '' && $roomid > 0) {
        $nickname = trim((string) get_query_val('fn_room', 'roomname', array('roomid' => $roomid)));
    }
    if ($nickname === '') {
        $nickname = '群主';
    }
    if ($avatar === '' && $roomid > 0) {
        $avatar = trim((string) get_query_val('fn_setting', 'setting_sysimg', array('roomid' => $roomid)));
    }
    if ($avatar === '' && $roomid > 0) {
        $avatar = trim((string) get_query_val('fn_setting', 'setting_robotsimg', array('roomid' => $roomid)));
    }
    if ($avatar === '') {
        $avatar = '/Style/newimg/laba.png';
    }
    // 割接前保存的房主头像带旧 H5 域名。图片统一由当前站点的 /upload 提供，
    // 否则登录状态不会跨域携带，旧域名会重定向成登录页导致破图。
    $avatar = preg_replace('#^https?://fnh5\.atmyx\.app(/upload/[^?\\#]+)(?:[?\\#].*)?$#i', '$1', $avatar);
    return array('nickname' => $nickname, 'avatar' => $avatar);
}

/**
 * 登录页「历史房间」展示：房间号 + 房间名 + 头像，与房主后台同步。
 * 房间名优先 fn_room.roomname（后台「房间名字」）；头像优先房主资料。
 */
function feiniao_history_room_display($roomid)
{
    $roomid = (int) $roomid;
    $roomname = $roomid > 0
        ? trim((string) get_query_val('fn_room', 'roomname', array('roomid' => $roomid)))
        : '';
    $owner = getRoomOwnerInfo($roomid);
    if ($roomname === '') {
        $roomname = '房间' . $roomid;
    }
    return array(
        'roomid' => $roomid,
        'roomno' => (string) $roomid,
        'name' => $roomname,
        'avatar' => $owner['avatar'],
    );
}

/**
 * 历史房间只记录通过 findroom 门禁后真正进入过的房间。
 * 注册时必须为旧 fn_user 表分配默认 roomid，但那只是账号落库位置，不能当作访问记录。
 */
function feiniao_ensure_user_room_history_table()
{
    static $ready = null;
    if ($ready !== null) return $ready;
    $sql = "CREATE TABLE IF NOT EXISTS `fn_user_room_history` ("
        . "`id` bigint unsigned NOT NULL AUTO_INCREMENT,"
        . "`userid` varchar(64) NOT NULL,"
        . "`roomid` int NOT NULL,"
        . "`entered_at` int NOT NULL,"
        . "PRIMARY KEY (`id`),"
        . "UNIQUE KEY `uniq_user_room` (`userid`,`roomid`),"
        . "KEY `idx_user_entered` (`userid`,`entered_at`)"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $ready = (bool) db_query($sql, true);
    return $ready;
}

function feiniao_record_user_room_entry($userid, $roomid)
{
    $userid = trim((string) $userid);
    $roomid = (int) $roomid;
    if ($userid === '' || $roomid <= 0 || !feiniao_ensure_user_room_history_table()) {
        return false;
    }
    $uid = db_escape_string($userid);
    $now = time();
    return (bool) db_query(
        "INSERT INTO `fn_user_room_history` (`userid`,`roomid`,`entered_at`) VALUES ('{$uid}',{$roomid},{$now}) "
        . "ON DUPLICATE KEY UPDATE `entered_at`=VALUES(`entered_at`)",
        true
    );
}
