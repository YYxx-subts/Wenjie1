<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#5babeb" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <link rel="icon" type="image/png" href="/favicon.png?v=20260727a" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20260727a" />
    <title>问界</title>

    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/roomdoor.css?v=20260803pagelock1" />
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
    <script type="text/javascript" src="/Style/newjs/room-overscroll.js?v=20260803pagelock1"></script>
<link rel="Stylesheet" type="text/css" href="/Style/pop/css/style.css" />
    <script type="text/javascript" src="/Style/pop/js/popups.js"></script>
    <script type="text/javascript">
        window.onerror=function (x,y,z) {
            //alert(x+"=="+y+"=="+z);
        }
    </script>
    <style type="text/css">
        input{ime-mode:disabled;}
    </style>
<script type="text/javascript" src="/Style/plus.js?v=20260812pageload9"></script>
<meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
</head>
<body>
<script>
(function(){
  try{
    var root=document.documentElement;
    var probe=document.createElement("div");
    probe.style.cssText="position:fixed;top:0;left:0;height:env(safe-area-inset-top);width:0;visibility:hidden;pointer-events:none;";
    document.documentElement.appendChild(probe);
    var inset=probe.getBoundingClientRect().height||0;
    probe.remove();
    if(inset<1){
      // 多数 App WebView 不注入 safe-area，用状态栏高度兜底让蓝底盖住顶部
      inset=Math.round((window.devicePixelRatio>2?48:32));
    }
    root.style.setProperty("--fn-status-bar", inset+"px");
  }catch(e){}
})();
</script>
<div class="mainbox max-width">
    <div class="roomtop" style="flex-shrink: 0">
        <img class="roomtop-bg" src="/Style/newimg/ic_game_list_top_bg.webp?v=20260724x" alt="">
        <div class="userinfo">
            <p><img style="height: 0.72rem!important;width: 0.72rem!important;" src="<?=$_SESSION['headimg'];?>" onerror="this.onerror=null;this.src='/Style/newimg/laba.png'"></p>
            <p>
                <span><?=$_SESSION['username'];?></span>
            </p>
        </div>
        <div class="top-btn">
            <li onclick="window.location.href='/Templates/user/setting.php'"><img class="bgimg" src="/Style/newimg/setting-bg.png"><span><img src="/Style/newimg/setting.png">个人设置</span></li>
            <li id="logout"><img class="bgimg" src="/Style/newimg/exit-bg.png"><span><img src="/Style/newimg/exit.png">安全退出</span></li>
        </div>
    </div>
    <div class="roomdoor-body">
    <div class="roomnum-row" style="flex-shrink: 0; display: flex; justify-content: center; gap: 0.15rem;">
    <div class="roomnum referral-tab" id="tabRoom" style="flex-shrink: 0; opacity:1;" onclick="switchInputMode('room')"><img src="/Style/newimg/roomnum.png" style="width: auto!important;height: 0.6rem!important;"><span>房间号</span></div>
    <div class="roomnum referral-tab" id="tabReferral" style="flex-shrink: 0; opacity:0.6;" onclick="switchInputMode('referral')"><img src="/Style/newimg/roomnum.png" style="width: auto!important;height: 0.6rem!important;"><span>推荐码</span></div>
    </div>
    <div class="room-num-input" id="numinput" style="flex-shrink: 0">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" enterkeyhint="done" value="">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" enterkeyhint="done" value="">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" enterkeyhint="done" value="">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" enterkeyhint="done" value="">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" enterkeyhint="done" value="">
        <input type="tel" inputmode="numeric" pattern="[0-9]*" maxlength="1" autocomplete="off" enterkeyhint="done" value="">
    </div>
    <div class="referral-input-wrap" id="referralInputWrap" style="flex-shrink: 0; display:none; margin: 0 0.5rem;">
        <input type="text" id="referralInput" class="referral-oval-input" placeholder="请输入房间推荐码" autocomplete="off" enterkeyhint="done" value="">
    </div>
    <div class="room-in" id="loginroom" style="flex-shrink: 0"><img src="/Style/newimg/ic_login_btn_bg.png"><span>进入房间</span></div>
    <div class="room-history" style="flex-shrink: 0">
        <div class="history-title">历史房间:</div>

        <div class="or-ptr" id="historyPtr">
            <div class="or-ptr-icon">
                <span class="or-ptr-arrow">↑</span>
                <div class="or-ptr-spinner"></div>
            </div>
            <div class="or-ptr-info">
                <span class="or-ptr-text">下拉刷新</span>
                <span class="or-ptr-sub" id="historyPtrTime"></span>
            </div>
        </div>

        <div class="listroom" id="historyList">
            <?php
            $histUid = db_escape_string((string)$_SESSION['userid']);
            $histSeen = array();
            $histCount = 0;
            // 只显示门禁校验通过后真实进入过的房间；注册落到默认房间的账号行不算访问记录。
            if (feiniao_ensure_user_room_history_table()) {
                select_query("fn_user_room_history", 'roomid', "`userid` = '{$histUid}' order by `entered_at` desc limit 30");
                while ($con = db_fetch_array()) {
                $rid = (int)$con['roomid'];
                if ($rid <= 0 || isset($histSeen[$rid])) {
                    continue;
                }
                // 房间已删则不展示
                if (!get_query_val('fn_room', 'id', array('roomid' => $rid))) {
                    continue;
                }
                $histSeen[$rid] = 1;
                $card = feiniao_history_room_display($rid);
                if ($card['roomid'] <= 0) {
                    continue;
                }
                $histCount++;
                if ($histCount > 8) {
                    break;
                }
                $avatarEsc = htmlspecialchars($card['avatar'], ENT_QUOTES, 'UTF-8');
                $nameEsc = htmlspecialchars($card['name'], ENT_QUOTES, 'UTF-8');
                $noEsc = htmlspecialchars($card['roomno'], ENT_QUOTES, 'UTF-8');
                ?>
                    <li class="roomrow" data-roomid="<?= (int)$card['roomid']; ?>">
                    <p class="room-avatar" data-roomid="<?= (int)$card['roomid']; ?>"><span class="del-badge">−</span><img src="<?= $avatarEsc; ?>" alt="" loading="eager" decoding="async" onerror="this.onerror=null;this.src='/Style/newimg/laba.png'"></p>
                    <p class="room-no"><?= $noEsc; ?></p>
                    <p class="room-name"><?= $nameEsc; ?></p>
                </li>
                <?php
                }
            }
            ?>

        </div>

    </div>
    </div>
    <!--<div class="btn-bg"><img src="/Style/newimg/loginbt-dark.png"></div>-->
</div>
<!--<div class="footer_menu">
    <li data-url="/action.php?do=kefu"><img src="/Style/newimg/m_icon_4.png"></li>
    <li data-url="/Templates/user/"><img src="/Style/newimg/m_icon_2.png"></li>
    <li data-url=""><img src="/Style/newimg/m_icon_3.png"></li>
    <li data-url="/Templates/user/setting.php"><img src="/Style/newimg/m_icon_1.png"></li>
</div>-->
<script type="text/javascript">
    function feiniaoCsrf() {
        return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    }
    $('.footer_menu li').on('click',function () {
        var url=$(this).data('url');
        window.location.href=url;
    });
    $(document).on('click', '.roomrow[data-roomid]', function () {
        var avatar = this.querySelector('.room-avatar');
        if (avatar && avatar.classList.contains('show-del')) return;
        var roomid=$(this).data('roomid');
        try { this.blur(); } catch(e) {}
        if (window.fnShowPageLoading) window.fnShowPageLoading('循环加载');
        $.ajax({
            url:'action.php?do=findroom',
            dataType:'json',
            type:'post',
            headers: { 'X-CSRF-TOKEN': feiniaoCsrf() },
            data:{'room':roomid, '_csrf': feiniaoCsrf()},
            success:function (res) {
                if(res.status==1){
                    // 房间校验成功即可进入大厅，不能再人为阻塞一秒。
                    if (window.fnNavigateAfterPrefetch) window.fnNavigateAfterPrefetch('/action.php?do=gamelist', '循环加载');
                    else window.location.replace('/action.php?do=gamelist');
                }else{
                    if (window.fnHidePageLoading) window.fnHidePageLoading();
                    jqtoast(res.msg);
                }
            }, error:function(){ if (window.fnHidePageLoading) window.fnHidePageLoading(); jqtoast('网络繁忙，请稍后重试'); }
        })
    })
</script>
<script type="text/javascript">
var keyAll=['','','','','',''];
var fromInput=0;
$('#numinput input').on('input',function () {
    var digits=String($(this).val()||'').replace(/\D/g,'');
    if(digits!==''){
        $(this).val(digits.substr(-1));
        $(this).next('input').focus();
    }else{
        $(this).val('');
    }
}).on('keyup',function (e) {
    var thisValue=String($(this).val()||'').replace(/\D/g,'');
    if(e.keyCode==8){
        // backspace handled in keydown
    }else if(thisValue!==''){
        $(this).val(thisValue.substr(-1));
        $(this).next('input').focus();
    }else{
        $(this).val('');
    }
}).on('keydown',function (e) {
    var thisValue=String($(this).val()||'');
    if(e.keyCode==8 && thisValue=="") $(this).prev('input').focus();
});
var xtime;
function showText(){
    clearTimeout(xtime);
    xtime=setTimeout(function () {
        for (i=0;i<6;i++){
            if(typeof keyAll[i]=="undefined") break;
            $('#numinput input').eq(i).val(keyAll[i]);
            //console.log('add',keyAll[i]);
        }
    },50);

}


$('#loginroom').on('click',function () {
    var totalStr='';
    $('#numinput input').each(function () {
        totalStr+=""+$(this).val();
    });
    if (window.fnShowPageLoading) window.fnShowPageLoading('循环加载');
    $.ajax({
        url:'action.php?do=findroom',
        dataType:'json',
        type:'post',
        headers: { 'X-CSRF-TOKEN': (typeof feiniaoCsrf === 'function' ? feiniaoCsrf() : '') },
        data:{'room':totalStr, '_csrf': (typeof feiniaoCsrf === 'function' ? feiniaoCsrf() : '')},
        success:function (res) {
            if(res.status==1){
                // 键盘输入房号与历史房间走同一条即时跳转链路。
                if (window.fnNavigateAfterPrefetch) window.fnNavigateAfterPrefetch('/action.php?do=gamelist', '循环加载');
                else window.location.replace('/action.php?do=gamelist');
            }else{
                if (window.fnHidePageLoading) window.fnHidePageLoading();
                jqtoast(res.msg);
            }
        }, error:function(){ if (window.fnHidePageLoading) window.fnHidePageLoading(); jqtoast('网络繁忙，请稍后重试'); }
    })
})
    $('#logout').on('click',function () {
        jqalert({
            content:'确认要退出吗？',
            yestext:'退出',
            notext:'取消',
            yesfn:function () {
                window.location.href='/action.php?do=logout';
            }
        })

    })
</script>
<style>
    .room-history{position:relative;}
    .or-ptr{flex:0 0 auto;max-height:0;overflow:hidden;display:flex;flex-direction:row;align-items:center;justify-content:center;color:rgba(255,255,255,.94);font-size:13px;background:transparent;transition:max-height .2s;padding:0 16px;}
    .or-ptr.is-active{transition:none;}
    .or-ptr-icon{width:20px;height:20px;flex-shrink:0;margin-right:10px;transition:transform .15s;position:relative;}
    .or-ptr-icon .or-ptr-arrow{font-size:20px;color:#fff;line-height:1;transition:transform .15s;}
    .or-ptr-icon .or-ptr-spinner{position:absolute;top:0;left:0;width:20px;height:20px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;display:none;}
    .or-ptr.is-loading .or-ptr-arrow{display:none;}
    .or-ptr.is-loading .or-ptr-spinner{display:block;animation:or-spin .6s linear infinite;}
    .or-ptr-info{display:flex;flex-direction:column;align-items:flex-start;}
    .or-ptr-info .or-ptr-text{color:#fff;font-size:14px;font-weight:500;line-height:1.3;}
    .or-ptr-info .or-ptr-sub{color:rgba(255,255,255,.7);font-size:12px;line-height:1;padding-top:2px;}
    @keyframes or-spin{to{transform:rotate(360deg)}}
    .room-avatar{position:relative;cursor:pointer;}
    .room-avatar .del-badge{position:absolute;top:-4px;right:-4px;width:18px;height:18px;background:#ff4d4f;color:#fff;border-radius:50%;font-size:12px;line-height:18px;text-align:center;display:none;z-index:2;cursor:pointer;}
    .room-avatar.show-del .del-badge{display:block;}
    .room-avatar.del-anim{animation:delShake .3s ease;}
    @keyframes delShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-3px)}75%{transform:translateX(3px)}}
    .referral-tab{cursor:pointer;}
    .referral-tab:active{opacity:0.7!important;}
    .referral-oval-input{width:100%;height:0.8rem;border-radius:100px;background-color:rgba(255,255,255,0.5);border:1px solid #FFFFFF;padding:0 0.3rem;font-size:0.28rem;color:white;text-align:left;box-sizing:border-box;outline:none;}
    .referral-oval-input::placeholder{color:rgba(255,255,255,0.6);text-align:left;}
</style>
<script>
(function(){
    var listEl = document.querySelector('.listroom');
    var ptrEl = document.getElementById('historyPtr');
    if (!listEl || !ptrEl) return;
    var ptrText = ptrEl.querySelector('.or-ptr-text');
    var ptrArrow = ptrEl.querySelector('.or-ptr-arrow');
    var ptrSub = document.getElementById('historyPtrTime');
    var ptrThreshold = 80;
    var ptrStartY = 0;
    var ptrPulling = false;
    var ptrLoading = false;
    function updatePtrTime() {
        try {
            var raw = localStorage.getItem('fn_roomdoor_last_refresh');
            if (ptrSub && raw) ptrSub.textContent = '最后更新：' + raw;
        } catch (e) {}
    }
    updatePtrTime();
    listEl.addEventListener('touchstart', function (e) {
        if (ptrLoading) return;
        if (listEl.scrollTop > 5) return;
        if (!e.touches || !e.touches.length) return;
        ptrStartY = e.touches[0].clientY;
        ptrPulling = true;
    }, { passive: true });
    listEl.addEventListener('touchmove', function (e) {
        if (!ptrPulling || ptrLoading) return;
        if (!e.touches || !e.touches.length) return;
        var dy = e.touches[0].clientY - ptrStartY;
        if (dy < 0) { ptrEl.style.maxHeight = '0px'; return; }
        if (listEl.scrollTop > 0) { ptrPulling = false; ptrEl.style.maxHeight = '0px'; return; }
        var h = Math.min(dy * 0.5, 180);
        ptrEl.style.maxHeight = h + 'px';
        ptrEl.classList.add('is-active');
        if (ptrArrow) {
            var deg = Math.min((h / ptrThreshold) * 180, 180);
            ptrArrow.style.transform = 'rotate(' + deg + 'deg)';
        }
        if (ptrText) {
            ptrText.textContent = h >= ptrThreshold ? '松开立即刷新' : '下拉刷新';
        }
    }, { passive: true });
    listEl.addEventListener('touchend', function () {
        if (!ptrPulling) return;
        ptrPulling = false;
        var h = parseInt(ptrEl.style.maxHeight) || 0;
        if (h >= ptrThreshold && !ptrLoading) {
            ptrLoading = true;
            ptrEl.classList.add('is-loading');
            if (ptrText) ptrText.textContent = '刷新中...';
            ptrEl.style.maxHeight = '70px';
            $.ajax({
                url: '/action.php?do=roomdoor&ajax=1',
                dataType: 'json',
                success: function (res) {
                    if (res && res.history_html) {
                        $('#historyList').html(res.history_html);
                        try {
                            var now = new Date();
                            var stamp = '今天 ' + String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
                            localStorage.setItem('fn_roomdoor_last_refresh', stamp);
                            updatePtrTime();
                        } catch (e) {}
                    }
                },
                complete: function () {
                    ptrLoading = false;
                    ptrEl.classList.remove('is-loading', 'is-active');
                    ptrEl.style.maxHeight = '0px';
                    if (ptrArrow) ptrArrow.style.transform = '';
                    if (ptrText) ptrText.textContent = '下拉刷新';
                }
            });
        } else {
            ptrEl.classList.remove('is-active');
            ptrEl.style.maxHeight = '0px';
            if (ptrArrow) ptrArrow.style.transform = '';
        }
    }, { passive: true });
    listEl.addEventListener('touchcancel', function () {
        ptrPulling = false;
        ptrEl.classList.remove('is-active');
        ptrEl.style.maxHeight = '0px';
        if (ptrArrow) ptrArrow.style.transform = '';
    }, { passive: true });
})();
</script>
<script>
var inputMode = 'room';
function switchInputMode(mode) {
    inputMode = mode;
    var numInput = document.getElementById('numinput');
    var refInput = document.getElementById('referralInputWrap');
    var tabRoom = document.getElementById('tabRoom');
    var tabRef = document.getElementById('tabReferral');
    if (mode === 'room') {
        numInput.style.display = '';
        refInput.style.display = 'none';
        tabRoom.style.opacity = '1';
        tabRef.style.opacity = '0.6';
    } else {
        numInput.style.display = 'none';
        refInput.style.display = '';
        tabRoom.style.opacity = '0.6';
        tabRef.style.opacity = '1';
        document.getElementById('referralInput').focus();
    }
}
</script>
<script>
(function(){
    var longPressTimer = null;
    var longPressDuration = 600;
    var lpMoved = false;
    function feiniaoCsrf2() {
        return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    }
    function findAvatar(node) {
        while (node) {
            if (node.classList && node.classList.contains('room-avatar') && node.getAttribute('data-roomid')) return node;
            node = node.parentNode;
        }
        return null;
    }
    function hideAllDel() {
        var els = document.querySelectorAll('.room-avatar.show-del');
        for (var i = 0; i < els.length; i++) els[i].classList.remove('show-del');
    }
    function onLPStart(x, y) {
        var target = document.elementFromPoint(x, y);
        var avatar = findAvatar(target);
        if (!avatar) return;
        var roomid = avatar.getAttribute('data-roomid');
        if (!roomid) return;
        lpMoved = false;
        longPressTimer = setTimeout(function() {
            if (lpMoved) return;
            hideAllDel();
            avatar.classList.add('show-del');
            try { navigator.vibrate(30); } catch(e) {}
        }, longPressDuration);
    }
    function onLPEnd() {
        if (longPressTimer) { clearTimeout(longPressTimer); longPressTimer = null; }
    }
    document.addEventListener('touchstart', function(e) {
        var t = e.touches[0];
        lpMoved = false;
        onLPStart(t.clientX, t.clientY);
    }, true);
    document.addEventListener('touchmove', function() {
        lpMoved = true;
        onLPEnd();
    }, true);
    document.addEventListener('touchend', function() {
        onLPEnd();
    }, true);
    document.addEventListener('touchcancel', function() {
        onLPEnd();
    }, true);
    document.addEventListener('click', function(e) {
        var badge = e.target;
        if (badge.classList && badge.classList.contains('del-badge')) {
            e.stopPropagation();
            e.preventDefault();
            var avatar = badge.parentNode;
            var roomid = avatar.getAttribute('data-roomid');
            if (!roomid) return;
            var row = avatar;
            while (row && !(row.classList && row.classList.contains('roomrow'))) row = row.parentNode;
            var roomName = '';
            if (row) {
                var nameEl = row.querySelector('.room-name');
                if (nameEl) roomName = nameEl.textContent || '';
            }
            var msg = roomName ? '是否删除「' + roomName + '」的历史记录？' : '是否删除该历史记录？';
            jqalert({
                content: msg,
                yestext: '确定',
                notext: '取消',
                yesfn: function() { deleteHistoryRoom(avatar, roomid); },
                nofn: function() { hideAllDel(); }
            });
            return;
        }
        if (e.target.classList && e.target.classList.contains('del-badge')) return;
        var avatar = e.target;
        while (avatar && !(avatar.classList && avatar.classList.contains('room-avatar'))) {
            avatar = avatar.parentNode;
        }
        if (avatar && avatar.classList.contains('show-del')) {
            hideAllDel();
        }
    }, false);
    function deleteHistoryRoom(avatar, roomid) {
        avatar.classList.add('del-anim');
        setTimeout(function() { avatar.classList.remove('del-anim'); }, 300);
        $.ajax({
            url: '/action.php?do=roomdoor&ajax=delete_history',
            type: 'POST',
            dataType: 'json',
            headers: { 'X-CSRF-TOKEN': feiniaoCsrf2() },
            data: { roomid: roomid, '_csrf': feiniaoCsrf2() },
            success: function(res) {
                if (res && res.status == 1) {
                    var li = avatar;
                    while (li && !(li.classList && li.classList.contains('roomrow'))) li = li.parentNode;
                    if (li) {
                        li.style.transition = 'opacity .25s, max-height .25s';
                        li.style.opacity = '0';
                        li.style.maxHeight = '0';
                        li.style.overflow = 'hidden';
                        setTimeout(function() { li.remove(); }, 260);
                    }
                } else {
                    alert(res && res.msg ? res.msg : '删除失败');
                }
            },
            error: function() { alert('网络繁忙，请稍后重试'); }
        });
    }
})();
</script>
</body>
</html>
