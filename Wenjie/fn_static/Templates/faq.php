<?php
include_once dirname(__DIR__) . '/Public/config.php';
if (empty($_SESSION['roomid']) || empty($_SESSION['userid'])) {
    header('Location: /Templates/login.php');
    exit;
}
$roomid = (int) $_SESSION['roomid'];
$items = function_exists('feiniao_biz_list_json') ? feiniao_biz_list_json($roomid, 'faqs') : array();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, viewport-fit=cover" />
    <title>常见问题</title>
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css" />
    <style>
      body{background:#f5f7fa;margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
      .faq-top{display:flex;align-items:center;height:48px;padding:0 12px;background:linear-gradient(90deg,#02d4fd,#23a5fd);color:#fff;font-weight:700;}
      .faq-top a{color:#fff;text-decoration:none;margin-right:10px;}
      .faq-list{padding:12px;}
      .faq-item{background:#fff;margin-bottom:10px;padding:12px 14px;border-radius:8px;}
      .faq-item h3{margin:0 0 8px;font-size:15px;color:#17324d;}
      .faq-item p{margin:0;font-size:13px;color:#5a6b73;line-height:1.5;white-space:pre-wrap;}
      .faq-empty{text-align:center;color:#99a;padding:40px 12px;}
    </style>
</head>
<body>
<div class="faq-top">
  <a href="javascript:history.back()">‹</a>
  常见问题
</div>
<div class="faq-list">
<?php if (!$items): ?>
  <div class="faq-empty">暂无常见问题，请联系在线客服</div>
<?php else: ?>
  <?php foreach ($items as $item):
    $title = trim((string)($item['title'] ?? $item['keyword'] ?? $item['question'] ?? ''));
    $content = trim((string)($item['content'] ?? $item['answer'] ?? ''));
    if ($title === '' && $content === '') continue;
  ?>
  <div class="faq-item">
    <h3><?= htmlspecialchars($title !== '' ? $title : '问题', ENT_QUOTES, 'UTF-8'); ?></h3>
    <p><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8'); ?></p>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</body>
</html>
