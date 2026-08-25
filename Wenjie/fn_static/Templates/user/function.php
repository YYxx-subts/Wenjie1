<?php
function getinfo($userid){
    return feiniao_getinfo($userid);
}
function getextend($userid, $time){
    $liushui = 0;
    $yk = 0;
    $money = 0;
    $sf = 0;
    $time = explode(' - ', $time);
    if(count($time) == 2){
        $ordersql_from = $time[0];
        $ordersql_to = $time[1];
        $marksql = " and (`time` between '{$time[0]}' and '{$time[1]}')";
    }else{
        list($ordersql_from, $ordersql_to) = feiniao_business_day_range();
        $marksql = '';
    }
    $cons = array();
    select_query("fn_user", '*', "roomid = {$_SESSION['roomid']} and agent = '{$userid}'");
    while($con = db_fetch_array()){
        $cons[] = $con;
    }
    foreach($cons as $con){
        $stats = feiniao_sum_bets($_SESSION['roomid'], $con['userid'], $ordersql_from, $ordersql_to, false);
        $l = (int)$stats['liu'];
        $y = $stats['yk'];
        $s = get_query_val('fn_upmark', 'sum(`money`)', "roomid = {$_SESSION['roomid']} and userid = '{$con['userid']}' and `status` = '已处理'" . $marksql);
        $liushui += ($l);
        $yk += ($y);
        $money += $con['money'];
        $sf += $s;
    }
    $arr = array('liu' => $liushui, 'yk' => sprintf("%.2f", $yk), 'money' => $money, 'pay' => $sf);
    return $arr;
}
function getorder($userid, $time){
    $useBizRange = false;
    $bizFrom = $bizTo = '';
    $time2 = date('Y-m-d');
    switch($time){
        case 1:
            list($bizFrom, $bizTo) = feiniao_business_day_range();
            $useBizRange = true;
            break;
        case 7:
            list($bizFrom, $bizTo) = feiniao_business_day_range_for_date(date('Y-m-d', strtotime('-1 day')));
            $useBizRange = true;
            break;
        case 30:
            $bizFrom = date('Y-m-d', strtotime('-30 day')) . ' 07:00:00';
            list(, $bizTo) = feiniao_business_day_range();
            $useBizRange = true;
            break;
        default:
            $time = date('Y-m-d');
            list($bizFrom, $bizTo) = feiniao_business_day_range();
            $useBizRange = true;
            break;
    }
    $id = $userid;
    $allmoney = 0;
    $allstatus = 0;
    $cons = array();
    $data = array('data' => array());
    $from = $useBizRange ? $bizFrom : ($time . ' 00:00:00');
    $to = $useBizRange ? $bizTo : ($time2 . ' 23:59:59');

    foreach (feiniao_order_tables() as $tb) {
        select_query($tb, '*', "roomid = '{$_SESSION['roomid']}' and userid = '{$id}' and (`addtime` between '{$from}' and '{$to}')");
        while ($con = db_fetch_array()) {
            $game = feiniao_order_table_game_name($tb, $con);
            $cons[] = $con;
            if ($con['status'] != '已撤单' && $con['status'] != '未结算') $allmoney += (int)$con['money'];
            if ($con['status'] > 0) $allstatus += $con['status'];
            $bet = (isset($con['mingci']) && $con['mingci'] !== '' && $con['mingci'] !== null)
                ? ($con['mingci'] . '/' . $con['content'])
                : $con['content'];
            $data['data'][] = array('#' . $con['id'], $con['username'], $game, $con['term'], $bet, $con['money'], $con['addtime'], $con['status']);
        }
    }
    if (count($cons) == 0){
        $data['data'][] = null;
    }
    $allstatus = $allstatus - $allmoney;
    $data['allmoney'] = sprintf("%.2f", $allmoney);
    $data['allstatus'] = sprintf("%.2f", $allstatus);
    return $data;
}
function getxia($userid){
    $allmoney = 0;
    $allliu = 0;
    $allyk = 0;
    $alls = 0;
    $cons = array();
    list($from, $to) = feiniao_business_day_range();
    select_query("fn_user", '*', "roomid = {$_SESSION['roomid']} and agent = '{$userid}'");
    while($con = db_fetch_array()){
        $cons[] = $con;
    }
    $arr = array('data' => array());
    foreach($cons as $con){
        $stats = feiniao_sum_bets($_SESSION['roomid'], $con['userid'], $from, $to, false);
        $liushui = (int)$stats['liu'];
        $yk = sprintf("%.2f", $stats['yk']);
        $allyk += $stats['yk'];
        $allliu += $liushui;
        $s = get_query_val('fn_upmark', 'sum(`money`)', "roomid = {$_SESSION['roomid']} and userid = '{$con['userid']}' and `status` = '已处理'");
        $alls += $s;
        $arr['data'][] = array($con['id'], "<img src='{$con['headimg']}' style='width:30px;height:30px'> ", $con['username'], $con['money'], "$liushui", $yk, $s == "" ? '0.00' : $s, date('Y-m-d H:i:s', $con['statustime']));
        $allmoney += $con['money'];
    }
    $arr['allmoney'] = sprintf("%.2f", $allmoney);
    $arr['allyk'] = sprintf("%.2f", $allyk);
    $arr['alls'] = $alls;
    $arr['allliu'] = $allliu;
    return $arr;
}
?>
