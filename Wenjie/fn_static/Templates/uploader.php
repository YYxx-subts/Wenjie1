<?php
/**
 * 头像上传：优先写入站点已有的 /upload/avatar/（与后台机器人头像同根目录），
 * 失败再尝试 Templates/header；并尽量同步到 h5 镜像目录。
 */
if (!isset($_SESSION['userid']) || $_SESSION['userid'] === '') {
    echo json_encode(array("error" => "请先登录", 'status' => 0));
    exit;
}

if (empty($_FILES) || empty($_FILES['file'])) {
    echo json_encode(array("error" => "您还未选择图片", 'status' => 0));
    exit;
}

$typeArr = array('png', 'jpg', 'jpeg', 'gif', 'webp');
$name = isset($_FILES['file']['name']) ? (string)$_FILES['file']['name'] : '';
$size = isset($_FILES['file']['size']) ? (int)$_FILES['file']['size'] : 0;
$name_tmp = isset($_FILES['file']['tmp_name']) ? (string)$_FILES['file']['tmp_name'] : '';
$err = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;

if ($err !== UPLOAD_ERR_OK || $name_tmp === '' || !is_uploaded_file($name_tmp)) {
    $msg = '您还未选择图片';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        $msg = '图片过大，请压缩后重试';
    } elseif ($err === UPLOAD_ERR_NO_FILE) {
        $msg = '您还未选择图片';
    } elseif ($err !== UPLOAD_ERR_OK) {
        $msg = '上传失败，错误码:' . $err;
    }
    echo json_encode(array("error" => $msg, 'status' => 0));
    exit;
}

$type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if ($type === '' || !in_array($type, $typeArr, true)) {
    // App 拍照常见无扩展名，按 MIME 推断
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $name_tmp);
            @finfo_close($fi);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($name_tmp);
    }
    $map = array(
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    );
    if (isset($map[$mime])) {
        $type = $map[$mime];
    }
}
if (!in_array($type, $typeArr, true)) {
    echo json_encode(array("error" => "请上传 png/jpg/jpeg/gif/webp 图片", 'status' => 0));
    exit;
}
if ($size > 5 * 1024 * 1024) {
    echo json_encode(array("error" => "图片大小已超过5M！", 'status' => 0));
    exit;
}

// S-08：真实图像校验（防伪造扩展名脚本）
$imgInfo = @getimagesize($name_tmp);
if ($imgInfo === false || empty($imgInfo[0]) || empty($imgInfo[1])) {
    echo json_encode(array("error" => "文件不是有效图片", 'status' => 0));
    exit;
}
$imgMime = isset($imgInfo['mime']) ? strtolower((string) $imgInfo['mime']) : '';
$mimeOk = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp');
if ($imgMime === '' || !isset($mimeOk[$imgMime])) {
    echo json_encode(array("error" => "不支持的图片类型", 'status' => 0));
    exit;
}
$type = $mimeOk[$imgMime];

$codeRoot = dirname(__DIR__); // action.php / Templates 所在站点根（可能是 h5/）
$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], "/\\") : '';
$candidates = array();

// 优先：与 Agent 一致的 upload 目录（线上通常已可写）
foreach (array_unique(array_filter(array($docRoot, $codeRoot))) as $base) {
    $candidates[] = array(
        'abs' => $base . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'avatar',
        'url' => '/upload/avatar',
    );
    $candidates[] = array(
        'abs' => $base . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'header',
        'url' => '/Templates/header',
    );
}

// 若当前在 h5/ 下，也尝试父级 upload（部分部署双目录）
$parent = dirname($codeRoot);
if ($parent && is_dir($parent . DIRECTORY_SEPARATOR . 'upload')) {
    $candidates[] = array(
        'abs' => $parent . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'avatar',
        'url' => '/upload/avatar',
    );
}
if ($parent && basename($codeRoot) === 'h5') {
    $candidates[] = array(
        'abs' => $parent . DIRECTORY_SEPARATOR . 'h5' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'avatar',
        'url' => '/upload/avatar',
    );
}

function feiniao_ensure_writable_dir($absDir)
{
    if (!is_dir($absDir)) {
        @mkdir($absDir, 0755, true);
    }
    if (!is_dir($absDir)) {
        return false;
    }
    @chmod($absDir, 0755);
    if (is_writable($absDir)) {
        return true;
    }
    // 二次探测：真实写文件（部分环境 is_writable 不可靠）
    $probe = $absDir . DIRECTORY_SEPARATOR . '.wprobe_' . getmypid();
    $ok = @file_put_contents($probe, '1') !== false;
    if ($ok) {
        @unlink($probe);
        return true;
    }
    return false;
}

$chosen = null;
foreach ($candidates as $c) {
    if (feiniao_ensure_writable_dir($c['abs'])) {
        $chosen = $c;
        break;
    }
}

if (!$chosen) {
    echo json_encode(array("error" => "头像目录不可写，请在服务器执行: mkdir -p upload/avatar && chmod -R 777 upload", 'status' => 0));
    exit;
}

$header_name = date('YmdHis') . '_' . mt_rand(10000, 99999) . '.' . $type;
$absFile = $chosen['abs'] . DIRECTORY_SEPARATOR . $header_name;
if (!@move_uploaded_file($name_tmp, $absFile)) {
    // 部分环境 move 失败时尝试 copy
    if (!@copy($name_tmp, $absFile)) {
        echo json_encode(array('error' => '上传失败，无法写入文件', 'status' => 0));
        exit;
    }
    @unlink($name_tmp);
}
@chmod($absFile, 0644);

// 同步到常见镜像路径，避免根目录 / h5 双份部署时 404
$mirrors = array();
if ($docRoot !== '') {
    $mirrors[] = $docRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $chosen['url']), DIRECTORY_SEPARATOR);
}
$mirrors[] = $codeRoot . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $chosen['url']), DIRECTORY_SEPARATOR);
if (basename($codeRoot) !== 'h5' && is_dir($codeRoot . DIRECTORY_SEPARATOR . 'h5')) {
    $mirrors[] = $codeRoot . DIRECTORY_SEPARATOR . 'h5' . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $chosen['url']), DIRECTORY_SEPARATOR);
}
if (basename($codeRoot) === 'h5') {
    $mirrors[] = dirname($codeRoot) . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $chosen['url']), DIRECTORY_SEPARATOR);
}
foreach (array_unique($mirrors) as $mirrorDir) {
    if ($mirrorDir === $chosen['abs']) {
        continue;
    }
    if (!is_dir($mirrorDir)) {
        @mkdir($mirrorDir, 0777, true);
    }
    if (is_dir($mirrorDir)) {
        @copy($absFile, $mirrorDir . DIRECTORY_SEPARATOR . $header_name);
    }
}

$headimg = rtrim($chosen['url'], '/') . '/' . $header_name;
$userid = (string)$_SESSION['userid'];
update_query("fn_user", array("headimg" => $headimg), array('userid' => $userid));
$_SESSION['headimg'] = $headimg;
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $_SESSION['user']['headimg'] = $headimg;
}

echo json_encode(array('header' => $headimg, 'status' => 1, 'msg' => '上传成功'));
exit;
