<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, viewport-fit=cover" />
    <title>会员注册</title>
    <link rel="icon" type="image/png" href="/favicon.png?v=20260727a" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20260727a" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/login.css?v=20260905a" />
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
<link rel="Stylesheet" type="text/css" href="/Style/pop/css/style.css" />
    <script type="text/javascript" src="/Style/pop/js/popups.js"></script>
    <script type="text/javascript" src="/Style/plus.js?v=20260812pageload8"></script>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>" />
</head>
<body>
<div class="mainbox max-width login-screen">
<?php
// 元素由可视化编辑器(/editor.html)管理：读取 editor_reg.json 渲染；本页的输入/按钮用 DOM 叠加实现
$wjEdFile = __DIR__ . '/editor_reg.json';
$wjEd = is_file($wjEdFile) ? json_decode((string)file_get_contents($wjEdFile), true) : null;
if (!is_array($wjEd)) $wjEd = array();
if (empty($wjEd['elements']) || !is_array($wjEd['elements'])) $wjEd['elements'] = array();
$wjW = isset($wjEd['canvas']['w']) && is_numeric($wjEd['canvas']['w']) ? (float)$wjEd['canvas']['w'] : 375;
$wjH = isset($wjEd['canvas']['h']) && is_numeric($wjEd['canvas']['h']) ? (float)$wjEd['canvas']['h'] : 667;

// 需要在 UI 层覆盖的占位文字（渲染视觉层时跳过，改用覆盖层 div）
$wjSkipTexts = array('请输入你的昵称', '请输入6-15位字母和数字组合', '请输入8-16位密码', '请再次填写密码');
$wjPhEls = array();

// 视觉层（transform 缩放）
echo '<div id="wjEdWrap" data-w="' . $wjW . '" data-h="' . $wjH . '" style="position:absolute;left:50%;top:0;width:' . $wjW . 'px;height:' . $wjH . 'px;transform:translateX(-50%);transform-origin:top center;z-index:3;">';
echo '<div id="wjEdStage" style="position:absolute;inset:0;pointer-events:none;transform:scale(var(--wjS,1));transform-origin:top left;">';
foreach ($wjEd['elements'] as $wjIdx => $wjEl) {
    if (!is_array($wjEl) || !empty($wjEl['hidden'])) continue;
    $wjType = isset($wjEl['type']) ? $wjEl['type'] : '';
    $wjBase = 'position:absolute;left:' . (float)(isset($wjEl['x']) ? $wjEl['x'] : 0) . 'px;top:' . (float)(isset($wjEl['y']) ? $wjEl['y'] : 0) . 'px;pointer-events:none;z-index:' . ($wjIdx + 1) . ';';
    if (isset($wjEl['opacity']) && (float)$wjEl['opacity'] < 1) $wjBase .= 'opacity:' . (float)$wjEl['opacity'] . ';';
    if ($wjType === 'text') {
        $wjTxt = (string)(isset($wjEl['text']) ? $wjEl['text'] : '');
        $wjTrim = mb_strtolower(trim($wjTxt), 'UTF-8');
        // 占位文字跳过（UI 层用覆盖层 div 实现）
        if (in_array(trim($wjTxt), $wjSkipTexts, true)) {
            $wjPhEls[trim($wjTxt)] = $wjEl;
            continue;
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

// ---- 交互层（坐标按 375×667 设计稿，JS 按缩放系数重算实际位置） ----
echo '<div id="wjEdUI" style="position:absolute;inset:0;pointer-events:auto;">';

// 占位文字覆盖层（从 editor JSON 读取坐标和样式）
$wjPhColor = '#9EBAD0';
$wjPhFontSize = 14;
$wjPhFontWeight = 400;
function wjRegPhDiv($key, $id, $phEls) {
    global $wjPhColor, $wjPhFontSize, $wjPhFontWeight;
    if (!isset($phEls[$key])) return;
    $el = $phEls[$key];
    $c = !empty($el['color']) ? (string)$el['color'] : $wjPhColor;
    $fs = !empty($el['fontSize']) ? (float)$el['fontSize'] : $wjPhFontSize;
    $fw = !empty($el['fontWeight']) ? (int)$el['fontWeight'] : $wjPhFontWeight;
    echo '<div id="' . $id . '" data-x="' . (float)$el['x'] . '" data-y="' . (float)$el['y'] . '" style="position:absolute;left:0;top:0;z-index:9999;pointer-events:none;font-size:' . $fs . 'px;font-weight:' . $fw . ';color:' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . ';white-space:nowrap;">' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</div>';
}
wjRegPhDiv('请输入你的昵称', 'wjPhNick', $wjPhEls);
wjRegPhDiv('请输入6-15位字母和数字组合', 'wjPhUser', $wjPhEls);
wjRegPhDiv('请输入8-16位密码', 'wjPhPass', $wjPhEls);
wjRegPhDiv('请再次填写密码', 'wjPhRepass', $wjPhEls);

// 4 个真实输入框（坐标与 editor 中 rect 背景对齐）
// 昵称：rect y=261.5, h=46 → 输入框 y=261.5, 居中 padding-top 11px
echo '<input id="nickname" data-x="50" data-y="261.5" data-w="279" data-h="46" type="text" maxlength="16" autocomplete="nickname" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" aria-label="昵称" style="position:absolute;left:0;top:0;width:279px;height:46px;padding:0 20px;border:0;outline:none;background:transparent;font-size:15px;line-height:46px;color:#175DAA;" />';
// 账号：rect y=320, h=46
echo '<input id="uname" data-x="50" data-y="320" data-w="279" data-h="46" type="text" maxlength="15" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" aria-label="账号" style="position:absolute;left:0;top:0;width:279px;height:46px;padding:0 20px;border:0;outline:none;background:transparent;font-size:15px;line-height:46px;color:#175DAA;" />';
// 密码：rect y=380, h=46
echo '<input id="pass" data-x="50" data-y="380" data-w="279" data-h="46" type="password" maxlength="16" autocomplete="new-password" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="next" aria-label="密码" style="position:absolute;left:0;top:0;width:279px;height:46px;padding:0 20px;border:0;outline:none;background:transparent;font-size:15px;line-height:46px;color:#175DAA;" />';
// 确认密码：rect y=440, h=46
echo '<input id="repass" data-x="50" data-y="440" data-w="279" data-h="46" type="password" maxlength="16" autocomplete="new-password" autocapitalize="none" autocorrect="off" spellcheck="false" enterkeyhint="done" aria-label="确认密码" style="position:absolute;left:0;top:0;width:279px;height:46px;padding:0 20px;border:0;outline:none;background:transparent;font-size:15px;line-height:46px;color:#175DAA;" />';

// 注册按钮（覆盖图片区域 y=496, 280×48）
echo '<button type="button" id="doreg" data-x="50" data-y="496" data-w="280" data-h="48" aria-label="注册" style="position:absolute;left:0;top:0;width:280px;height:48px;border:0;background:transparent;color:transparent;cursor:pointer;">注册</button>';
// 返回登录按钮（覆盖图片区域 y=553, 280×48）
echo '<button type="button" id="dologin" data-x="50" data-y="553" data-w="280" data-h="48" aria-label="返回登录" style="position:absolute;left:0;top:0;width:280px;height:48px;border:0;background:transparent;color:transparent;cursor:pointer;">返回登录</button>';
// "账号登录" tab 点击跳转登录页（覆盖文字热区）
echo '<button type="button" id="wjGoLogin" data-x="67.5" data-y="194" data-w="110" data-h="30" aria-label="账号登录" style="position:absolute;left:0;top:0;width:110px;height:30px;border:0;background:transparent;color:transparent;cursor:pointer;">账号登录</button>';

echo '</div>';
echo '</div>';
?>
<style>
  #wjEdWrap input::placeholder{color:#9EBAD0;opacity:1}
  #wjEdWrap input::-webkit-input-placeholder{color:#9EBAD0;opacity:1}
</style>
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
        el.style.width = (w * s) + 'px';
        el.style.height = (h * s) + 'px';
        el.style.setProperty('--wjS', String(s));

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
        place('nickname');
        place('uname');
        place('pass');
        place('repass');
        place('doreg');
        place('dologin');
        place('wjGoLogin');
        place('wjPhNick');
        place('wjPhUser');
        place('wjPhPass');
        place('wjPhRepass');
    }
    window.addEventListener('resize', fitStage);
    fitStage();
})();
</script>
<script type="text/javascript">
(function () {
    function feiniaoCsrf() {
        return (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    }

    // placeholder 覆盖层：为空且未聚焦才显示
    (function () {
        var ids = [
            { ph: 'wjPhNick', input: 'nickname' },
            { ph: 'wjPhUser', input: 'uname' },
            { ph: 'wjPhPass', input: 'pass' },
            { ph: 'wjPhRepass', input: 'repass' }
        ];
        function sync() {
            for (var i = 0; i < ids.length; i++) {
                var ph = document.getElementById(ids[i].ph);
                var inp = document.getElementById(ids[i].input);
                if (!ph || !inp) continue;
                var has = !!String(inp.value || '');
                var focused = document.activeElement && document.activeElement.id === ids[i].input;
                ph.style.opacity = (!has && !focused) ? '1' : '0';
            }
        }
        $('#nickname,#uname,#pass,#repass').on('input change focus blur', sync);
        sync();
        var n = 0;
        var tm = setInterval(function(){ n++; sync(); if (n >= 20) clearInterval(tm); }, 150);
    })();

    // 注册提交
    var busy = false;
    function submitReg(e) {
        if (e) { try { e.preventDefault(); e.stopPropagation(); } catch (err) {} }
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
        $('#doreg').prop('disabled', true);
        $.ajax({
            url: '/Application/ajax_register.php',
            dataType: 'json',
            type: 'post',
            headers: { 'X-CSRF-TOKEN': feiniaoCsrf() },
            data: { nickname: nickname, userName: userName, pass: pass, _csrf: feiniaoCsrf() },
            success: function (res) {
                if (res && Number(res.status) === 1) {
                    jqtoast('注册成功，跳转到登录页');
                    setTimeout(function () { window.location.href = '/action.php?do=login'; }, 1000);
                } else {
                    jqtoast((res && res.msg) ? res.msg : '注册失败');
                    busy = false;
                    $('#doreg').prop('disabled', false);
                }
            },
            error: function (xhr) {
                var msg = '注册失败，请稍后重试';
                var text = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
                if (text) {
                    try { var j = JSON.parse(text); if (j && j.msg) msg = j.msg; } catch (err) {
                        if (text.indexOf('错误编码') >= 0) msg = '注册失败：服务器异常，请联系管理员';
                    }
                }
                jqtoast(msg);
                busy = false;
                $('#doreg').prop('disabled', false);
            }
        });
    }
    document.getElementById('doreg').addEventListener('touchend', function (e) { submitReg(e); }, { passive: false });
    $('#doreg').on('click', function (e) { submitReg(e); });

    // 返回登录
    $('#dologin').on('click', function () { window.location.href = '/action.php?do=login'; });
    // "账号登录" tab
    $('#wjGoLogin').on('click', function () { window.location.href = '/action.php?do=login'; });

    // 回车跳转到下一个输入框，最后一个回车提交
    $('#nickname').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#uname').trigger('focus'); } });
    $('#uname').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#pass').trigger('focus'); } });
    $('#pass').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#repass').trigger('focus'); } });
    $('#repass').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#doreg').trigger('click'); } });
})();
</script>
</body>
</html>
