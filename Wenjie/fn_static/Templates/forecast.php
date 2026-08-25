<?php
include_once(dirname(dirname(__FILE__)) . "/Public/config.php");
$game = $_COOKIE['game'];

?>

<?php if($game == 'pk10'){
    $type = '1';
}elseif($game == 'xyft'){
    $type = '2';
}elseif($game == 'cqssc'){
    $type = '3';
}elseif($game == 'xy28'){
    $type = '4';
}elseif($game == 'jnd28'){
    $type = '5';
}elseif($game == 'jsmt'){
    $type = '6';
}elseif($game == 'jssc'){
    $type = '7';
}elseif($game == 'jsssc'){
    $type = '8';
}elseif($game == 'azxy5'){
    $type = '9';
}elseif($game == 'dzlhc'){
    $type = '10';
}else{
    echo '<br><br><strong style="font-size:40px;color:red">该房间已经封盘啦！';
}

select_query("fn_open", '*', "`type` = '$type' order by `id` desc limit 1");
while($con = db_fetch_array()){
    $cons[] = $con;
}
$lastTerm=$cons[0];
$filePath=dirname(__FILE__).'/forecastlog/'.$game.'.log';
if(file_exists($filePath)){
    $infoStr=file_get_contents($filePath);
    $info=json_decode($infoStr,true);
}else{
    $info=[];
}
if(isset($info['sn'])&&$info['sn']==$lastTerm['term']){
    $listForecast=$info['list'];
}else{
    $dx=['大','小'];
    $ds=['单','双'];
    $num='0123456789';
    $dataArr=str_split($num);
    $listForecast=[];
    for ($i=0;$i<10;$i++){
        $tmpArr=$dataArr;
        shuffle($tmpArr);
        $str=implode('',$tmpArr);
        $start=mt_rand(0,5);
        $getStr=str_split(substr($str,$start,5));
        $getLast=implode(',',$getStr);


        $row=[];
        $row['num']=$getLast;
        $row['dx']=$dx[mt_rand(0,1)];
        $row['ds']=$ds[mt_rand(0,1)];
        $listForecast[$i]=$row;
    }
    $save=[];
    $save['list']=$listForecast;
    $save['sn']=$lastTerm['term'];
    file_put_contents($filePath,json_encode($save));
}

?>

<div class="forecast-row titlehi">
    <li class="for-sn">名称</li>
    <li class="for-title">实时预测</li>
</div>
<?php
$snName=[0=>'冠军',1=>'亚军',2=>'第三名',3=>'第四名',4=>'第五名',5=>'第六名',6=>'第七名',7=>'第八名',8=>'第九名',9=>'第十名'];
foreach ($listForecast as $s=>$vx):?>
<div class="forecast-row titlehi">
    <li class="for-sn"><?=$snName[$s];?></li>
    <li class="for-num"><?=$vx['num'];?></li>
    <li class="for-dx"><span class="<?=$vx['dx']=='大'?'red':'blue';?>"><?=$vx['dx'];?></span></li>
    <li class="for-ds"><span class="<?=$vx['ds']=='双'?'red':'blue';?>"><?=$vx['ds'];?></span> </li>
</div>
<?php endforeach;?>

