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
}elseif($game == 'jnd28'){
    $type = '5';
}else{
    echo '<br><br><strong style="font-size:40px;color:red">该房间已经封盘啦！';
}

select_query("fn_open", '*', "`type` = '$type' order by `id` desc limit 20");
while($con = db_fetch_array()){
    $cons[] = $con;
}
$pos=[];
$row=[];
$row['dx']='';
$row['dx_count']=0;
$row['ds']='';
$row['ds_count']=0;

$cons=array_reverse($cons);

foreach ($cons as $item){
    $opennum=explode(',',$item['code']);
    $gyh=$opennum[0]+$opennum[1];
    unset($opennum[0],$opennum[1]);
    $opennum[0]=$gyh;
    ksort($opennum);
    foreach ($opennum as $index=>$num){
        $lastRow=isset($pos[$index])?$pos[$index]:$row;
        if($num%2==0){
            if($lastRow['ds']=='双'){
                $lastRow['ds_count']++;
            }else $lastRow['ds_count']=1;
            $lastRow['ds']='双';
        }else{
            if($lastRow['ds']=='单'){
                $lastRow['ds_count']++;
            }else $lastRow['ds_count']=1;
            $lastRow['ds']='单';
        }

        $bigNum=($index==0)?10:5;
        if($num>=$bigNum){
            if($lastRow['dx']=='大'){
                $lastRow['dx_count']++;
            }else $lastRow['dx_count']=1;
            $lastRow['dx']='大';
        }else{
            if($lastRow['dx']=='小'){
                $lastRow['dx_count']++;
            }else $lastRow['dx_count']=1;
            $lastRow['dx']='小';
        }

        $pos[$index]=$lastRow;
    }
}
$showLong=[];
foreach ($pos as $index=>$item){
    $row=[];
    if($item['dx_count']>=3){
        $row['lx']=$item['dx'];
        $row['sn']=$index;
        $row['count']=$item['dx_count'];
        $showLong[]=$row;
    }
    if($item['ds_count']>=3){
        $row['lx']=$item['ds'];
        $row['sn']=$index;
        $row['count']=$item['ds_count'];
        $showLong[]=$row;
    }
}
?>
<div class="changlong-row titlehi">
    <li>序号</li>
    <li>位置</li>
    <li>结果</li>
    <li>连期</li>
</div>
<?php
$snName=[0=>'冠亚和',1=>'亚军',2=>'第三名',3=>'第四名',4=>'第五名',5=>'第六名',6=>'第七名',7=>'第八名',8=>'第九名',9=>'第十名'];
$i=1;
foreach ($showLong as $s=>$vx):?>
<div class="changlong-row titlehi">
    <li><?=$i++;?></li>
    <li><?=$snName[$vx['sn']];?></li>
    <li><?=$vx['lx'];?></li>
    <li><?=$vx['count'];?></li>
</div>
<?php endforeach;?>

