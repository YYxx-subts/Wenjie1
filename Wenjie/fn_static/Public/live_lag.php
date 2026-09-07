<?php
/**
 * 直播流延迟探测：同源 CDN 的 HLS 分片名墙钟 vs 服务器墙钟。
 * 用于「视频秒 − 顶部秒 ≈ fengtime」：effective_drawAt = API_drawAt + lag。
 */

if (!function_exists('feiniao_live_lag_dir')) {
function feiniao_live_lag_dir()
{
    $dir = __DIR__ . '/runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}
}

if (!function_exists('feiniao_live_lag_path')) {
function feiniao_live_lag_path($typeId)
{
    return feiniao_live_lag_dir() . '/live-lag-' . (int) $typeId . '.json';
}
}

if (!function_exists('feiniao_live_hls_url')) {
function feiniao_live_hls_url($gameId)
{
    if (!function_exists('feiniao_live_flv')) {
        return '';
    }
    $flv = feiniao_live_flv($gameId);
    if ($flv === '') {
        return '';
    }
    // 同路径 FLV → HLS（CDN 实测可用；六合彩可能 404）
    if (preg_match('/\.flv(\?.*)?$/i', $flv)) {
        return preg_replace('/\.flv(\?.*)?$/i', '.m3u8$1', $flv);
    }
    return '';
}
}

if (!function_exists('feiniao_live_lag_default')) {
function feiniao_live_lag_default($typeId = 0)
{
    // 无真实测量时不得虚构 16/18 秒延迟；虚构值会直接抵消房主封盘秒。
    return 0;
}
}

if (!function_exists('feiniao_live_lag_clamp')) {
function feiniao_live_lag_clamp($sec)
{
    $sec = (int) round((float) $sec);
    if ($sec < 0) {
        return 0;
    }
    if ($sec > 35) {
        return 35;
    }
    return $sec;
}
}

if (!function_exists('feiniao_live_lag_read')) {
function feiniao_live_lag_read($typeId, $maxAge = 180)
{
    $path = feiniao_live_lag_path($typeId);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['lag_sec'])) {
        return null;
    }
    $ts = isset($data['ts']) ? (int) $data['ts'] : 0;
    if ($ts > 0 && $maxAge > 0 && (time() - $ts) > (int) $maxAge) {
        // 过期仍返回数值，但标记 stale，调用方可决定是否重测
        $data['stale'] = 1;
    } else {
        $data['stale'] = 0;
    }
    $data['lag_sec'] = feiniao_live_lag_clamp($data['lag_sec']);
    return $data;
}
}

if (!function_exists('feiniao_live_lag_write')) {
function feiniao_live_lag_write($typeId, $payload)
{
    $path = feiniao_live_lag_path($typeId);
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
}

/**
 * 解析 m3u8 内最新带墙钟的分片名，返回 Unix 秒；失败返回 0。
 * 例：...-20260731T092037.ts 或 ..._20260731T092037_....ts
 */
if (!function_exists('feiniao_live_hls_latest_seg_ts')) {
function feiniao_live_hls_latest_seg_ts($m3u8Body)
{
    $body = (string) $m3u8Body;
    if ($body === '') {
        return 0;
    }
    $best = 0;
    if (preg_match_all('/(\d{8})T(\d{6})/', $body, $m)) {
        $n = count($m[0]);
        for ($i = 0; $i < $n; $i++) {
            $ymd = $m[1][$i];
            $hms = $m[2][$i];
            $ts = strtotime(
                substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2)
                . ' ' . substr($hms, 0, 2) . ':' . substr($hms, 2, 2) . ':' . substr($hms, 4, 2)
            );
            if ($ts !== false && $ts > $best) {
                $best = (int) $ts;
            }
        }
    }
    return $best;
}
}

/**
 * 拉取 HLS 并计算 raw lag；失败返回 null。
 */
if (!function_exists('feiniao_live_lag_probe_raw')) {
function feiniao_live_lag_probe_raw($typeId, $timeoutSec = 1.5)
{
    $typeId = (int) $typeId;
    $hls = feiniao_live_hls_url($typeId);
    if ($hls === '') {
        return null;
    }
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => max(0.5, (float) $timeoutSec),
            'header' => "User-Agent: FeiniaoLagProbe/1.0\r\nAccept: */*\r\n",
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
        ),
    ));
    $body = @file_get_contents($hls, false, $ctx);
    if ($body === false || $body === '') {
        return null;
    }
    // 若是主播放列表，再跟一条第一个子列表
    if (stripos($body, '#EXT-X-STREAM-INF') !== false && preg_match('/^(?!#)(\S+\.m3u8\S*)/mi', $body, $mm)) {
        $sub = trim($mm[1]);
        if ($sub !== '' && strpos($sub, 'http') !== 0) {
            $base = preg_replace('#/[^/]*$#', '/', $hls);
            $sub = $base . $sub;
        }
        $body2 = @file_get_contents($sub, false, $ctx);
        if ($body2 !== false && $body2 !== '') {
            $body = $body2;
        }
    }
    $segTs = feiniao_live_hls_latest_seg_ts($body);
    if ($segTs <= 0) {
        return null;
    }
    $now = function_exists('GetTtime') ? (int) GetTtime() : time();
    $raw = $now - $segTs;
    // 分片名若用 UTC 而服务器用 CST，可能偏 ±8h；明显离谱则丢弃
    if ($raw < -5 || $raw > 120) {
        return null;
    }
    return array(
        'raw' => feiniao_live_lag_clamp($raw),
        'seg_ts' => $segTs,
        'hls' => $hls,
        'now' => $now,
    );
}
}

/**
 * 探测并 EMA 写入；返回最终 lag_sec。
 */
if (!function_exists('feiniao_live_lag_probe_and_store')) {
function feiniao_live_lag_probe_and_store($typeId, $timeoutSec = 1.5)
{
    $typeId = (int) $typeId;
    $prev = feiniao_live_lag_read($typeId, 0);
    $probe = feiniao_live_lag_probe_raw($typeId, $timeoutSec);
    $default = feiniao_live_lag_default($typeId);
    if ($probe === null) {
        if (is_array($prev) && isset($prev['lag_sec'])) {
            return (int) $prev['lag_sec'];
        }
        $payload = array(
            'type' => $typeId,
            'lag_sec' => $default,
            'raw' => $default,
            'source' => 'default_cold',
            'ts' => time(),
            'ts_ms' => (int) round(microtime(true) * 1000),
        );
        feiniao_live_lag_write($typeId, $payload);
        return $default;
    }
    $raw = (int) $probe['raw'];
    $prevLag = is_array($prev) && isset($prev['lag_sec']) ? (int) $prev['lag_sec'] : $raw;
    // EMA：允许上下校正。只增不减会把历史延迟永久叠在倒计时上。
    $smoothed = (int) round($prevLag * 0.65 + $raw * 0.35);
    $smoothed = feiniao_live_lag_clamp($smoothed);
    $payload = array(
        'type' => $typeId,
        'lag_sec' => $smoothed,
        'raw' => $raw,
        'seg_ts' => (int) $probe['seg_ts'],
        'hls' => (string) $probe['hls'],
        'source' => 'hls_segment',
        'ts' => time(),
        'ts_ms' => (int) round(microtime(true) * 1000),
    );
    feiniao_live_lag_write($typeId, $payload);
    return $smoothed;
}
}

/**
 * 业务读取：有效流延迟秒数（失败用默认 18）。
 */
if (!function_exists('feiniao_live_stream_lag_sec')) {
function feiniao_live_stream_lag_sec($typeId)
{
    $typeId = (int) $typeId;
    if ($typeId <= 0 || !function_exists('feiniao_has_live') || !feiniao_has_live($typeId)) {
        return 0;
    }
    $data = feiniao_live_lag_read($typeId, 300);
    $lag = feiniao_live_lag_default($typeId);
    if (is_array($data) && isset($data['lag_sec'])) {
        $lag = feiniao_live_lag_clamp($data['lag_sec']);
    }
    return feiniao_live_lag_clamp($lag);
}
}

/**
 * 解析直播流延迟：只使用最新探测值，禁止历史 frozen_lag 叠加。
 */
if (!function_exists('feiniao_resolve_live_stream_lag')) {
function feiniao_resolve_live_stream_lag($typeId, $liveCache = null)
{
    // 保留接口兼容旧调用；直播画面延迟不参与开盘、封盘、结算时钟。
    unset($typeId, $liveCache);
    return 0;
}
}
