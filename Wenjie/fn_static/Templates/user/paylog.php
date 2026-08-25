<?php
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . "/Public/config.php";
require "function.php";
if (!isset($_SESSION['userid']) || $_SESSION['userid'] === '') {
    header('Location: /action.php?do=login');
    exit;
}

$pageTitle = '申请记录';
$tab = isset($_GET['time']) ? (int)$_GET['time'] : 1;
if ($tab !== 7) {
    $tab = 1;
}

if ($tab === 7) {
    $day = date('Y-m-d', strtotime('-1 day'));
    list($timeFrom, $timeTo) = feiniao_business_day_range_for_date($day);
} else {
    $day = date('Y-m-d');
    list($timeFrom, $timeTo) = feiniao_business_day_range();
}
$dateLabel = $day . ' - ' . $day;

$cons = array();
select_query("fn_upmark", '*', "userid = '{$_SESSION['userid']}' and roomid = {$_SESSION['roomid']} and time between '$timeFrom' and '$timeTo'");
while ($con = db_fetch_array()) {
    $cons[] = $con;
}
$self = 'paylog.php';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover" />
<meta content="telephone=no" name="format-detection">
<title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
<script type="text/javascript" src="/Style/newjs/user-page-lock.js?v=20260812pcenterlock5"></script>
<link rel="stylesheet" href="css/record-list.css?v=20260725h" />
</head>
<body>
<div class="rl-page">
  <header class="rl-nav">
    <button type="button" class="rl-back" onclick="history.back()"><img src="/Style/newimg/leftar.png" alt="返回"></button>
    <div class="rl-nav-title"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></div>
  </header>

  <div class="rl-toolbar">
    <div class="rl-tabs">
      <a class="rl-tab<?php echo $tab === 1 ? ' on' : ''; ?>" href="<?php echo $self; ?>?time=1" onclick="location.replace(this.href);return false;">今日</a>
      <a class="rl-tab<?php echo $tab === 7 ? ' on' : ''; ?>" href="<?php echo $self; ?>?time=7" onclick="location.replace(this.href);return false;">昨日</a>
    </div>
    <div class="rl-range"><?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
  </div>

  <div class="rl-table-wrap">
    <div class="rl-head">
      <span>类型</span>
      <span>时间</span>
      <span>积分</span>
      <span>状态</span>
    </div>
    <div class="rl-list">
      <?php if (count($cons) === 0): ?>
      <div class="rl-empty">
        <svg class="rl-empty-ico" viewBox="0 0 72 72" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <rect x="14" y="10" width="36" height="46" rx="4" stroke="#c8c8c8" stroke-width="2"/>
          <path d="M22 24h20M22 32h20M22 40h12" stroke="#c8c8c8" stroke-width="2" stroke-linecap="round"/>
          <circle cx="48" cy="48" r="12" fill="#f5f6f8" stroke="#c8c8c8" stroke-width="2"/>
          <path d="M56 56l6 6" stroke="#c8c8c8" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <div class="rl-empty-txt">暂无数据~</div>
      </div>
      <?php else: foreach ($cons as $con): ?>
      <div class="rl-row">
        <span><?php echo htmlspecialchars((string)$con['type'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span><?php echo htmlspecialchars((string)$con['time'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span><?php echo htmlspecialchars((string)$con['money'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span><?php echo htmlspecialchars((string)$con['status'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
</body>
</html>
