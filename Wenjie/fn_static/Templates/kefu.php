<!DOCTYPE html>
<html lang="zh-CN">
<head>
<?php if (!function_exists('feiniao_csrf_token')) { require_once dirname(__DIR__) . '/Public/csrf.php'; } ?>
<?php if (!function_exists('getRoomOwnerInfo')) { require_once __DIR__ . '/../Public/config.php'; } ?>
<?php if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); } ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
    <title>在线客服</title>
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/kefu.css?v=20260816kfbj1" />
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
    <link rel="Stylesheet" type="text/css" href="/Style/pop/css/style.css" />
    <script type="text/javascript" src="/Style/pop/js/popups.js"></script>
    <style type="text/css">
        .keyb-input textarea{background: none;border: 0px none;width: 100%;height: 100%;padding: 0rem 0.1rem;box-sizing: border-box;height: 0.6rem;outline: none;resize: none;line-height: 0.5rem}
        /* 未打开系统键盘时，留白在输入栏下方；键盘打开后留白属于系统键盘本身。 */
        .kefu-input-dock{position:absolute!important;left:0;right:0;bottom:0;padding-bottom:max(.34rem, env(safe-area-inset-bottom, 0px))!important;background:#f2f2f2;border-top:1px solid #d9dde2;box-sizing:border-box;}
        .kefu-input-dock.kefu-system-kb-open{padding-bottom:0!important;}
        .chat-input{height:.64rem!important;min-height:.64rem!important;padding:.12rem .18rem!important;box-sizing:border-box;background:#f2f2f2!important;}
        .kefu-input-dock:not(.kefu-system-kb-open) .chat-input{margin-top:.12rem;}
        .chat-input .keyb-input{height:.44rem!important;margin:0 .18rem!important;background:#fff!important;border:1px solid #dedede!important;}
        .chat-input .keyb-input textarea{height:.44rem!important;line-height:.44rem!important;}
        .kefu-back-link{
            display:flex;align-items:center;color:#fff!important;text-decoration:none!important;
            font-size:0.3rem;font-weight:bold;cursor:pointer;-webkit-tap-highlight-color:transparent;
            min-height:0.72rem;padding:0;position:relative;z-index:20;
        }
        .kefu-back-link img{width:0.2rem;margin-right:0.2rem;pointer-events:none;}
    </style>
    <script type="text/javascript">
        var lastChartID=0;
    </script>
<script type="text/javascript" src="/Style/plus.js?v=20260812pageload8"></script>
<script type="text/javascript">try{sessionStorage.removeItem('fn_pending_navigation');}catch(e){}</script>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
</head>
<body>
<div class="mainbox max-width">
    <div class="kefu-topbar">
        <div class="header-hegith roomtop">
            <a class="back kefu-back-link" id="kefuBackLink" href="/action.php?do=gamelist">
                <img src="/Style/newimg/leftar.png" alt=""> 在线客服
            </a>
        </div>
        <?php
        $kefuVipFx = function_exists('feiniao_vip_effects')
            ? feiniao_vip_effects($_SESSION['userid'], $_SESSION['roomid'])
            : array('priorityCs' => false, 'vipLevel' => 0);
        if (!empty($kefuVipFx['priorityCs'])):
        ?>
        <div class="kefu-vip-priority">您是 VIP<?php echo (int)$kefuVipFx['vipLevel']; ?>，享有优先客服接待</div>
        <?php endif; ?>
    </div>

    <div class="chat-content kefu-chat-content<?php echo !empty($kefuVipFx['priorityCs']) ? ' has-vip-tip' : ''; ?>" style="padding-bottom: 1.5rem;width: 100%;">
        <div class="last-show" id="chat_list"></div>
        <div id="chatBt" style="height: 1px"></div>
    </div>

    <div class="kefu-input-dock" style="position: fixed;bottom: 0px;left: 0px;width: 100%;">
        <div id="kefuQuickReplies" class="kefu-quick-replies" style="display:none;padding:6px 8px;background:#f3f6f9;border-top:1px solid #e4ebf0;overflow-x:auto;white-space:nowrap;"></div>
        <input id="kefuCameraInput" class="kefu-hidden-file" type="file" accept="image/*" capture="environment">
        <input id="kefuGalleryInput" class="kefu-hidden-file" type="file" accept="image/png,image/jpeg,image/webp,image/gif">
        <div class="bottom-height chat-input">
            <button type="button" class="kefu-tool-button kefu-media-button" id="kefuMediaBtn" aria-label="拍照或从图库选择"><img src="/Style/newimg/mj-ui/kefu-image.png" alt=""></button>
            <div class="keyb-input"><textarea id="msg" placeholder="输入消息"></textarea></div>
            <button type="button" class="kefu-tool-button kefu-emoji-button" id="kefuEmojiBtn" aria-label="表情"><img src="/Style/newimg/mj-ui/kefu-emoji.png" alt=""></button>
            <button type="button" class="kefu-tool-button kefu-send-button" id="butSend" aria-label="发送"><img src="/Style/newimg/mj-ui/kefu-send.png" alt=""></button>
        </div>
        <div id="kefuMediaMenu" class="kefu-media-menu" hidden>
            <button type="button" id="kefuChoosePhoto"><span class="kefu-media-card"><img src="/Style/newimg/mj-ui/admin-album.png" alt=""></span><b>相册</b></button>
            <button type="button" id="kefuTakePhoto"><span class="kefu-media-card is-camera"><img src="/Style/newimg/mj-ui/kefu-image.png" alt=""></span><b>拍摄</b></button>
        </div>
        <div id="kefuEmojiPanel" class="kefu-emoji-panel" hidden></div>
    </div>
</div>
<script type = "text/javascript">
    var headimg = "<?php echo $_SESSION['headimg'];?>";
    var nickname = "<?php echo $_SESSION['username']?>";
    var kefuRoomId = <?php echo json_encode((string)(int)$_SESSION['roomid']); ?>;
    var kefuUserId = <?php echo json_encode((string)$_SESSION['userid']); ?>;
</script>
<script src="/Style/Old/js/kefu.js?v=20260821faqautosend1"></script>

</body>
</html>
