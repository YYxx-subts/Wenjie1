<?php
header('Content-Type: application/json; charset=utf-8');
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . "/Public/config.php";
require "function.php";

if (!isset($_SESSION['userid']) || $_SESSION['userid'] === '') {
    echo json_encode(array('ok' => false, 'message' => '未登录'));
    exit;
}

$userid = $_SESSION['userid'];
$roomid = $_SESSION['roomid'];

$start = isset($_GET['start']) ? trim($_GET['start']) : date('Y-m-d');
$end = isset($_GET['end']) ? trim($_GET['end']) : date('Y-m-d');
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';

$startFull = $start . ' 00:00:00';
$endFull = $end . ' 23:59:59';

/* 查询下线会员 */
$downlines = array();
select_query("fn_user", "id, userid, username, remark", "roomid = {$roomid} AND agent = '" . db_escape_string($userid) . "'");
while ($row = db_fetch_array()) {
    if ($keyword !== '' && stripos($row['userid'], $keyword) === false && stripos($row['username'], $keyword) === false && stripos($row['remark'], $keyword) === false) {
        continue;
    }
    $downlines[] = $row;
}

$totalTurnover = 0;
$totalPaid = 0;
$totalUnpaid = 0;
$rows = array();

foreach ($downlines as $dl) {
    $dlUserid = $dl['userid'];
    $stats = feiniao_sum_bets($roomid, $dlUserid, $startFull, $endFull, false);
    $turnover = floatval($stats['liu']);

    /* 读取佣金配置（bridge/data 目录，文件名用数字ID） */
    $dlRate = 0;
    $dlNumericId = intval($dl['id']);
    $bizFile = dirname(dirname(dirname(dirname(__FILE__)))) . "/bridge/data/biz_{$roomid}_commission_{$dlNumericId}_game.json";
    if (is_file($bizFile)) {
        $dj = json_decode(file_get_contents($bizFile), true);
        if (isset($dj['config']['byGame']) && is_array($dj['config']['byGame'])) {
            $sum = 0; $cnt = 0;
            foreach ($dj['config']['byGame'] as $v) {
                if (is_numeric($v)) { $sum += floatval($v); $cnt++; }
            }
            if ($cnt > 0) $dlRate = $sum / $cnt;
        }
    }

    /* 未返佣 = 会员流水 × 返佣比例（配置值是百分比，如0.2代表0.2%，需÷100） */
    $unpaid = round($turnover * $dlRate / 100, 2);
    /* 已返佣 = 0（后台操作返佣后才会有值，暂无返佣记录表） */
    $paid = 0;

    $totalTurnover += $turnover;
    $totalPaid += $paid;
    $totalUnpaid += $unpaid;

    $rows[] = array(
        'username' => $dlUserid,
        'nickname' => $dl['username'],
        'turnover' => $turnover,
        'commissionRate' => $dlRate,
        'commission' => $unpaid,
        'paid' => $paid,
        'unpaid' => $unpaid,
    );
}

/* 按流水降排 */
usort($rows, function($a, $b) { return $b['turnover'] > $a['turnover'] ? 1 : -1; });

echo json_encode(array(
    'ok' => true,
    'data' => array(
        'rows' => $rows,
        'sum' => array(
            'turnover' => $totalTurnover,
            'total' => $totalUnpaid,
            'paid' => $totalPaid,
            'unpaid' => $totalUnpaid,
        )
    )
), JSON_UNESCAPED_UNICODE);
