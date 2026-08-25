<?php
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . '/Public/config.php';
require_once dirname(dirname(__DIR__)) . '/Public/csrf.php';
require_once dirname(dirname(__DIR__)) . '/Public/auth_security.php';
if (empty($_SESSION['userid'])) {
    header('Location: /action.php?do=login');
    exit;
}
$userid = (string)$_SESSION['userid'];
$enabled = feiniao_totp_user_enabled($userid);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
  <meta name="format-detection" content="telephone=no">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>二次验证</title>
  <link rel="stylesheet" href="/Style/newcss/user-subpage.css?v=20260817totpclose1">
</head>
<body>
<header class="si-nav">
  <button type="button" class="si-back" id="backBtn" aria-label="返回"><img src="/Style/newimg/leftar.png" alt=""></button>
  <div class="si-nav-title">二次验证</div>
</header>

<main class="tf-page">
  <section class="tf-hero">
    <h1><?php echo $enabled ? '二次验证已开启' : '为账号增加一层登录保护'; ?></h1>
    <p>开启后，账号密码验证通过后还必须输入验证器生成的 6 位动态验证码。动态码每 30 秒更新一次。</p>
  </section>

  <?php if (!$enabled) { ?>
  <section class="tf-panel tf-download">
    <div class="tf-step">
      <div class="tf-number">1</div>
      <div class="tf-content">
        <div class="tf-title">下载 Google 验证器</div>
        <div class="tf-copy">请先在应用商店搜索“Google Authenticator”（Google 验证器）并安装。安装后再返回本页继续绑定。</div>
        <div class="tf-store-links">
          <a class="tf-store-link" href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank" rel="noopener">前往 App Store</a>
          <a class="tf-store-link" href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank" rel="noopener">前往 Google Play</a>
        </div>
        <div class="tf-mainland-note">
          <strong>大陆用户无法下载？</strong>
          <span>可在微信小程序搜索“腾讯身份验证器”；也可使用任意支持 TOTP 的验证器，后续通过“复制密钥”手动添加。</span>
        </div>
      </div>
    </div>
  </section>

  <section class="tf-panel" id="beginPanel">
    <div class="tf-step">
      <div class="tf-number">2</div>
      <div class="tf-content">
        <div class="tf-title">验证身份并生成绑定信息</div>
        <div class="tf-copy">请输入当前密码确认是账号本人操作，通过后会显示二维码和手动密钥。</div>
        <input class="tf-field" type="password" id="currentPassword" autocomplete="current-password" placeholder="请输入当前密码">
        <button class="tf-primary" type="button" id="beginBtn">开始绑定</button>
      </div>
    </div>
  </section>

  <section class="tf-panel tf-setup" id="setupPanel">
    <div class="tf-step">
      <div class="tf-number">2</div>
      <div class="tf-content">
        <div class="tf-title">添加到验证器</div>
        <div class="tf-copy">同一台手机可直接点“打开验证器”；也可以保存二维码后在验证器中导入，或复制密钥手动添加。</div>
        <img class="tf-qr" id="qrImage" alt="二次验证绑定二维码">
        <div class="tf-secret" id="secretText"></div>
        <div class="tf-actions">
          <button class="tf-secondary" type="button" id="openAuthenticator">打开验证器</button>
          <button class="tf-secondary" type="button" id="saveQr">保存二维码</button>
          <button class="tf-secondary" type="button" id="copySecret">复制密钥</button>
          <button class="tf-secondary" type="button" id="restartBind">重新生成</button>
        </div>
      </div>
    </div>
    <div class="tf-step">
      <div class="tf-number">3</div>
      <div class="tf-content">
        <div class="tf-title">输入 6 位动态验证码</div>
        <div class="tf-copy">输入验证器当前显示的验证码，确认绑定正确。</div>
        <input class="tf-field code" type="text" id="enableCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">
        <button class="tf-primary" type="button" id="enableBtn">确认开启</button>
        <div class="tf-status" id="setupStatus"></div>
      </div>
    </div>
  </section>
  <?php } else { ?>
  <section class="tf-enabled">
    <div class="tf-enabled-title">账号已受二次验证保护</div>
    <div class="tf-enabled-copy">关闭二次验证需要同时提供当前密码和验证器动态码。</div>
  </section>
  <section class="tf-panel">
    <input class="tf-field" type="password" id="disablePassword" autocomplete="current-password" placeholder="当前密码">
    <input class="tf-field code" type="text" id="disableCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6 位动态验证码">
    <button class="tf-danger" type="button" id="disableBtn">关闭二次验证</button>
    <div class="tf-status" id="disableStatus"></div>
  </section>
  <?php } ?>
</main>

<div class="tf-confirm" id="disableConfirm" aria-hidden="true">
  <div class="tf-confirm-mask" id="disableConfirmMask"></div>
  <section class="tf-confirm-card" role="dialog" aria-modal="true" aria-labelledby="disableConfirmTitle">
    <h2 id="disableConfirmTitle">确认关闭二次验证？</h2>
    <p>关闭后，账号登录将不再校验动态验证码。确定要继续吗？</p>
    <div class="tf-confirm-actions">
      <button type="button" class="tf-confirm-cancel" id="disableConfirmCancel">取消</button>
      <button type="button" class="tf-confirm-submit" id="disableConfirmSubmit">确定关闭</button>
    </div>
  </section>
</div>

<script>(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var csrf = meta ? meta.content : '';
  var bindState = null;

  function post(data) {
    data._csrf = csrf;
    return fetch('/Application/ajax_totp.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-TOKEN': csrf },
      body: new URLSearchParams(data).toString()
    }).then(function (res) { return res.json(); });
  }
  function digits(input) { input.value = String(input.value || '').replace(/\D/g, '').slice(0, 6); }
  function back() { location.replace('/Templates/user/setting.php'); }
  document.getElementById('backBtn').addEventListener('click', back);

  var begin = document.getElementById('beginBtn');
  if (begin) {
    begin.addEventListener('click', function () {
      var password = document.getElementById('currentPassword').value;
      if (!password) { alert('请输入当前密码'); return; }
      begin.disabled = true;
      post({ action: 'begin', password: password }).then(function (res) {
        begin.disabled = false;
        if (!res || res.status != 1) { alert((res && res.msg) || '绑定初始化失败'); return; }
        bindState = res;
        document.getElementById('secretText').textContent = res.secret;
        document.getElementById('qrImage').src = res.qr;
        document.getElementById('setupPanel').classList.add('show');
        document.getElementById('beginPanel').style.display = 'none';
      }).catch(function () { begin.disabled = false; alert('网络错误，请重试'); });
    });
    document.getElementById('openAuthenticator').addEventListener('click', function () {
      if (!bindState || !bindState.otpauth) return;
      if (/iPhone|iPad|iPod/i.test(navigator.userAgent || '')) {
        location.href = 'googleauthenticator://';
      } else {
        location.href = bindState.otpauth;
      }
    });
    document.getElementById('saveQr').addEventListener('click', function () {
      if (!bindState || !bindState.qr) return;
      var link = document.createElement('a');
      link.href = bindState.qr;
      link.download = 'wenjie-2fa.png';
      link.target = '_blank';
      document.body.appendChild(link); link.click(); document.body.removeChild(link);
    });
    document.getElementById('copySecret').addEventListener('click', function () {
      if (!bindState) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(bindState.secret).then(function () { alert('密钥已复制'); });
      } else {
        var area = document.createElement('textarea'); area.value = bindState.secret; document.body.appendChild(area); area.select(); document.execCommand('copy'); document.body.removeChild(area); alert('密钥已复制');
      }
    });
    document.getElementById('restartBind').addEventListener('click', function () {
      document.getElementById('setupPanel').classList.remove('show');
      document.getElementById('beginPanel').style.display = '';
      document.getElementById('currentPassword').value = '';
      bindState = null;
    });
    var enableCode = document.getElementById('enableCode');
    enableCode.addEventListener('input', function () { digits(enableCode); });
    document.getElementById('enableBtn').addEventListener('click', function () {
      digits(enableCode);
      var out = document.getElementById('setupStatus');
      if (enableCode.value.length !== 6) { out.textContent = '请输入 6 位动态验证码'; return; }
      post({ action: 'enable', code: enableCode.value }).then(function (res) {
        if (!res || res.status != 1) { out.className = 'tf-status'; out.textContent = (res && res.msg) || '开启失败'; return; }
        out.className = 'tf-status ok'; out.textContent = res.msg;
        setTimeout(function () { location.reload(); }, 600);
      }).catch(function () { out.textContent = '网络错误，请重试'; });
    });
  }

  var disable = document.getElementById('disableBtn');
  if (disable) {
    var disableCode = document.getElementById('disableCode');
    var disablePassword = document.getElementById('disablePassword');
    var disableOut = document.getElementById('disableStatus');
    var disableConfirm = document.getElementById('disableConfirm');
    var disableConfirmMask = document.getElementById('disableConfirmMask');
    var disableConfirmCancel = document.getElementById('disableConfirmCancel');
    var disableConfirmSubmit = document.getElementById('disableConfirmSubmit');
    var disableBusy = false;

    function showDisableConfirm(show) {
      disableConfirm.classList.toggle('show', !!show);
      disableConfirm.setAttribute('aria-hidden', show ? 'false' : 'true');
      document.body.classList.toggle('tf-confirm-open', !!show);
    }

    disableCode.addEventListener('input', function () { digits(disableCode); });
    disable.addEventListener('click', function (event) {
      event.preventDefault();
      digits(disableCode);
      disableOut.className = 'tf-status';
      if (!disablePassword.value || disableCode.value.length !== 6) {
        disableOut.textContent = '请输入当前密码和 6 位动态验证码';
        return;
      }
      disableOut.textContent = '';
      showDisableConfirm(true);
    });

    disableConfirmCancel.addEventListener('click', function () { showDisableConfirm(false); });
    disableConfirmMask.addEventListener('click', function () { showDisableConfirm(false); });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && disableConfirm.classList.contains('show')) showDisableConfirm(false);
    });

    disableConfirmSubmit.addEventListener('click', function () {
      if (disableBusy) return;
      disableBusy = true;
      disable.disabled = true;
      disableConfirmSubmit.disabled = true;
      disableConfirmSubmit.textContent = '正在关闭…';
      showDisableConfirm(false);
      disableOut.className = 'tf-status';
      disableOut.textContent = '正在关闭二次验证…';
      post({ action: 'disable', password: disablePassword.value, code: disableCode.value }).then(function (res) {
        if (!res || res.status != 1) {
          disableOut.textContent = (res && res.msg) || '关闭失败';
          return;
        }
        disableOut.className = 'tf-status ok';
        disableOut.textContent = res.msg;
        setTimeout(function () { location.reload(); }, 600);
      }).catch(function () {
        disableOut.textContent = '网络错误，请重试';
      }).then(function () {
        disableBusy = false;
        disable.disabled = false;
        disableConfirmSubmit.disabled = false;
        disableConfirmSubmit.textContent = '确定关闭';
      });
    });
  }
}());
</script>
</body>
</html>
