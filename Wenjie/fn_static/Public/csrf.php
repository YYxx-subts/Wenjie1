<?php
/**
 * F-11：Session CSRF 防护
 */
if (!function_exists('feiniao_csrf_token')) {
function feiniao_csrf_token()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($_SESSION['feiniao_csrf']) || !is_string($_SESSION['feiniao_csrf'])) {
        $_SESSION['feiniao_csrf'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['feiniao_csrf'];
}
}

if (!function_exists('feiniao_csrf_validate')) {
function feiniao_csrf_validate($token = null)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $expect = isset($_SESSION['feiniao_csrf']) ? (string) $_SESSION['feiniao_csrf'] : '';
    if ($token === null) {
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (isset($_POST['_csrf'])) {
            $token = $_POST['_csrf'];
        } else {
            $token = '';
        }
    }
    if ($expect === '' || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($expect, (string) $token);
}
}

if (!function_exists('feiniao_same_origin_ok')) {
function feiniao_same_origin_ok()
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
    if ($host === '') {
        return false;
    }
    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $hk) {
        if (empty($_SERVER[$hk])) {
            continue;
        }
        $parts = @parse_url((string) $_SERVER[$hk]);
        if (!is_array($parts) || empty($parts['host'])) {
            continue;
        }
        $h = strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $h .= ':' . $parts['port'];
        }
        if ($h === $host) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('feiniao_require_csrf_post')) {
function feiniao_require_csrf_post($allowSameOriginFallback = false)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'msg' => '请使用 POST'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (feiniao_csrf_validate()) {
        return;
    }
    if ($allowSameOriginFallback && feiniao_same_origin_ok()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('success' => false, 'status' => 0, 'msg' => 'CSRF 校验失败'), JSON_UNESCAPED_UNICODE);
    exit;
}
}

if (!function_exists('feiniao_agent_require_login')) {
function feiniao_agent_require_login()
{
    $user = isset($_SESSION['agent_user']) ? (string) $_SESSION['agent_user'] : '';
    $roomid = isset($_SESSION['agent_room']) ? (string) $_SESSION['agent_room'] : '';
    $pass = isset($_SESSION['agent_pass']) ? (string) $_SESSION['agent_pass'] : '';
    $room = ($roomid !== '') ? get_query_vals('fn_room', 'roomadmin,roompass', array('roomid' => $roomid)) : false;
    if ($user === '' || $roomid === '' || $pass === '' || !is_array($room)
        || !hash_equals((string) (isset($room['roomadmin']) ? $room['roomadmin'] : ''), $user)
        || !hash_equals((string) (isset($room['roompass']) ? $room['roompass'] : ''), $pass)) {
        unset($_SESSION['agent_user'], $_SESSION['agent_room'], $_SESSION['agent_pass']);
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => false, 'msg' => '未登录'), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
}
