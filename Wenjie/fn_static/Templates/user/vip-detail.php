<?php
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . "/Public/config.php";
if (!isset($_SESSION['userid']) || $_SESSION['userid'] === '') {
    header('Location: /action.php?do=login');
    exit;
}
$levels = feiniao_vip_default_levels();
$intro = '会员需同时达到本房间对应的有效投注流水和充值积分门槛才能晋升 VIP 等级；达到相应等级可获得晋级彩金、周礼金等特权。VIP 礼金到账积分也会计入充值积分统计。晋级彩金需在达级后 31 天内领取，逾期失效。VIP 等级按房间隔离。';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover" />
<title>详情</title>
<script type="text/javascript" src="/Style/newjs/user-page-lock.js?v=20260812pcenterlock5"></script>
<link rel="stylesheet" href="css/vip-detail.css?v=20260817scroll1" />
</head>
<body class="vd-body">
<header class="vd-nav">
  <button type="button" class="vd-back" onclick="history.back()" aria-label="返回">
    <img src="/Style/newimg/leftar.png" alt="">
  </button>
  <h1>详情</h1>
</header>
<main class="vd-main">
  <section class="vd-card">
    <div class="vd-title">
      <i></i><span>等级详情</span><i></i>
    </div>
    <p class="vd-intro"><?php echo htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'); ?></p>
    <div class="vd-table-wrap">
      <table class="vd-table">
        <thead>
          <tr>
            <th>等级</th>
            <th>爵位</th>
            <th>充值</th>
            <th>流水</th>
            <th>彩金</th>
            <th>周礼金</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($levels as $row):
            $dep = isset($row['deposit']) ? (float)$row['deposit'] : 0;
            $liu = isset($row['turnover']) ? (float)$row['turnover'] : (float)$row['threshold'];
            $up = (float)$row['upgradeBonus'];
            $wk = (float)$row['weeklyGift'];
            $fmt = function ($n) {
                $n = (float)$n;
                if ($n <= 0) return '—';
                if (abs($n - round($n)) < 1e-6) return (string)(int)round($n);
                return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            };
        ?>
          <tr>
            <td>VIP<?php echo (int)$row['level']; ?></td>
            <td><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo $fmt($dep); ?></td>
            <td><?php echo $fmt($liu); ?></td>
            <td><?php echo $fmt($up); ?></td>
            <td><?php echo $fmt($wk); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="vd-foot">特权解锁：VIP0 徽章 → VIP10 彩金 → VIP30 优先客服 → VIP40 周礼金 → VIP50 头像框/昵称高亮 → VIP60 进房特效 → VIP70 匿名投注 → VIP80 节日礼包。</p>
  </section>
</main>
</body>
</html>
