<?php
include_once(dirname(dirname(__FILE__)) . "/Public/config.php");
header("Content-Type: text/html; charset=utf-8");
$game = $_COOKIE['game'];

?>
<!--<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />-->
<style>
*{
    box-sizing: border-box;
    -webkit-box-sizing: border-box;
    -moz-box-sizing: border-box;
}
body{
    font-family: Helvetica;
    -webkit-font-smoothing: antialiased;
    /*background: rgba( 71, 147, 227, 1);*/
}
h2{
    text-align: center;
    font-size: 18px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: white;
    padding: 30px 0;
}

/* Table Styles */

.table-wrapper{
    /*margin: 10px 70px 70px;*/
    box-shadow: 0px 35px 50px rgba( 0, 0, 0, 0.2 );
}

.fl-table {
    border-radius: 5px;
    font-size: 12px;
    font-weight: normal;
    border: none;
    /*border-collapse: collapse;*/
    width: 10%;
    width: 100%;
    max-width: 100%;
    white-space: nowrap;
    background-color: white;
}

.fl-table td, .fl-table th {
    text-align: center;
    padding: 8px;
}

.fl-table td {
    border-right: 1px solid #f8f8f8;
    font-size: 12px;
}

.fl-table thead th {
    color: #ffffff;
    background: #4FC3A1;
}


.fl-table thead th:nth-child(odd) {
    color: #ffffff;
    background: #324960;
}

.fl-table tr:nth-child(even) {
    background: #F8F8F8;
}

/* Responsive */

@media (max-width: 767px) {
    .fl-table {
        display: block;
        width: 100%;
    }
    .table-wrapper:before{
        content: "Scroll horizontally >";
        display: block;
        text-align: right;
        font-size: 11px;
        color: white;
        padding: 0 0 10px;
    }
    .fl-table thead, .fl-table tbody, .fl-table thead th {
        display: block;
    }
    .fl-table thead th:last-child{
        border-bottom: none;
    }
    .fl-table thead {
        float: left;
    }
    .fl-table tbody {
        width: auto;
        position: relative;
        overflow-x: auto;
    }
    .fl-table td, .fl-table th {
        padding: 20px .625em .625em .625em;
        height: 60px;
        vertical-align: middle;
        box-sizing: border-box;
        overflow-x: hidden;
        overflow-y: auto;
        width: 120px;
        font-size: 13px;
        text-overflow: ellipsis;
    }
    .fl-table thead th {
        text-align: left;
        border-bottom: 1px solid #f7f7f9;
    }
    .fl-table tbody tr {
        display: table-cell;
    }
    .fl-table tbody tr:nth-child(odd) {
        background: none;
    }
    .fl-table tr:nth-child(even) {
        background: transparent;
    }
    .fl-table tr td:nth-child(odd) {
        background: #F8F8F8;
        border-right: 1px solid #E6E4E4;
    }
    .fl-table tr td:nth-child(even) {
        border-right: 1px solid #E6E4E4;
    }
    .fl-table tbody td {
        display: block;
        text-align: center;
    }
}
</style>
<?php if($game == 'jnd28'){
    $type = '5';
}else{
    echo '<br><br><strong style="font-size:40px;color:red">错误！';
    return;
}

select_query("fn_open", '*', "`type` = '$type' order by `next_time` asc");
while($con = db_fetch_array()){
    $cons[] = $con;
}
$data = [];
$cons=array_reverse($cons);
// var_dump($game);

foreach ($cons as $k=>$y){
    $opennum=explode(',',$y['code']);
    $num=$opennum[0]+$opennum[1]+$opennum[2];
        if($num%2==0){
            $ss_count++;
            $data[$k]['ss']='双';
            $data[$k]['sred'] = 'color:white;border: 7px solid #f44336;border-radius: 50%;background: #f44336;';
        }else{
            $dd_count++;
            $data[$k]['dd']='单';
            $data[$k]['dared'] = 'color:white;border: 7px solid #03a9f4;border-radius: 50%;background: #03a9f4;';
        }

        if($num>=14){
            $da_count++;
            $data[$k]['da']='大';
            $data[$k]['dred'] = 'color:white;border: 7px solid red;border-radius: 50%;background: red;';
        }else{
            $xx_count++;
            $data[$k]['xx']='小';
            $data[$k]['xred'] = 'color:white;border: 7px solid blue;border-radius: 50%;background: blue;';
        }
        if($num>=24){
            $jid_count++;
            $data[$k]['jid'] = '极大';
            $data[$k]['jdred'] = 'color:white;border: 7px solid #ff9800;border-radius: 50%;background: #ff9800;';
        }
        if($num<=3){
            $jix_count++;
            $data[$k]['jix'] = '极小';
            $data[$k]['jxred'] = 'color:white;border: 7px solid #e900ff;border-radius: 50%;background: #e900ff;';
        }
        $data[$k]['he'] = $num;
        $data[$k]['qishu'] = $y['term'];
        $data[$k]['code'] = $y['code'];
        // $data[$k]=$y;

}
// var_dump($xx_count);
// die;
?>




<div class="table-wrapper">
    <table class="fl-table">
        <thead>
        <tr>
            <th>期数</th>
            <th>号码</th>
            <th>和值</th>
            <th>大</th>
            <th>小</th>
            <th>单</th>
            <th>双</th>
            <th>极大</th>
            <th>极小</th>
        </tr>
        </thead>
         
        <tbody>
        
        <tr>
            <th>50期出现次数</th>
            <th></th>
            <th></th>
            <th><?=$da_count; ?></th>
            <th><?=$xx_count; ?></th>
            <th><?=$dd_count; ?></th>
            <th><?=$ss_count; ?></th>
            <th><?=$jid_count; ?></th>
            <th><?=$jix_count; ?></th>
        </tr>
        <?php foreach ($data as $s): ?>
        <tr>
            <td><?=$s['qishu']; ?></td>
            <td><?=$s['code']; ?></td>
            <td><?=$s['he']; ?></td>
            <td><span style="<?=$s['dred']; ?>"><?=$s['da']; ?></span></td>
            <td><span style="<?=$s['xred']; ?>"><?=$s['xx']; ?></span></td>
            <td><span style="<?=$s['dared']; ?>"><?=$s['dd']; ?></span></td>
            <td><span style="<?=$s['sred']; ?>"><?=$s['ss']; ?></span></td>
            <td><span style="<?=$s['jdred']; ?>"><?=$s['jid']; ?></span></td>
            <td><span style="<?=$s['jxred']; ?>"><?=$s['jix']; ?></span></td>
        </tr>
       <?php endforeach; ?> 
       <tbody>
        
    </table>
</div>



