<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, viewport-fit=cover" />
    <title>登录</title>
    <link rel="icon" type="image/png" href="/favicon.png?v=20260727a" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20260727a" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css?v=20260807editablefix1" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/login.css?v=20260905a" />
    <link rel="preload" as="image" href="/Style/newimg/home_banner_preload.png?v=20260812preload2" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/login-security.css?v=20260816captchabuffer1" />
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
<link rel="Stylesheet" type="text/css" href="/Style/pop/css/style.css" />
    <script type="text/javascript" src="/Style/pop/js/popups.js"></script>
    <script type="text/javascript" src="/Style/newjs/login-security.js?v=20260816captchaopt1"></script>
    <script type="text/javascript" src="/Style/plus.js?v=20260812pageload8"></script>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
</head>
<body>
<div class="mainbox max-width login-screen">
<?php
// 元素由可视化编辑器(/editor.html)管理：读取 editor_login.json 渲染；本页的输入/按钮用 DOM 叠加实现
$wjEdFile = __DIR__ . '/editor_login.json';
$wjEd = is_file($wjEdFile) ? json_decode((string)file_get_contents($wjEdFile), true) : null;
if (!is_array($wjEd)) $wjEd = array();
if (empty($wjEd['elements']) || !is_array($wjEd['elements'])) $wjEd['elements'] = array();
$wjW = isset($wjEd['canvas']['w']) && is_numeric($wjEd['canvas']['w']) ? (float)$wjEd['canvas']['w'] : 375;
$wjH = isset($wjEd['canvas']['h']) && is_numeric($wjEd['canvas']['h']) ? (float)$wjEd['canvas']['h'] : 667;
$wjPhUser = '';
$wjPhPass = '';
$wjPhUserEl = null;
$wjPhPassEl = null;
$wjGoRegEl = null;

// 注意：iOS Safari 对 transform 包裹 input 的交互支持很差（无法聚焦/无法输入）。
// 这里将“视觉层”与“交互层”分离：视觉层可 transform 缩放，交互层用 JS 计算缩放后的 left/top/width/height。
echo '<div id="wjEdWrap" data-w="' . $wjW . '" data-h="' . $wjH . '" style="position:absolute;left:50%;top:0;width:' . $wjW . 'px;height:' . $wjH . 'px;transform:translateX(-50%);transform-origin:top center;z-index:3;">';
echo '<div id="wjEdStage" style="position:absolute;inset:0;pointer-events:none;transform:scale(var(--wjS,1));transform-origin:top left;">';
foreach ($wjEd['elements'] as $wjIdx => $wjEl) {
    if (!is_array($wjEl) || !empty($wjEl['hidden'])) continue;
    $wjType = isset($wjEl['type']) ? $wjEl['type'] : '';
    $wjBase = 'position:absolute;left:' . (float)(isset($wjEl['x']) ? $wjEl['x'] : 0) . 'px;top:' . (float)(isset($wjEl['y']) ? $wjEl['y'] : 0) . 'px;pointer-events:none;z-index:' . ($wjIdx + 1) . ';';
    if (isset($wjEl['opacity']) && (float)$wjEl['opacity'] < 1) $wjBase .= 'opacity:' . (float)$wjEl['opacity'] . ';';
    if ($wjType === 'text') {
        $wjTxt = (string)(isset($wjEl['text']) ? $wjEl['text'] : '');
        $wjTrim = trim($wjTxt);

        // 将“输入提示文字”转为 input.placeholder，避免被 input 覆盖导致看不见
        if ($wjTrim === '请输入你的账号' || $wjTrim === '请输入你的账户') {
            $wjPhUser = $wjTxt;
            $wjPhUserEl = $wjEl;
            continue;
        }
        if ($wjTrim === '请输入你的密码') {
            $wjPhPass = $wjTxt;
            $wjPhPassEl = $wjEl;
            continue;
        }

        // 注册入口：实际点击在 UI 层实现（文本层禁用 pointer-events）
        if ($wjTrim === '注册账户') {
            $wjGoRegEl = $wjEl;
        }

        echo '<div style="' . $wjBase . 'font-size:' . (float)(isset($wjEl['fontSize']) ? $wjEl['fontSize'] : 14) . 'px;font-weight:' . (int)(isset($wjEl['fontWeight']) ? $wjEl['fontWeight'] : 400) . ';color:' . (isset($wjEl['color']) ? $wjEl['color'] : '#333333') . ';white-space:nowrap;">' . htmlspecialchars($wjTxt, ENT_QUOTES, 'UTF-8') . '</div>';
    } elseif ($wjType === 'image') {
        echo '<div style="' . $wjBase . 'width:' . (float)(isset($wjEl['w']) ? $wjEl['w'] : 100) . 'px;height:' . (float)(isset($wjEl['h']) ? $wjEl['h'] : 100) . 'px;background-image:url(\'' . (string)(isset($wjEl['src']) ? $wjEl['src'] : '') . '\');background-size:100% 100%;background-repeat:no-repeat;"></div>';
    } elseif ($wjType === 'rect') {
        $wjS = $wjBase . 'width:' . (float)(isset($wjEl['w']) ? $wjEl['w'] : 60) . 'px;height:' . (float)(isset($wjEl['h']) ? $wjEl['h'] : 24) . 'px;';
        $wjS .= (empty($wjEl['bg']) || $wjEl['bg'] === 'none') ? 'background:transparent;' : 'background:' . $wjEl['bg'] . ';';
        if (!empty($wjEl['border']) && (float)(isset($wjEl['borderWidth']) ? $wjEl['borderWidth'] : 0) > 0) {
            $wjS .= 'border:' . (float)$wjEl['borderWidth'] . 'px solid ' . $wjEl['border'] . ';';
        }
        if (isset($wjEl['radius']) && (float)$wjEl['radius'] > 0) $wjS .= 'border-radius:' . (float)$wjEl['radius'] . 'px;';
        echo '<div style="' . $wjS . '"></div>';
    }
}
echo '</div>';

// 输入与按钮叠加区域（坐标按 375×667）
echo '<div id="wjEdUI" style="position:absolute;inset:0;pointer-events:auto;">';
$wjPhColor = '#9EBAD0';
$wjPhFontSize = 14;
$wjPhFontWeight = 400;

if (is_array($wjPhUserEl)) {
    if (!empty($wjPhUserEl['color'])) $wjPhColor = (string)$wjPhUserEl['color'];
    if (!empty($wjPhUserEl['fontSize'])) $wjPhFontSize = (float)$wjPhUserEl['fontSize'];
    if (!empty($wjPhUserEl['fontWeight'])) $wjPhFontWeight = (int)$wjPhUserEl['fontWeight'];
    echo '<div id="wjPhUser" data-x="' . (float)$wjPhUserEl['x'] . '" data-y="' . (float)$wjPhUserEl['y'] . '" style="position:absolute;left:0;top:0;z-index:9999;pointer-events:none;font-size:' . $wjPhFontSize . 'px;font-weight:' . $wjPhFontWeight . ';color:' . htmlspecialchars($wjPhColor, ENT_QUOTES, 'UTF-8') . ';white-space:nowrap;">' . htmlspecialchars($wjPhUser, ENT_QUOTES, 'UTF-8') . '</div>';
}

if (is_array($wjPhPassEl)) {
    $c = !empty($wjPhPassEl['color']) ? (string)$wjPhPassEl['color'] : $wjPhColor;
    $fs = !empty($wjPhPassEl['fontSize']) ? (float)$wjPhPassEl['fontSize'] : $wjPhFontSize;
    $fw = !empty($wjPhPassEl['fontWeight']) ? (int)$wjPhPassEl['fontWeight'] : $wjPhFontWeight;
    echo '<div id="wjPhPass" data-x="' . (float)$wjPhPassEl['x'] . '" data-y="' . (float)$wjPhPassEl['y'] . '" style="position:absolute;left:0;top:0;z-index:9999;pointer-events:none;font-size:' . $fs . 'px;font-weight:' . $fw . ';color:' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . ';white-space:nowrap;">' . htmlspecialchars($wjPhPass, ENT_QUOTES, 'UTF-8') . '</div>';
}

// 点击“注册账户”跳转会员注册页
if (is_array($wjGoRegEl)) {
    $x = isset($wjGoRegEl['x']) ? (float)$wjGoRegEl['x'] : 240;
    $y = isset($wjGoRegEl['y']) ? (float)$wjGoRegEl['y'] : 196;
    // 宽高做一个可点击热区即可，不追求精确包围
    echo '<button type="button" id="wjGoReg" data-x="' . $x . '" data-y="' . ($y - 6) . '" data-w="110" data-h="34" aria-label="注册账户" style="position:absolute;left:0;top:0;width:110px;height:34px;border:0;background:transparent;color:transparent;cursor:pointer;"></button>';
}

$wjPhUserAttr = $wjPhUser !== '' ? htmlspecialchars($wjPhUser, ENT_QUOTES, 'UTF-8') : '请输入你的账户';
$wjPhPassAttr = $wjPhPass !== '' ? htmlspecialchars($wjPhPass, ENT_QUOTES, 'UTF-8') : '请输入你的密码';

// data-* 保存设计稿坐标，避免 transform 缩放下 input 失效
// iOS/Safari 某些场景 placeholder 会重复渲染或与覆盖层叠加：这里不使用 placeholder，仅用覆盖层 wjPhUser/wjPhPass
echo '<input id="uname" data-x="30" data-y="290" data-w="320" data-h="46" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" aria-label="' . $wjPhUserAttr . '" style="position:absolute;left:0;top:0;width:320px;height:46px;padding:0 20px 0 48px;border:0;outline:none;background:transparent;font-size:15px;line-height:46px;color:#175DAA;" />';
echo '<input id="pass" data-x="30" data-y="350" data-w="320" data-h="46" type="password" autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="done" aria-label="' . $wjPhPassAttr . '" style="position:absolute;left:0;top:0;width:320px;height:46px;padding:0 20px 0 48px;border:0;outline:none;background:transparent;font-size:15px;line-height:46px;color:#175DAA;" />';
// 登录按钮覆盖图片区域
echo '<button type="button" id="dologin" data-x="30" data-y="480" data-w="320" data-h="48" aria-label="登录" style="position:absolute;left:0;top:0;width:320px;height:48px;border:0;background:transparent;color:transparent;cursor:pointer;">login</button>';
echo '<input id="totp" type="hidden">';
echo '<button type="button" id="loginTwoFactorEntry" data-x="246" data-y="268.5" data-w="76" data-h="28" style="position:absolute;left:0;top:0;width:76px;height:28px;border:0;background:transparent;color:transparent;cursor:pointer;display:none;">2fa</button>';
echo '<button type="button" id="togglePass" style="display:none;"></button>';
// 记住密码复选框：盖在编辑器切图框上，点击切换 √
echo '<div id="reck" data-x="245" data-y="411" data-w="17" data-h="17" style="position:absolute;left:0;top:0;width:17px;height:17px;cursor:pointer;z-index:10000;display:flex;align-items:center;justify-content:center;font-size:13px;color:#175DAA;font-weight:bold;line-height:1;"></div>';
echo '</div>';
echo '</div>';
?>
<style>
  #wjEdWrap input::placeholder{color:#9EBAD0;opacity:1}
  #wjEdWrap input::-webkit-input-placeholder{color:#9EBAD0;opacity:1}
</style>
    <div id="loginTotpBox" style="display:none;"></div>

    <nav class="reg-way social-login">
        <button type="button" class="social-btn" id="btnWechat" aria-label="微信登录">
            <img src="/Style/newimg/ic_wechat_login.webp" alt="微信登录">
        </button>
        <button type="button" class="social-btn" id="btnDouyin" aria-label="抖音登录">
            <img src="/Style/newimg/ic_tiktok_login.webp" alt="抖音登录">
        </button>
        <button type="button" class="social-btn" id="btnOwner" aria-label="房主登录">
            <img src="/Style/newimg/ic_admin_login.webp" alt="房主登录">
        </button>
        <button type="button" class="social-btn" id="doreg" aria-label="会员注册">
            <img src="/Style/newimg/ic_member_register.webp" alt="会员注册">
        </button>
    </nav>
</div>
<script type="text/javascript">
(function(){
    function fitStage(){
        var el = document.getElementById('wjEdWrap');
        if (!el) return;
        var w = Number(el.getAttribute('data-w') || 375) || 375;
        var h = Number(el.getAttribute('data-h') || 667) || 667;
        var sw = window.innerWidth / w;
        var sh = window.innerHeight / h;
        var s = Math.min(1, sw, sh);

        // 外层容器直接缩到最终尺寸（不使用 transform 缩放交互层）
        el.style.width = (w * s) + 'px';
        el.style.height = (h * s) + 'px';
        el.style.setProperty('--wjS', String(s));

        // 将交互层元素按缩放系数重算 left/top/width/height
        function place(id){
            var n = document.getElementById(id);
            if (!n) return;
            var x = Number(n.getAttribute('data-x') || 0) || 0;
            var y = Number(n.getAttribute('data-y') || 0) || 0;
            var ww = Number(n.getAttribute('data-w') || 0) || 0;
            var hh = Number(n.getAttribute('data-h') || 0) || 0;
            n.style.left = (x * s) + 'px';
            n.style.top = (y * s) + 'px';
            if (ww) n.style.width = (ww * s) + 'px';
            if (hh) n.style.height = (hh * s) + 'px';
        }
        place('uname');
        place('pass');
        place('dologin');
        place('loginTwoFactorEntry');
        place('wjPhUser');
        place('wjPhPass');
        place('wjGoReg');
        place('reck');
    }
    window.addEventListener('resize', fitStage);
    fitStage();
})();
</script>
<script type="text/javascript">
(function () {
    var OWNER_URL = 'https://wjagent.atmyx.app';
    var passVisible = false;
    var pendingCaptchaToken = '';

    function rememberKey() { return 'fn_login_remember'; }
    function loadRemember() {
        try {
            var raw = localStorage.getItem(rememberKey());
            if (!raw) return;
            var data = JSON.parse(raw);
            if (data && data.userName) {
                $('#uname').val(data.userName);
                $('#pass').val(data.pass || '');
                $('#reck').addClass('check').text('\u2713');
            }
        } catch (e) {}
    }
    function saveRemember() {
        if ($('#reck').hasClass('check')) {
            localStorage.setItem(rememberKey(), JSON.stringify({
                userName: $('#uname').val(),
                pass: $('#pass').val()
            }));
        } else {
            localStorage.removeItem(rememberKey());
        }
    }

    loadRemember();

    function toggleRemember() {
        var $r = $('#reck');
        $r.toggleClass('check');
        $r.text($r.hasClass('check') ? '\u2713' : '');
    }
    $('#reck').on('click', toggleRemember);
    $('.toremember').on('click', toggleRemember);

    function togglePassVisibility() {
        passVisible = !passVisible;
        $('#pass').attr('type', passVisible ? 'text' : 'password');
        $('#togglePass').find('.eye-open').toggleClass('is-on', !passVisible);
        $('#togglePass').find('.eye-closed').toggleClass('is-on', passVisible);
    }
    $('#togglePass').on('click', togglePassVisibility);
    $('#togglePass').on('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            togglePassVisibility();
        }
    });

    $('#doreg').on('click', function () {
        window.location.href = '/action.php?do=reg';
    });
    $('#wjGoReg').on('click', function () {
        window.location.href = '/action.php?do=reg';
    });
    $('#btnWechat').on('click', function () { jqtoast('微信登录暂时关闭！'); });
    $('#btnDouyin').on('click', function () { jqtoast('抖音登录暂时关闭！'); });
    $('#btnOwner').on('click', function () { window.location.href = OWNER_URL; });

    $('#loginTwoFactorEntry').on('click', function () {
        var box = $('#loginTotpBox');
        var show = !box.hasClass('is-visible');
        box.toggleClass('is-visible', show).attr('aria-hidden', show ? 'false' : 'true');
    });
    $('#totp').on('input', function () { this.value = String(this.value || '').replace(/\D/g, '').slice(0, 6); });

    function submitLogin(captchaToken, totpCode) {
        var userName = $('#uname').val();
        var pass = $('#pass').val();
        if (window.fnShowPageLoading) window.fnShowPageLoading('正在登录');
        $.ajax({
            url: 'action.php?do=dologin',
            dataType: 'json',
            method: 'post',
            headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
            data: {
                'userName': userName,
                'pass': pass,
                'captcha_token': captchaToken,
                'totp_code': totpCode || '',
                '_csrf': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
            },
            success: function (res) {
                if (res.status == 1) {
                    window.location.replace('/action.php?do=roomdoor');
                } else if (res && res.require_totp == 1) {
                    if (window.fnHidePageLoading) window.fnHidePageLoading();
                    pendingCaptchaToken = captchaToken || pendingCaptchaToken;
                    $('#loginTotpBox').addClass('is-visible').attr('aria-hidden', 'false');
                    jqtoast('您已开启二次验证，请输入6位动态验证码');
                    setTimeout(function () { $('#totp').trigger('focus'); }, 80);
                } else {
                    if (window.fnHidePageLoading) window.fnHidePageLoading();
                    pendingCaptchaToken = '';
                    jqtoast((res && res.msg) || '登录失败，请重试');
                }
            }, error: function () { if (window.fnHidePageLoading) window.fnHidePageLoading(); jqtoast('网络繁忙，请稍后重试'); }
        });
    }

    $('#dologin').on('click', function () {
        var userName = $('#uname').val();
        var pass = $('#pass').val();
        if (userName === '') return jqtoast('账号不能为空');
        if (pass.length < 6) return jqtoast('密码最少6位');
        saveRemember();
        var code = String($('#totp').val() || '').replace(/\D/g, '');
        if ($('#loginTotpBox').hasClass('is-visible')) {
            if (code.length !== 6) return jqtoast('请输入6位动态验证码');
            if (pendingCaptchaToken) return submitLogin(pendingCaptchaToken, code);
        }
        if (!window.FnLoginSecurity) return jqtoast('安全验证加载失败，请刷新页面');
        window.FnLoginSecurity.open(function (captchaToken) {
            pendingCaptchaToken = captchaToken || '';
            submitLogin(captchaToken, code);
        });
    });

    // placeholder 覆盖层：避免部分机型 placeholder 不显示
    (function () {
        var phU = document.getElementById('wjPhUser');
        var phP = document.getElementById('wjPhPass');
        if (!phU && !phP) return;
        function sync(){
            var uHas = !!String($('#uname').val() || '');
            var pHas = !!String($('#pass').val() || '');

            // 只保留一条占位文字：为空且未聚焦才显示
            var uFocus = document.activeElement && document.activeElement.id === 'uname';
            var pFocus = document.activeElement && document.activeElement.id === 'pass';
            if (phU) phU.style.opacity = (!uHas && !uFocus) ? '1' : '0';
            if (phP) phP.style.opacity = (!pHas && !pFocus) ? '1' : '0';
        }
        $('#uname,#pass').on('input change focus blur', sync);
        sync();

        // 某些浏览器自动填充不会触发 input/change，短时间轮询兜底
        var n = 0;
        var tm = setInterval(function(){
            n++;
            sync();
            if (n >= 20) clearInterval(tm);
        }, 150);
    })();

    // 回车登录
    $('#pass').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#dologin').trigger('click');
        }
    });
})();
</script>
<script type="text/javascript">
(function () {
    function focusInputFromShell(target) {
        var box = target && target.closest ? target.closest('.input-box') : null;
        if (!box || (target.matches && target.matches('input'))) return;
        var input = box.querySelector('input');
        if (input) input.focus();
    }
    document.addEventListener('click', function (e) { focusInputFromShell(e.target); }, false);

    if (!/Android/i.test(navigator.userAgent || '')) return;
    document.addEventListener('keydown', function (e) {
        var input = e.target && e.target.matches && e.target.matches('input[type="text"],input[type="password"]') ? e.target : null;
        if (!input || e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
        var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
        var value = input.value || '';
        if (e.key === 'Backspace') {
            if (start === end && start > 0) start -= 1;
            input.value = value.slice(0, start) + value.slice(end);
            input.setSelectionRange(start, start);
        } else if (e.key === 'Delete') {
            input.value = value.slice(0, start) + value.slice(end + 1);
            input.setSelectionRange(start, start);
        } else if (e.key && e.key.length === 1) {
            input.value = value.slice(0, start) + e.key + value.slice(end);
            start += e.key.length;
            input.setSelectionRange(start, start);
        } else {
            return;
        }
        e.preventDefault();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }, true);
})();
</script>
</body>
</html>
