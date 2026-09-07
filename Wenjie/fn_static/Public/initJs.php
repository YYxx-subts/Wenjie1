<?php
/**
 * 微信 JS-SDK 签名：禁止公网匿名；密钥来自环境变量/配置，禁止硬编码。
 */
include "../Public/config.php";
require_once __DIR__ . "/csrf.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
// 需已登录玩家或房主
if (empty($_SESSION['userid']) && empty($_SESSION['agent_user'])) {
    http_response_code(401);
    echo json_encode(array('success' => false, 'msg' => '未登录'));
    exit;
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'msg' => 'POST only'));
    exit;
}
// 同站 CSRF 或同源
if (!function_exists('feiniao_csrf_validate') || (!feiniao_csrf_validate() && !(function_exists('feiniao_same_origin_ok') && feiniao_same_origin_ok()))) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'msg' => 'CSRF'));
    exit;
}

$Appid = getenv('FEINIAO_WX_APPID') ?: (defined('FEINIAO_WX_APPID') ? FEINIAO_WX_APPID : '');
$Appkey = getenv('FEINIAO_WX_APPSECRET') ?: (defined('FEINIAO_WX_APPSECRET') ? FEINIAO_WX_APPSECRET : '');
if ($Appid === '' || $Appkey === '') {
    echo json_encode(array('success' => false, 'msg' => '微信密钥未配置（请设 FEINIAO_WX_APPID/FEINIAO_WX_APPSECRET）'));
    exit;
}

$url = isset($_POST['url']) ? (string) $_POST['url'] : '';
$parts = @parse_url($url);
$host = is_array($parts) && !empty($parts['host']) ? strtolower((string) $parts['host']) : '';
$reqHost = isset($_SERVER['HTTP_HOST']) ? strtolower((string) $_SERVER['HTTP_HOST']) : '';
if ($host === '' || $reqHost === '' || $host !== preg_replace('/:\d+$/', '', $reqHost)) {
    echo json_encode(array('success' => false, 'msg' => 'url 必须为本站'));
    exit;
}

$sql = get_query_vals('fn_system', '*', array('type' => 1));
$sql_time = $sql ? $sql['content2'] : 0;
if (time() - (int) $sql_time >= 7200) {
    $token = getToken($Appid, $Appkey);
    if (!$token) {
        echo json_encode(array('success' => false, 'msg' => '获取 token 失败'));
        exit;
    }
    update_query("fn_system", array("content1" => $token, 'content2' => time()), array('type' => 1));
} else {
    $token = $sql['content1'];
}
$sql = get_query_vals('fn_system', '*', array('type' => 2));
$sql_time = $sql ? $sql['content2'] : 0;
if (time() - (int) $sql_time >= 7200) {
    $jsapi = getJsapi($token);
    if (!$jsapi) {
        echo json_encode(array('success' => false, 'msg' => '获取 jsapi 失败'));
        exit;
    }
    update_query("fn_system", array("content1" => $jsapi, 'content2' => time()), array('type' => 2));
} else {
    $jsapi = $sql['content1'];
}
$noncestr = getRandStr(15);
$nowtime = time();
$str = "jsapi_ticket={$jsapi}&noncestr={$noncestr}&timestamp={$nowtime}&url={$url}";
$strr = sha1($str);
echo json_encode(array("appId" => $Appid, 'timestamp' => "$nowtime", 'noncestr' => $noncestr, 'signature' => $strr));

function getRandStr($length)
{
    $str = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-';
    $randString = '';
    $len = strlen($str) - 1;
    for ($i = 0; $i < $length; $i++) {
        $num = mt_rand(0, $len);
        $randString .= $str[$num];
    }
    return $randString;
}
function getToken($appid, $appkey)
{
    $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$appkey}";
    $ctx = stream_context_create(array('http' => array('timeout' => 5)));
    $res = @file_get_contents($url, false, $ctx);
    $json = $res ? json_decode($res, true) : null;
    return is_array($json) && !empty($json['access_token']) ? $json['access_token'] : '';
}
function getJsapi($token)
{
    $url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token={$token}&type=jsapi";
    $ctx = stream_context_create(array('http' => array('timeout' => 5)));
    $res = @file_get_contents($url, false, $ctx);
    $json = $res ? json_decode($res, true) : null;
    return is_array($json) && !empty($json['ticket']) ? $json['ticket'] : '';
}
