<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, viewport-fit=cover" />
    <title>会员注册</title>
    <link rel="icon" type="image/png" href="/favicon.png?v=20260727a" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20260727a" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/login.css?v=20260815mjloginicons1" />
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
<link rel="Stylesheet" type="text/css" href="/Style/pop/css/style.css" />
    <script type="text/javascript" src="/Style/pop/js/popups.js"></script>
    <script type="text/javascript" src="/Style/plus.js?v=20260812pageload8"></script>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
</head>
<body>
<div class="mainbox max-width login-screen">
    <div class="toplogo brand-lockup"><img src="/Style/newimg/zylogo.png?v=20260727a" alt="问界"></div>

    <div class="main-content login-panel register-panel" id="regPanel">
        <div class="login-tab-spacer" aria-hidden="true"></div>
        <div class="input-box login-input">
            <span class="left-icon"><img src="/Style/newimg/mj-ui/login-nickname.png" alt=""></span>
            <span class="right-input"><input id="nickname" type="text" placeholder="请输入昵称" autocomplete="nickname" maxlength="16"></span>
        </div>
        <div class="input-box login-input">
            <span class="left-icon"><img src="/Style/newimg/mj-ui/login-user.png" alt=""></span>
            <span class="right-input"><input id="uname" type="text" placeholder="请输入6-15位字母和数字组合" autocomplete="username" maxlength="15" autocapitalize="none" autocorrect="off"></span>
        </div>
        <div class="input-box login-input">
            <span class="left-icon"><img src="/Style/newimg/mj-ui/login-lock.png" class="lock-icon" alt=""></span>
            <span class="right-input"><input id="pass" type="password" placeholder="请输入8-16位密码" autocomplete="new-password"></span>
        </div>
        <div class="input-box login-input">
            <span class="left-icon"><img src="/Style/newimg/mj-ui/login-lock.png" class="lock-icon" alt=""></span>
            <span class="right-input"><input id="repass" type="password" placeholder="请再次填写密码" autocomplete="new-password"></span>
        </div>
        <button type="button" class="toreg primary-login" id="doreg" aria-label="会员注册"><span>注册</span></button>
        <button type="button" class="reg-back-link" id="dologin">返回登陆</button>
    </div>
</div>
<script type="text/javascript">
(function () {
    $('#dologin').on('click', function () {
        window.location.href = '/action.php?do=login';
    });
    function feiniaoCsrf() {
        return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    }
    function bindRegSubmit() {
        var btn = document.getElementById('doreg');
        if (!btn || btn.getAttribute('data-bound') === '1') return;
        btn.setAttribute('data-bound', '1');
        var busy = false;
        function submitReg(e) {
            if (e) {
                try { e.preventDefault(); e.stopPropagation(); } catch (err) {}
            }
            if (busy) return;
            var nickname = String($('#nickname').val() || '').trim();
            var userName = String($('#uname').val() || '').trim();
            var pass = String($('#pass').val() || '');
            var repass = String($('#repass').val() || '');
            if (nickname === '') return jqtoast('昵称不能为空');
            if (Array.from(nickname).length > 16) return jqtoast('昵称最多16个字符');
            if (!/^[A-Za-z0-9]{6,15}$/.test(userName)) return jqtoast('账号需为6-15位字母和数字组合');
            if (pass.length < 8 || pass.length > 16) return jqtoast('密码需为8-16位');
            if (repass !== pass) return jqtoast('两次输入密码不一致');
            busy = true;
            btn.disabled = true;
            $.ajax({
                // 走 Application 独立接口（热修必同步），不依赖可能未覆盖的 action.php
                url: '/Application/ajax_register.php',
                dataType: 'json',
                type: 'post',
                headers: { 'X-CSRF-TOKEN': feiniaoCsrf() },
                data: { nickname: nickname, userName: userName, pass: pass, _csrf: feiniaoCsrf() },
                success: function (res) {
                    if (res && Number(res.status) === 1) {
                        jqtoast('注册成功，跳转到登录页');
                        setTimeout(function () {
                            window.location.href = '/action.php?do=login';
                        }, 1000);
                    } else {
                        jqtoast((res && res.msg) ? res.msg : '注册失败');
                        busy = false;
                        btn.disabled = false;
                    }
                },
                error: function (xhr) {
                    var msg = '注册失败，请稍后重试';
                    var text = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
                    if (text) {
                        try {
                            var j = JSON.parse(text);
                            if (j && j.msg) msg = j.msg;
                        } catch (err) {
                            if (text.indexOf('错误编码') >= 0) msg = '注册失败：服务器异常，请联系管理员';
                        }
                    }
                    jqtoast(msg);
                    busy = false;
                    btn.disabled = false;
                }
            });
        }
        // App WebView 优先 touchend，避免 click 被吞
        btn.addEventListener('touchend', function (e) { submitReg(e); }, { passive: false });
        btn.addEventListener('click', function (e) { submitReg(e); }, false);
    }
    if (window.jQuery) {
        $(bindRegSubmit);
    } else {
        document.addEventListener('DOMContentLoaded', bindRegSubmit);
    }
})();
</script>
<script type="text/javascript">
(function () {
    function focusInputFromBox(target) {
        // 原生 input 的焦点交给 WebView 自己处理。全局 touch/pointer 捕获会在
        // iOS 合成事件中重复抢焦点，可能把用户名输入误切到密码输入框。
        if (target && target.matches && target.matches('input, textarea, select, button')) return;
        var box = target && target.closest ? target.closest('.input-box') : null;
        if (!box) return;
        var input = box.querySelector('input');
        if (input) input.focus();
    }
    // 仅为点到图标/留白时补焦点；不用 capture，避免干扰输入控件自身的事件顺序。
    document.addEventListener('click', function (e) { focusInputFromBox(e.target); }, false);

})();
</script>
</body>
</html>
