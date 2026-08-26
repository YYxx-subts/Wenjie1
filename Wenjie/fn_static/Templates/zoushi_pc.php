<?php
include_once(dirname(dirname(__FILE__)) . "/Public/config.php");
$game = $_COOKIE['game'];

?>

<?php if($game == 'jnd28'){
    $type = '5';
}else{
    echo '<br><br><strong style="font-size:40px;color:red">错误！';
}

select_query("fn_open", '*', "type = $type order by id asc");
while($con = db_fetch_array()){
    $cons[] = $con;
}
$data = [];
$cons=array_reverse($cons);
// var_dump($cons);

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

<div class="forecast-row titlehi">
    <li class="for-zoushi1">期数</li>
    <li class="for-zoushi2">号码</li>
    <li class="for-zoushi2">和值</li>
    <li class="for-zoushi2">大</li>
    <li class="for-zoushi2">小</li>
    <li class="for-zoushi2">单</li>
    <li class="for-zoushi2">双</li>
    <li class="for-zoushi2">极大</li>
    <li class="for-zoushi2">极小</li>
</div>
<div class="forecast-row titlehi">
    <li class="for-zoushi1">出现次数</li>
    <li class="for-zoushi2"></li>
    <li class="for-zoushi2"></li>
    <li class="for-zoushi2"><?=$da_count; ?></li>
    <li class="for-zoushi2"><?=$xx_count; ?></li>
    <li class="for-zoushi2"><?=$dd_count; ?></li>
    <li class="for-zoushi2"><?=$ss_count; ?></li>
    <li class="for-zoushi2"><?=$jid_count; ?></li>
    <li class="for-zoushi2"><?=$jix_count; ?></li>
</div>
<?php
foreach ($data as $s):?>
<div class="forecast-row titlehi">
    <li class="for-zoushi1"><?=$s['qishu'];?></li>
    <li class="for-zoushi2"><?=$s['code'];?></li>
    <li class="for-zoushi2"><?=$s['he'];?></li>
    <li class="for-zoushi2"><span style="<?=$s['dred']; ?>"><?=$s['da']; ?></li>
    <li class="for-zoushi2"><span style="<?=$s['xred']; ?>"><?=$s['xx']; ?></li>
    <li class="for-zoushi2"><span style="<?=$s['dared']; ?>"><?=$s['dd']; ?></li>
    <li class="for-zoushi2"><span style="<?=$s['sred']; ?>"><?=$s['ss']; ?></li>
    <li class="for-zoushi2"><span style="<?=$s['jdred']; ?>"><?=$s['jid']; ?></li>
    <li class="for-zoushi2"><span style="<?=$s['jxred']; ?>"><?=$s['jix']; ?></li>
</div>
<?php endforeach;?>

