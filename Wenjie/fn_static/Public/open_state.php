<?php
/** 开奖发布闸门：fn_open 保存号码，fn_open_state 记录结算/公告是否完成。 */
function feiniao_open_state_table_ready()
{
    static $ready = null;
    if ($GLOBALS['feiniao_open_state_table_ready'] ?? false) return true;
    if ($ready !== null) return $ready;
    $ready = false;
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) return false;
    $result = @$GLOBALS['D']->query("SHOW TABLES LIKE 'fn_open_state'");
    if ($result) {
        $ready = $result->num_rows > 0;
        $result->free();
    }
    return $ready;
}

function feiniao_open_state_ensure_table()
{
    // 热路径禁止 DDL：仅检查表是否存在（由 migrate_schema.php 创建）
    return feiniao_open_state_table_ready();
}

function feiniao_open_state_get($typeId)
{
    if (!feiniao_open_state_table_ready()) return null;
    $typeId = (int)$typeId;
    $result = @$GLOBALS['D']->query("SELECT * FROM `fn_open_state` WHERE `type` = {$typeId} LIMIT 1");
    if (!$result) return null;
    $row = $result->fetch_assoc();
    $result->free();
    return $row ?: null;
}

function feiniao_open_state_set($typeId, $issue, $nextTerm, $status, $lastError = '')
{
    if (!feiniao_open_state_table_ready()) return false;
    $typeId = (int)$typeId;
    $issue = db_real_escape_string((string)$issue);
    $nextTerm = db_real_escape_string((string)$nextTerm);
    $status = db_real_escape_string((string)$status);
    $lastError = db_real_escape_string((string)$lastError);
    $now = date('Y-m-d H:i:s');
    $published = $status === 'published' ? "'{$now}'" : 'NULL';
    $sql = "INSERT INTO `fn_open_state` (`type`,`issue`,`next_term`,`status`,`updated_at`,`published_at`,`last_error`)
        VALUES ({$typeId},'{$issue}','{$nextTerm}','{$status}','{$now}',{$published},'{$lastError}')
        ON DUPLICATE KEY UPDATE `issue`=VALUES(`issue`),`next_term`=VALUES(`next_term`),`status`=VALUES(`status`),`updated_at`=VALUES(`updated_at`),`published_at`=VALUES(`published_at`),`last_error`=VALUES(`last_error`)";
    return (bool)@$GLOBALS['D']->query($sql);
}

/** 写入第三方开盘信号（atmkai marketState / market_term） */
function feiniao_open_state_set_market($typeId, $marketTerm, $marketState)
{
    if (!feiniao_open_state_table_ready()) {
        return false;
    }
    $typeId = (int) $typeId;
    $marketTerm = db_real_escape_string((string) $marketTerm);
    $marketState = db_real_escape_string(strtolower(trim((string) $marketState)));
    $now = date('Y-m-d H:i:s');
    $sql = "INSERT INTO `fn_open_state` (`type`,`issue`,`next_term`,`status`,`market_state`,`market_term`,`updated_at`)
        VALUES ({$typeId},'','','processing','{$marketState}','{$marketTerm}','{$now}')
        ON DUPLICATE KEY UPDATE `market_state`=VALUES(`market_state`),`market_term`=VALUES(`market_term`),`updated_at`=VALUES(`updated_at`)";
    return (bool) @$GLOBALS['D']->query($sql);
}

/**
 * 采集器写入的直播相位快照（文件缓存，供顶栏热路径毫秒级读取，不打第三方）。
 * phase=publishing|betting；betting 时 next_at=AtmKai drawAt。
 * 同期限死字段（换期才清零；短暂 publishing 不得重置）：
 * - betting_since / betting_since_ms：该期首次 betting 墙钟（只写一次）
 * - visual_opened：该期画面闸门已放过一次则永久 1
 * - 历史 frozen_* 字段会在每次采集时清理，不能参与当前倒计时。
 */
function feiniao_live_phase_cache_path($typeId)
{
    // 必须落在站点目录：采集器(CLI)与 php-fpm 共用，避免 /tmp 用户隔离读不到
    $dir = __DIR__ . '/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/live-phase-' . (int) $typeId . '.json';
}

function feiniao_live_phase_terms_same($a, $b)
{
    $a = trim((string) $a);
    $b = trim((string) $b);
    if ($a === '' || $b === '') {
        return false;
    }
    if (function_exists('feiniao_terms_loosely_equal')) {
        return feiniao_terms_loosely_equal($a, $b);
    }
    return $a === $b;
}

function feiniao_live_phase_cache_set($typeId, $phase, $nextTerm, $nextAt, $marketState = '', $nextAtMs = 0)
{
    $typeId = (int) $typeId;
    $nextAt = (int) $nextAt;
    $nextAtMs = (int) $nextAtMs;
    if ($nextAtMs <= 0 && $nextAt > 0) {
        $nextAtMs = $nextAt * 1000;
    }
    if ($nextAt <= 0 && $nextAtMs > 0) {
        $nextAt = (int) floor($nextAtMs / 1000);
    }
    $phase = strtolower(trim((string) $phase));
    $nextTerm = (string) $nextTerm;
    $marketState = strtolower(trim((string) $marketState));
    $now = time();
    $nowMs = (int) round(microtime(true) * 1000);

    $prev = null;
    $path = feiniao_live_phase_cache_path($typeId);
    if (is_file($path)) {
        $prevRaw = @file_get_contents($path);
        if ($prevRaw) {
            $tmp = json_decode($prevRaw, true);
            if (is_array($tmp)) {
                $prev = $tmp;
            }
        }
    }

    $sameTerm = is_array($prev) && feiniao_live_phase_terms_same(
        isset($prev['next_term']) ? $prev['next_term'] : '',
        $nextTerm
    );

    $bettingSince = 0;
    $bettingSinceMs = 0;
    $visualOpened = 0;
    $frozenLag = 0;
    $frozenNextAtMs = 0;

    // 同期只保留「何时首次 betting」和画面遥测；绝不能保留旧 API 开奖点/lag。
    // 第三方会校正 drawAt，保留较大的旧值会抵消本房 fengtime，造成顶部看似未封盘。
    if ($sameTerm && is_array($prev)) {
        $bettingSince = isset($prev['betting_since']) ? (int) $prev['betting_since'] : 0;
        $bettingSinceMs = isset($prev['betting_since_ms']) ? (int) $prev['betting_since_ms'] : 0;
        $visualOpened = !empty($prev['visual_opened']) ? 1 : 0;
    }

    if ($phase === 'betting') {
        if ($bettingSince <= 0) {
            $bettingSince = $now;
            $bettingSinceMs = $nowMs;
        }
        if ($marketState === '') {
            $marketState = 'open';
        }
    } elseif ($sameTerm) {
        // publishing 短暂回写：仅在本次没有时间时借上一笔原始时间，不做最大值棘轮。
        if ($nextAtMs <= 0 && is_array($prev) && !empty($prev['next_at_ms'])) {
            $nextAtMs = (int) $prev['next_at_ms'];
            $nextAt = (int) floor($nextAtMs / 1000);
        }
    }
    // 换期：上面未进入 sameTerm，闩锁已为 0 —— 正确清零

    $payload = array(
        'type' => $typeId,
        'phase' => $phase,
        'next_term' => $nextTerm,
        'next_at' => $nextAt,
        'next_at_ms' => $nextAtMs,
        'market_state' => $marketState,
        'betting_since' => $bettingSince,
        'betting_since_ms' => $bettingSinceMs,
        'visual_opened' => $visualOpened,
        // 兼容旧读取端：明确清空历史冻结字段。
        'frozen_lag_sec' => 0,
        'frozen_next_at_ms' => 0,
        'frozen_api_next_at_ms' => 0,
        'ts' => $now,
        'ts_ms' => $nowMs,
    );
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @file_put_contents($path, $json, LOCK_EX);
    }
    @unlink($tmp);
    return $payload;
}

/**
 * 直播开盘闸门：唯一事实源是第三方盘口快照。
 * 播放器画面可能缓冲、卡顿或停在 Result，不能参与资金、消息和倒计时状态。
 */
function feiniao_live_visual_open_gate($liveCache, $lagSec = 0)
{
    $out = array('ready' => false, 'wait_left' => 0, 'betting_since' => 0, 'lag' => max(0, (int) $lagSec), 'latched' => 0);
    if (!is_array($liveCache)) {
        return $out;
    }
    $out['betting_since'] = isset($liveCache['betting_since']) ? (int) $liveCache['betting_since'] : 0;
    $phase = strtolower(trim((string) (isset($liveCache['phase']) ? $liveCache['phase'] : '')));
    $market = strtolower(trim((string) (isset($liveCache['market_state']) ? $liveCache['market_state'] : '')));
    $nextAtMs = !empty($liveCache['next_at_ms']) ? (int) $liveCache['next_at_ms']
        : (!empty($liveCache['next_at']) ? (int) $liveCache['next_at'] * 1000 : 0);
    if ($phase === 'betting' && $market === 'open' && $nextAtMs > ((int) round(microtime(true) * 1000) + 1000)) {
        $out['ready'] = true;
        $out['latched'] = 0;
        $out['wait_left'] = 0;
        return $out;
    }
    return $out;
}

/**
 * 闩锁 visual_opened=1（同期限死，防反复等待）。
 */
function feiniao_live_phase_latch_visual_opened($typeId)
{
    $typeId = (int) $typeId;
    $path = feiniao_live_phase_cache_path($typeId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return null;
    }
    if (!empty($data['visual_opened'])) {
        return $data;
    }
    $data['visual_opened'] = 1;
    $data['ts'] = time();
    $data['ts_ms'] = (int) round(microtime(true) * 1000);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @file_put_contents($path, $json, LOCK_EX);
    }
    @unlink($tmp);
    return $data;
}

/**
 * 兼容旧调用：清理历史冻结字段，不再冻结 API 开奖点和流延迟。
 * 两者必须跟随最新采集结果；旧的大值会抵消 fengtime 并导致倒计时跳变。
 */
function feiniao_live_phase_freeze_deadline($typeId, $betCloseMs, $lagSec, $apiNextAtMs = 0)
{
    $typeId = (int) $typeId;
    $path = feiniao_live_phase_cache_path($typeId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return null;
    }
    $lagSec = (int) $lagSec;
    $apiNextAtMs = (int) $apiNextAtMs;
    $betCloseMs = (int) $betCloseMs;
    // 仅作观测：始终写入「按本次 fengtime 算出的截止」，不参与棘轮覆盖
    if ($betCloseMs > 0) {
        $data['last_bet_close_ms'] = $betCloseMs;
    }
    // 清除历史错误冻结，避免旧包继续读 frozen_bet_close 覆盖 fengtime
    unset($data['frozen_bet_close_ms'], $data['frozen_lag_sec'], $data['frozen_next_at_ms'], $data['frozen_api_next_at_ms']);
    $data['ts'] = time();
    $data['ts_ms'] = (int) round(microtime(true) * 1000);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @file_put_contents($path, $json, LOCK_EX);
    }
    @unlink($tmp);
    return $data;
}

/**
 * 直播本房可下注截止：单一公式
 *   api_next（最新第三方 drawAt）− 本房当前 fengtime
 * 不读取任何历史冻结值：封盘秒变更和上游时间校正必须立即生效。
 */
function feiniao_live_bet_close_ms($apiNextAtMs, $lagSec, $fengtimeSec, $liveCache = null)
{
    $apiNextAtMs = (int) $apiNextAtMs;
    // 视频延迟只影响观看画面，绝不能改写资金盘口的绝对开奖点。
    $lagSec = 0;
    $fengtimeSec = max(0, (int) $fengtimeSec);
    unset($liveCache);
    if ($apiNextAtMs <= 0) {
        return array(
            'api_next_at_ms' => 0,
            'stream_lag_sec' => $lagSec,
            'effective_next_at_ms' => 0,
            'bet_close_at_ms' => 0,
        );
    }
    $effective = $apiNextAtMs;
    $close = max(0, $effective - ($fengtimeSec * 1000));
    return array(
        'api_next_at_ms' => $apiNextAtMs,
        'stream_lag_sec' => $lagSec,
        'effective_next_at_ms' => $effective,
        'bet_close_at_ms' => $close,
    );
}

function feiniao_live_phase_cache_get($typeId, $maxAge = 20)
{
    $path = feiniao_live_phase_cache_path($typeId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $ts = isset($data['ts']) ? (int) $data['ts'] : 0;
    if ($ts <= 0 || (time() - $ts) > max(3, (int) $maxAge)) {
        return null;
    }
    return $data;
}

/** 仅刷新 ts，供后处理生图期间保活，防止顶栏误判缓存过期 */
function feiniao_live_phase_cache_touch($typeId)
{
    $path = feiniao_live_phase_cache_path((int) $typeId);
    if (!is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return false;
    }
    $data['ts'] = time();
    return (bool) @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * 读取直播相位文件（不校验新鲜度）。仅用于「已开盘闩锁恢复」取 API/lag，禁止单独当开盘条件。
 */
function feiniao_live_phase_cache_peek($typeId)
{
    $path = feiniao_live_phase_cache_path($typeId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * 本房本期开盘闩锁：一旦确认开盘，在 bet_close 前缓存抖动不得退回开奖中。
 * 文件只持久化 api_next + lag（只增）；bet_close 始终用当前房 fengtime 重算。
 */
function feiniao_room_open_latch_path($typeId, $roomid, $term)
{
    $dir = __DIR__ . '/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $termKey = preg_replace('/\W+/', '', (string) $term);
    return $dir . '/room-open-' . (int) $typeId . '-' . (int) $roomid . '-' . $termKey . '.json';
}

function feiniao_room_open_latch_get($typeId, $roomid, $term)
{
    $typeId = (int) $typeId;
    $roomid = (int) $roomid;
    $term = trim((string) $term);
    if ($typeId <= 0 || $roomid <= 0 || $term === '') {
        return null;
    }
    $path = feiniao_room_open_latch_path($typeId, $roomid, $term);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return null;
    }
    $latchTerm = trim((string) (isset($data['term']) ? $data['term'] : ''));
    if ($latchTerm !== '' && function_exists('feiniao_live_phase_terms_same')
        && !feiniao_live_phase_terms_same($latchTerm, $term)) {
        return null;
    }
    $api = 0;
    if (!empty($data['api_next_at_ms'])) {
        $api = (int) $data['api_next_at_ms'];
    }
    $lag = isset($data['lag_sec']) ? (int) $data['lag_sec'] : 0;
    if ($lag < 0) {
        $lag = 0;
    }
    if ($api <= 0) {
        return null;
    }
    // 过期清理：有效开奖点过后 10 分钟
    $eff = $api + ($lag * 1000);
    if ($eff > 0 && (int) round(microtime(true) * 1000) > ($eff + 600000)) {
        @unlink($path);
        return null;
    }
    $data['api_next_at_ms'] = $api;
    $data['lag_sec'] = $lag;
    $data['term'] = $latchTerm !== '' ? $latchTerm : $term;
    $data['type'] = $typeId;
    $data['roomid'] = $roomid;
    return $data;
}

/**
 * 写入/棘轮更新本房开盘闩锁（api/lag 只增不减；不写死 bet_close）。
 */
function feiniao_room_open_latch_set($typeId, $roomid, $term, $apiNextAtMs, $lagSec)
{
    $typeId = (int) $typeId;
    $roomid = (int) $roomid;
    $term = trim((string) $term);
    $apiNextAtMs = (int) $apiNextAtMs;
    $lagSec = max(0, (int) $lagSec);
    if ($typeId <= 0 || $roomid <= 0 || $term === '' || $apiNextAtMs <= 0) {
        return null;
    }
    $prev = feiniao_room_open_latch_get($typeId, $roomid, $term);
    // 闩锁只用于缓存缺失恢复，不能把过期的更大值覆盖实时采集值。
    $api = $apiNextAtMs;
    $lag = $lagSec;
    $payload = array(
        'type' => $typeId,
        'roomid' => $roomid,
        'term' => $term,
        'api_next_at_ms' => $api,
        'lag_sec' => $lag,
        'opened_at' => is_array($prev) && !empty($prev['opened_at']) ? (int) $prev['opened_at'] : time(),
        'ts' => time(),
        'ts_ms' => (int) round(microtime(true) * 1000),
    );
    $path = feiniao_room_open_latch_path($typeId, $roomid, $term);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @file_put_contents($path, $json, LOCK_EX);
    }
    @unlink($tmp);
    return $payload;
}

/**
 * 从闩锁按当前 fengtime 还原本房截止（缓存缺失时用）。
 */
function feiniao_room_open_latch_clock($latch, $fengtimeSec)
{
    if (!is_array($latch) || empty($latch['api_next_at_ms'])) {
        return null;
    }
    $api = (int) $latch['api_next_at_ms'];
    $lag = isset($latch['lag_sec']) ? (int) $latch['lag_sec'] : 0;
    if (function_exists('feiniao_live_bet_close_ms')) {
        return feiniao_live_bet_close_ms($api, $lag, $fengtimeSec, null);
    }
    $eff = $api + ($lag * 1000);
    return array(
        'api_next_at_ms' => $api,
        'stream_lag_sec' => $lag,
        'effective_next_at_ms' => $eff,
        'bet_close_at_ms' => max(0, $eff - (max(0, (int) $fengtimeSec) * 1000)),
    );
}

/** 第三方是否已对该下一期发出开盘信号（仅 market_state=open 算数，pending 不算） */
function feiniao_thirdparty_market_open($typeId, $nextTerm = '')
{
    // 文件缓存优先：必须 phase=betting 且 market_state=open；期号两侧均存在且相等
    // 采集后处理持锁时 ts 会停更；12s 过期会误判未开盘，顶栏卡死「开奖中」
    $cache = feiniao_live_phase_cache_get((int) $typeId, 45);
    if (is_array($cache) && isset($cache['phase']) && $cache['phase'] === 'betting') {
        $mkt = strtolower(trim((string) (isset($cache['market_state']) ? $cache['market_state'] : '')));
        if ($mkt !== 'open') {
            return false;
        }
        $nextTerm = trim((string) $nextTerm);
        $cacheTerm = trim((string) (isset($cache['next_term']) ? $cache['next_term'] : ''));
        if ($nextTerm === '' || $cacheTerm === '') {
            return false;
        }
        if (function_exists('feiniao_terms_loosely_equal')) {
            return feiniao_terms_loosely_equal($cacheTerm, $nextTerm);
        }
        return $cacheTerm === $nextTerm;
    }

    $st = feiniao_open_state_get((int) $typeId);
    if (!$st) {
        return false;
    }
    $state = strtolower(trim((string) (isset($st['market_state']) ? $st['market_state'] : '')));
    if ($state !== 'open') {
        return false;
    }
    $nextTerm = trim((string) $nextTerm);
    if ($nextTerm === '') {
        return true;
    }
    $marketTerm = trim((string) (isset($st['market_term']) ? $st['market_term'] : ''));
    if ($marketTerm === '') {
        return true;
    }
    if (function_exists('feiniao_terms_loosely_equal')) {
        return feiniao_terms_loosely_equal($marketTerm, $nextTerm);
    }
    return $marketTerm === $nextTerm;
}

/**
 * 是否仍在等待本期结果发布。
 * - 无表/无行：不拦（避免整站永久封盘）
 * - processing 超过 45 秒：交给采集器重试，不能在结算/生图未完成时伪造 published
 */
function feiniao_open_state_is_pending($typeId, $issue, $strict = true)
{
    // 下注闸门：表不存在则 fail-closed（未迁移时禁止放行竞态）
    if (!feiniao_open_state_table_ready()) {
        return $strict;
    }
    $state = feiniao_open_state_get($typeId);
    if (!$state) return false;
    if ((string)$state['issue'] !== (string)$issue) return false;
    if ((string)$state['status'] === 'published') return false;

    $updatedAt = strtotime((string)($state['updated_at'] ?? ''));
    $age = $updatedAt > 0 ? (time() - $updatedAt) : 9999;
    // 下注闸门（strict=true）始终保持 pending，直到采集器明确 published。
    // 展示层：processing 超过 45 秒不再锁死「开奖中」（下一期倒计时由 getOpenInfo 的 left 决定）。
    if ($age >= 45) {
        return $strict;
    }
    return true;
}

/** L-05：跨实例采集租约（MySQL GET_LOCK，主机/容器间有效） */
function feiniao_collector_db_lock($typeId, $timeout = 0)
{
    $typeId = (int) $typeId;
    $timeout = (int) $timeout;
    if ($timeout < 0) {
        $timeout = 0;
    }
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        if (function_exists('db_connect')) {
            @db_connect();
        }
    }
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        return true; // 无 DB 时退回文件锁
    }
    $name = db_real_escape_string('feiniao_collector_' . $typeId);
    $res = @$GLOBALS['D']->query("SELECT GET_LOCK('{$name}', {$timeout}) AS l");
    if (!$res) {
        return true;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return isset($row['l']) && (int) $row['l'] === 1;
}

function feiniao_collector_db_unlock($typeId)
{
    $typeId = (int) $typeId;
    if (empty($GLOBALS['D']) || !($GLOBALS['D'] instanceof mysqli)) {
        return;
    }
    $name = db_real_escape_string('feiniao_collector_' . $typeId);
    @$GLOBALS['D']->query("SELECT RELEASE_LOCK('{$name}')");
}
