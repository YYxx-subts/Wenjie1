<?php
include_once(dirname(dirname(__FILE__)) . "/Public/config.php");
$game = $_COOKIE['game'];

?>

<?php if ($game == 'pk10') {
    $type = '1';
} elseif ($game == 'xyft') {
    $type = '2';
} elseif ($game == 'cqssc') {
    $type = '3';
} elseif ($game == 'xy28') {
    $type = '4';
} elseif ($game == 'jnd28') {
    $type = '5';
} elseif ($game == 'jsmt') {
    $type = '6';
} else {
    echo '<br><br><strong style="font-size:40px;color:red">该房间已经封盘啦！';
}

select_query("fn_order", '*', "`roomid` = '{$_SESSION['roomid']}' and `userid` = '{$_SESSION['userid']}' and `addtime` like '" . date('Y-m-d') . "%'");

while ($con = db_fetch_array()) {
    $cons[] = $con;
}
$cons = array_reverse($cons);
$allZD = [];
foreach ($cons as $item) {
    if ($item['status'] == '已撤单') continue;
    $row = isset($allZD[$item['term']]) ? $allZD[$item['term']] : ['sn' => $item['term'], 'count' => 0, 'money' => 0, 'earn' => '结算中'];
    $row['count']++;

    if (is_numeric($item['status'])) {
        if (!is_numeric($row['earn'])) $row['earn'] = 0;
        $row['earn'] += $item['status'];
        //$row['money'] += floatval($item['money']);
        $row['money'] +=  $item['status']>0?floatval($item['money']):0;
        //print_r($item['status']);
        //$row['money'] += $item['status']>0?floatval($item['money']):-floatval($item['money']);
    }
    $allZD[$item['term']] = $row;
}


//print_r($allZD);exit;

?>
<?php foreach ($allZD as $item): ?>
    <div class="zhudanwrap">
        <div class="zhudan-row titlehi">
            <li class="for-sn">第<?= $item['sn']; ?>期</li>
            <li class="for-title">收益</li>

        </div>
        <div class="zhudan-row infozhu">
            <li class="for-sn">注单总数：<?= $item['count']; ?></li>
            <li class="for-title"><?= $item['earn']-$item['money']; ?></li>
        </div>
    </div>
<?php endforeach; ?>


