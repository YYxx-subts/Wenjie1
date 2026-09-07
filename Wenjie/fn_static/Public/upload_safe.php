<?php
/**
 * 安全图片落盘：强制真实图像校验 + 白名单扩展名 + 随机文件名。
 * @return array{ok:bool,path?:string,url?:string,msg?:string}
 */
if (!function_exists('feiniao_safe_store_image')) {
function feiniao_safe_store_image($fileKey, $subdir = 'upload', $maxBytes = 2000000)
{
    if (empty($_FILES[$fileKey]) || !is_array($_FILES[$fileKey])) {
        return array('ok' => false, 'msg' => '没有上传文件');
    }
    $f = $_FILES[$fileKey];
    $err = isset($f['error']) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;
    $tmp = isset($f['tmp_name']) ? (string) $f['tmp_name'] : '';
    $size = isset($f['size']) ? (int) $f['size'] : 0;
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        return array('ok' => false, 'msg' => '上传失败');
    }
    if ($size <= 0 || $size > (int) $maxBytes) {
        return array('ok' => false, 'msg' => '图片过大或为空');
    }
    $info = @getimagesize($tmp);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return array('ok' => false, 'msg' => '文件不是有效图片');
    }
    $mime = isset($info['mime']) ? strtolower((string) $info['mime']) : '';
    $map = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    );
    if ($mime === '' || !isset($map[$mime])) {
        return array('ok' => false, 'msg' => '不支持的图片类型');
    }
    $ext = $map[$mime];
    $subdir = trim(str_replace(array('..', '\\'), '', (string) $subdir), '/');
    if ($subdir === '') {
        $subdir = 'upload';
    }
    $root = dirname(__DIR__); // Public/ → 站点根
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return array('ok' => false, 'msg' => '上传目录不可写');
    }
    $name = date('Ymd') . '_' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : openssl_random_pseudo_bytes(8)) . '.' . $ext;
    $abs = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($tmp, $abs)) {
        return array('ok' => false, 'msg' => '保存失败');
    }
    @chmod($abs, 0644);
    $url = '/' . $subdir . '/' . $name;
    return array('ok' => true, 'path' => $abs, 'url' => $url);
}
}
