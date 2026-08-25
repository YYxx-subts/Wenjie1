<?php
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . "/Public/config.php";
require "function.php";
if (!isset($_SESSION['userid']) || $_SESSION['userid'] === '') {
    header('Location: /action.php?do=login');
    exit;
}

$view = isset($_GET['view']) ? (string)$_GET['view'] : 'mark';
$pageTitle = ($view === 'trade') ? '交易明细' : '积分账变';
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
select_query("fn_marklog", '*', "userid = '{$_SESSION['userid']}' and roomid = {$_SESSION['roomid']} and addtime between '$timeFrom' and '$timeTo'", array('addtime desc', 'id desc'));
while ($con = db_fetch_array()) {
    $cons[] = $con;
}
$self = 'marklog.php?view=' . rawurlencode($view);

function feiniao_marklog_game_name_by_id($id) {
    $names = array(
        1 => '澳洲幸运10', 2 => '幸运飞艇', 3 => '极速飞艇', 4 => '极速赛车', 5 => '弹珠28',
        6 => '极速弹珠', 7 => '弹珠PK10', 8 => '极速时时彩', 9 => '澳洲幸运5', 10 => '弹珠六合彩',
    );
    return isset($names[(int)$id]) ? $names[(int)$id] : '';
}

function feiniao_marklog_game_from_pay_key($payKey) {
    if (!preg_match('/^(?:BET|PAY):([A-Za-z0-9_]+):(\d+)$/', trim((string)$payKey), $matches)) {
        return '';
    }
    $table = $matches[1];
    $orderId = (int)$matches[2];
    $direct = array('fn_sscorder' => 3, 'fn_pcorder' => 5, 'fn_mtorder' => 6, 'fn_jsscorder' => 7, 'fn_jssscorder' => 8, 'fn_azxy5order' => 9, 'fn_lhcorder' => 10);
    if (isset($direct[$table])) {
        return feiniao_marklog_game_name_by_id($direct[$table]);
    }
    if ($table === 'fn_order' && $orderId > 0) {
        $type = (int)get_query_val('fn_order', 'type', 'id = ' . $orderId . ' limit 1');
        return feiniao_marklog_game_name_by_id($type);
    }
    return '';
}

function feiniao_marklog_display_reason($content, $payKey = '') {
    $game = feiniao_marklog_game_from_pay_key($payKey);
    if ($game !== '') {
        return $game;
    }
    $content = trim((string)$content);
    $aliases = array('极速摩托' => '极速弹珠');
    foreach ($aliases as $old => $current) {
        if (mb_strpos($content, $old, 0, 'UTF-8') !== false) return $current;
    }
    for ($id = 1; $id <= 10; $id++) {
        $name = feiniao_marklog_game_name_by_id($id);
        if ($name !== '' && mb_strpos($content, $name, 0, 'UTF-8') !== false) return $name;
    }
    return $content;
}
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
      <a class="rl-tab<?php echo $tab === 1 ? ' on' : ''; ?>" href="<?php echo $self; ?>&time=1" onclick="location.replace(this.href);return false;">今日</a>
      <a class="rl-tab<?php echo $tab === 7 ? ' on' : ''; ?>" href="<?php echo $self; ?>&time=7" onclick="location.replace(this.href);return false;">昨日</a>
    </div>
    <div class="rl-range"><?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></div>
  </div>

  <div class="rl-table-wrap">
    <div class="rl-head">
      <span>类型</span>
      <span>原因</span>
      <span>积分</span>
      <span>时间</span>
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
        <span><?php echo htmlspecialchars(feiniao_marklog_display_reason($con['content'], isset($con['pay_key']) ? $con['pay_key'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
        <span><?php echo htmlspecialchars((string)$con['money'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span><?php echo htmlspecialchars((string)$con['addtime'], ENT_QUOTES, 'UTF-8'); ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
</body>
</html>
