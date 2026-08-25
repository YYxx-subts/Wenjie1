<?php
require_once dirname(__DIR__) . '/Public/config.php';
require_once dirname(__DIR__) . '/Public/csrf.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['userid']) || empty($_SESSION['roomid'])) {
    header('Location: /action.php?do=login');
    exit;
}
$roomid = (int)$_SESSION['roomid'];
$images = array();
$url = 'https://wjagent.atmyx.app/api/public/room-promos?roomid=' . urlencode((string)$roomid);
$ch = @curl_init($url);
if ($ch) {
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $body = @curl_exec($ch);
    $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);
    if ($body !== false && $code >= 200 && $code < 300) {
        $json = @json_decode($body, true);
        if (is_array($json) && isset($json['data']['activities'])) {
            foreach ($json['data']['activities'] as $item) {
                if (isset($item['status']) && $item['status'] === 'enabled') {
                    $img = $item['image'] ?? $item['imageUrl'] ?? '';
                    if (!empty($img)) {
                        $images[] = array('image' => $img, 'title' => $item['title'] ?? $item['name'] ?? '');
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover" />
    <meta content="telephone=no" name="format-detection">
    <title>优惠活动</title>
    <link rel="stylesheet" type="text/css" href="/Templates/user/css/common.css?v=1.2" />
    <link rel="stylesheet" type="text/css" href="/Templates/user/css/pcenter.css?v=20260729vip3" />
    <script type="text/javascript" src="/Style/plus.js?v=20260812pageload8"></script>
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
</head>
<body class="pc-body">
<div class="pc-page">
    <header class="pc-nav">
        <button type="button" class="pc-back" onclick="window.history.back()" aria-label="返回">
            <img src="/Style/newimg/leftar.png" alt="">
        </button>
        <h1 class="pc-nav-title">优惠活动</h1>
        <span class="pc-nav-right"></span>
    </header>

    <section style="padding: 12px 16px;">
<?php if (empty($images)): ?>
        <div style="text-align:center;padding:60px 20px;color:#999;font-size:15px;">暂无优惠活动</div>
<?php else: ?>
        <?php foreach ($images as $item): ?>
        <div style="margin-bottom:12px;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
            <img src="<?php echo htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;display:block;" loading="lazy">
        </div>
        <?php endforeach; ?>
<?php endif; ?>
    </section>
</div>
</body>
</html>
