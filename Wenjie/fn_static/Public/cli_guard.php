<?php
/**
 * CLI / Token 门禁：采集、危险运维脚本禁止公网匿名触发。
 * - CLI（含 PM2 interpreter php）直接放行
 * - HTTP 仅允许 POST，且令牌只能来自 Header X-Feiniao-Token 或 POST body
 *   （禁止 GET：避免写操作被缓存/预取触发，也避免令牌进查询串日志）
 */
if (!function_exists('feiniao_require_cli_or_token')) {
function feiniao_require_cli_or_token($envKey = 'FEINIAO_COLLECTOR_TOKEN', $label = 'collector')
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        header('Allow: POST');
        echo 'Forbidden: ' . $label . ' HTTP requires POST';
        exit;
    }
    $expect = getenv($envKey);
    if ($expect === false || $expect === null) {
        $expect = '';
    }
    $expect = (string) $expect;
    $got = '';
    if (!empty($_SERVER['HTTP_X_FEINIAO_TOKEN'])) {
        $got = (string) $_SERVER['HTTP_X_FEINIAO_TOKEN'];
    } elseif (isset($_POST['token'])) {
        $got = (string) $_POST['token'];
    }
    if ($expect !== '' && $got !== '' && hash_equals($expect, $got)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: ' . $label . ' requires CLI or valid token (header/POST only)';
    exit;
}
}
