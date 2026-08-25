<?php
include_once(dirname(dirname(__FILE__)) . "/Public/config.php");
$game = isset($_COOKIE['game']) ? (string)$_COOKIE['game'] : '';

$gameTypeMap = array(
    'pk10' => '1',
    'xyft' => '2',
    'cqssc' => '3',
    'xy28' => '4',
    'jnd28' => '5',
    'jsmt' => '6',
    'jssc' => '7',
    'jsssc' => '8',
    'azxy5' => '9',
    'dzlhc' => '10',
);
if (!isset($gameTypeMap[$game])) {
    echo '<br><br><strong style="font-size:40px;color:red">该房间已经封盘啦！';
    return;
}
$type = $gameTypeMap[$game];
$kind = 'pk10';
if ($game === 'jnd28') $kind = 'pc28';
elseif ($game === 'jsssc' || $game === 'azxy5') $kind = 'ssc';
elseif ($game === 'dzlhc') $kind = 'lhc';

$ys = array('小' => 'blue', '大' => 'gray', '单' => 'blue', '双' => 'gray', '龙' => 'gray', '虎' => 'blue');
$moreHref = '/action.php?do=open-results&game=' . rawurlencode($game);

select_query("fn_open", '*', "`type` = '{$type}' order by `id` desc limit 7");
$cons = array();
while ($con = db_fetch_array()) {
    $cons[] = $con;
}

if ($kind === 'ssc') {
?>
<style>
.history-row.is-ssc .col-qh,.history-row.titlehi.is-ssc .col-qh{flex:1 1 0!important;width:auto!important;min-width:0;text-align:center;font-size:0.24rem}
.history-row.is-ssc .col-num.numrow,.history-row.titlehi.is-ssc .col-num.numrow{flex:2 1 0!important;width:auto!important;min-width:0;justify-content:center;align-items:center;gap:0.06rem}
.history-row.is-ssc .col-gy-ssc{flex:1 1 0!important;width:auto!important;min-width:0;text-align:center;font-size:0.24rem}
.history-row.titlehi.is-ssc .col-gy{flex:1 1 0!important;width:auto!important;min-width:0;text-align:center;font-size:0.24rem}
.history-row.titlehi.is-ssc .col-num.numrow span{font-size:0.24rem!important;letter-spacing:0.15rem}
.history-row.is-ssc .ssc-ball{width:0.48rem!important;height:0.44rem!important;flex:0 0 0.48rem!important;border-radius:50%!important}
.history-row.is-ssc{font-size:0.24rem;padding:0.08rem 0;border-bottom:1px solid #ECECEC;box-sizing:border-box}
.history-row.titlehi.is-ssc{border-bottom:2px solid #DEDEDE;padding:0.1rem 0}
</style>
<div class="history-row titlehi is-ssc">
    <li class="col-qh">期号</li>
    <li class="col-num numrow"><span>一</span><span>二</span><span>三</span><span>四</span><span>五</span></li>
    <li class="col-gy">开奖结果</li>
</div>
<?php
    foreach ($cons as $con) {
        $g = explode(",", (string)$con['code']);
        $balls = array();
        for ($i = 0; $i < 5; $i++) {
            $balls[] = isset($g[$i]) ? (int)$g[$i] : 0;
        }
        $sum = $balls[0] + $balls[1] + $balls[2] + $balls[3] + $balls[4];
        $dx = ($sum >= 23) ? '大' : '小';
        $ds = (($sum % 2) === 0) ? '双' : '单';
        $lh = ($balls[0] > $balls[4]) ? '龙' : (($balls[0] < $balls[4]) ? '虎' : '和');
        $term = function_exists('formatDisplayTerm') ? formatDisplayTerm($con['term']) : $con['term'];
        ?>
    <div class="history-row is-ssc">
        <li class="col-qh"><?= htmlspecialchars((string)$term, ENT_QUOTES, 'UTF-8'); ?></li>
        <li class="col-num numrow">
            <?php foreach ($balls as $n) { ?>
            <span class="ssc-ball n<?= $n; ?>"><?= $n; ?></span>
            <?php } ?>
        </li>
        <li class="col-gy col-gy-ssc">
            <span class="<?= $ys[$dx]; ?>"><?= $dx; ?>，</span>
            <span class="<?= $ys[$ds]; ?>"><?= $ds; ?>，</span>
            <span class="<?= $ys[$lh]; ?>"><?= $lh; ?></span>
        </li>
    </div>
<?php
    }
    echo '<a class="fn-history-more" href="' . htmlspecialchars($moreHref, ENT_QUOTES, 'UTF-8') . '">查看更多</a>';
    return;
}

if ($kind === 'pc28') {
    echo '<br><br><strong style="font-size:20px;color:#666">请使用弹珠28历史开奖面板</strong>';
    return;
}

if ($kind === 'lhc') {
?>
<div class="history-row titlehi is-lhc">
    <li class="col-qh">期号</li>
    <li class="col-num numrow"><span>正一</span><span>正二</span><span>正三</span><span>正四</span><span>正五</span><span>正六</span><span>+</span><span>特</span></li>
    <li class="col-gy">特码</li>
</div>
<?php
    foreach ($cons as $con) {
        $g = explode(",", (string)$con['code']);
        $balls = array();
        for ($i = 0; $i < 7; $i++) {
            $balls[] = isset($g[$i]) ? (int)$g[$i] : 0;
        }
        $tema = $balls[6];
        $dx = ($tema >= 25) ? '大' : '小';
        $ds = (($tema % 2) === 0) ? '双' : '单';
        $term = function_exists('formatDisplayTerm') ? formatDisplayTerm($con['term']) : $con['term'];
        ?>
    <div class="history-row is-lhc">
        <li class="col-qh"><?= htmlspecialchars((string)$term, ENT_QUOTES, 'UTF-8'); ?></li>
        <li class="col-num numrow">
            <?php for ($i = 0; $i < 6; $i++) {
                $pad = str_pad((string)$balls[$i], 2, '0', STR_PAD_LEFT);
            ?>
            <span class="lhc-ball lhc-n<?= $pad; ?>"><?= $pad; ?></span>
            <?php } ?>
            <span class="lhc-plus">+</span>
            <?php $pad = str_pad((string)$balls[6], 2, '0', STR_PAD_LEFT); ?>
            <span class="lhc-ball lhc-n<?= $pad; ?>"><?= $pad; ?></span>
        </li>
        <li class="col-gy col-gy-lhc">
            <span class="count"><?= $tema; ?></span>
            <span class="<?= $ys[$dx]; ?>"><?= $dx; ?></span>
            <span class="<?= $ys[$ds]; ?>"><?= $ds; ?></span>
        </li>
    </div>
<?php
    }
    echo '<a class="fn-history-more" href="' . htmlspecialchars($moreHref, ENT_QUOTES, 'UTF-8') . '">查看更多</a>';
    return;
}
?>
<div class="history-row titlehi">
    <li class="col-qh">期号</li>
    <li class="col-num numrow"><span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>七</span><span>八</span><span>九</span><span>十</span></li>
    <li class="col-gy">冠亚和</li>
    <li class="col-lh">1-5龙虎</li>
</div>
<?php
foreach ($cons as $con) {
    $g = explode(",", (string)$con['code']);
    for ($i = 0; $i < 10; $i++) {
        if (!isset($g[$i])) $g[$i] = 0;
        $g[$i] = (int)$g[$i];
    }
    $h = array();
    $h['冠亚'] = $g[0] + $g[1];
    $h['冠亚大小'] = ($h['冠亚'] > 11) ? '大' : '小';
    $h['冠亚单双'] = (($h['冠亚'] % 2) == 0) ? '双' : '单';
    $adb = array();
    $adb[] = ($g[0] > $g[9]) ? '龙' : '虎';
    $adb[] = ($g[1] > $g[8]) ? '龙' : '虎';
    $adb[] = ($g[2] > $g[7]) ? '龙' : '虎';
    $adb[] = ($g[3] > $g[6]) ? '龙' : '虎';
    $adb[] = ($g[4] > $g[5]) ? '龙' : '虎';
    $term = function_exists('formatDisplayTerm') ? formatDisplayTerm($con['term']) : $con['term'];
    ?>
    <div class="history-row">
        <li class="col-qh"><?= htmlspecialchars((string)$term, ENT_QUOTES, 'UTF-8'); ?></li>
        <li class="col-num numrow">
            <?php for ($i = 0; $i < 10; $i++) { ?>
            <span class="ball_pks_  n<?= $g[$i]; ?> ball_lenght10" title="<?= $g[$i]; ?>"><?= $g[$i]; ?></span>
            <?php } ?>
        </li>
        <li class="col-gy">
            <span class="count"><?= $h['冠亚']; ?></span>
            <span class="<?= $ys[$h['冠亚大小']]; ?>"><?= $h['冠亚大小']; ?></span>
            <span class="<?= $ys[$h['冠亚单双']]; ?> g_r_line g_td_p_right"><?= $h['冠亚单双']; ?></span>
        </li>
        <li class="col-lh">
            <?php foreach ($adb as $v) { ?>
            <span class="<?= $ys[$v]; ?>"><?= $v; ?></span>
            <?php } ?>
        </li>
    </div>
<?php } ?>
<a class="fn-history-more" href="<?= htmlspecialchars($moreHref, ENT_QUOTES, 'UTF-8'); ?>">查看更多</a>
