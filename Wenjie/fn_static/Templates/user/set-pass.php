<?php
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . '/Public/config.php';
require_once dirname(dirname(__DIR__)) . '/Public/csrf.php';
if (empty($_SESSION['userid'])) { header('Location: /action.php?do=login'); exit; }
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover">
  <meta name="format-detection" content="telephone=no">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(feiniao_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>修改密码</title>
  <link rel="stylesheet" href="/Style/newcss/user-subpage.css?v=20260815usersub1">
  <link rel="stylesheet" href="/Style/pop/css/style.css">
  <script src="/Style/newjs/jquery-1.10.1.min.js"></script>
  <script src="/Style/pop/js/popups.js"></script>
</head>
<body>
<header class="si-nav">
  <button type="button" class="si-back" id="backBtn" aria-label="返回"><img src="/Style/newimg/leftar.png" alt=""></button>
  <div class="si-nav-title">修改密码</div>
</header>
<main>
  <section class="us-card">
    <label class="us-row"><span class="us-label">输入原密码</span><input class="us-input" id="oldpass" type="password" autocomplete="current-password" placeholder="请输入当前登录密码"></label>
    <label class="us-row"><span class="us-label">输入新密码</span><input class="us-input" id="pass" type="password" autocomplete="new-password" placeholder="至少8位，含大小写字母"></label>
    <label class="us-row"><span class="us-label">再次输入密码</span><input class="us-input" id="repass" type="password" autocomplete="new-password" placeholder="再次输入新密码"></label>
    <div class="us-error" id="error"></div>
    <div class="us-action"><button class="us-submit" type="button" id="submitBtn">提交</button></div>
  </section>
  <p class="us-note">修改成功后，新密码会同步到该账号已加入的所有房间。</p>
</main>
<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  document.getElementById('backBtn').onclick = function () { location.replace('/Templates/user/setting.php'); };
  document.getElementById('submitBtn').onclick = function () {
    var oldpass = document.getElementById('oldpass').value;
    var pass = document.getElementById('pass').value;
    var repass = document.getElementById('repass').value;
    var error = document.getElementById('error');
    error.textContent = '';
    if (!oldpass) { error.textContent = '请输入原密码'; return; }
    if (pass.length < 8 || !/[a-z]/.test(pass) || !/[A-Z]/.test(pass)) { error.textContent = '密码至少8位，且必须包含大写和小写字母'; return; }
    if (pass !== repass) { error.textContent = '两次输入密码不一致'; return; }
    fetch('/action.php?do=uppass', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-TOKEN':csrf}, body:new URLSearchParams({oldpass:oldpass,newpass:pass,_csrf:csrf}).toString() })
      .then(function (res) { return res.json(); }).then(function (res) {
        if (res && (res.status == 1 || (res.msg && res.msg.status == 1))) { if (typeof jqtoast === 'function') jqtoast('操作成功'); setTimeout(function () { location.replace('/Templates/user/setting.php'); }, 500); }
        else error.textContent = (res && (res.msg && res.msg.msg ? res.msg.msg : res.msg)) || '更新失败，请重试';
      }).catch(function () { error.textContent = '网络错误，请重试'; });
  };
}());
</script>
</body>
</html>

