<?php
// 极速弹珠（6）保留 API 轮询兜底：实时推送中断时不能让市场停在上一期。
// 5/7/10 仍按原配置只接收推送，避免跨期回写。
return array('token'=>'2421c3737e1f9f949c49a01deff8b75fdd514c729caffc8273c0a252863ef29e','feeds'=>array(5=>array('push_only'=>true),7=>array('push_only'=>true),10=>array('push_only'=>true)));
