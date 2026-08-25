<?php
include_once(dirname(dirname(__FILE__)) . "/Public/config.php");
$game = isset($_COOKIE['game']) ? $_COOKIE['game'] : '';

if ($game != 'jnd28') {
    echo '<br><br><strong style="font-size:40px;color:red">该房间已经封盘啦！';
    return;
}

$type = '5';
$moreHref = '/action.php?do=open-results&game=jnd28';

if (!function_exists('feiniao_pc28_wave_class')) {
    function feiniao_pc28_wave_class($n) {
        $n = (int) $n;
        if ($n === 0 || $n === 13 || $n === 14 || $n === 27) {
            return 'pc-y';
        }
        $m = $n % 3;
        if ($m === 1) {
            return 'pc-g';
        }
        if ($m === 2) {
            return 'pc-b';
        }
        return 'pc-r';
    }
}
if (!function_exists('feiniao_pc28_ball')) {
    function feiniao_pc28_ball($n, $extra = '') {
        $n = (int) $n;
        if ($n < 0) $n = 0;
        $extraCls = ($extra !== '' ? ' ' . $extra : '');
        if ($n === 0) {
            return '<span class="lhc-ball lhc-n00 pc28-ball' . $extraCls . '">0</span>';
        }
        if ($n > 49) $n = 49;
        $pad = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        return '<span class="lhc-ball lhc-n' . $pad . ' pc28-ball' . $extraCls . '">' . $pad . '</span>';
    }
}
?>

<div class="history-row titlehi is-pc28">
    <li class="col-qh">期数</li>
    <li class="col-num">号码</li>
    <li class="col-gy">开奖结果</li>
</div>
<?php
select_query("fn_open", '*', "`type` = '$type' order by `id` desc limit 7");
$cons = array();
while ($con = db_fetch_array()) {
    $cons[] = $con;
}
foreach ($cons as $con) {
    $g = explode(",", $con['code']);
    $n1 = isset($g[0]) ? (int) $g[0] : 0;
    $n2 = isset($g[1]) ? (int) $g[1] : 0;
    $n3 = isset($g[2]) ? (int) $g[2] : 0;
    $count = $n1 + $n2 + $n3;
    $dans = ($count % 2 === 1) ? '单' : '双';
    $daxiao = ($count < 14) ? '小' : '大';
    $txtClass = ($daxiao === '大') ? 'pc28-txt-da' : 'pc28-txt-xiao';
    $term = function_exists('formatDisplayTerm') ? formatDisplayTerm($con['term']) : $con['term'];
    $eq =
        feiniao_pc28_ball($n1) .
        '<span class="pc28-op">+</span>' .
        feiniao_pc28_ball($n2) .
        '<span class="pc28-op">+</span>' .
        feiniao_pc28_ball($n3) .
        '<span class="pc28-op">=</span>' .
        feiniao_pc28_ball($count, 'is-sum');
    ?>
    <div class="history-row is-pc28">
        <li class="col-qh"><?= htmlspecialchars((string) $term, ENT_QUOTES, 'UTF-8'); ?></li>
        <li class="col-num numrow"><?= $eq; ?></li>
        <li class="col-gy">
            <?= feiniao_pc28_ball($count, 'is-sum'); ?>
            <span class="<?= $txtClass; ?>"><?= $count; ?>, <?= $daxiao; ?>, <?= $dans; ?></span>
        </li>
    </div>
<?php } ?>
<a class="fn-history-more" href="<?= htmlspecialchars($moreHref, ENT_QUOTES, 'UTF-8'); ?>">查看更多</a>
