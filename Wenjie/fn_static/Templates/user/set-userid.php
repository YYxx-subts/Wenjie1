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
  <title>修改昵称</title>
  <link rel="stylesheet" href="/Style/newcss/user-subpage.css?v=20260815usersub1">
</head>
<body>
<header class="si-nav">
  <button type="button" class="si-back" id="backBtn" aria-label="返回"><img src="/Style/newimg/leftar.png" alt=""></button>
  <div class="si-nav-title">修改昵称</div>
</header>
<main>
  <section class="us-card">
    <label class="us-row"><span class="us-label">新昵称</span><input class="us-input" id="username" type="text" maxlength="20" autocomplete="off" placeholder="请输入新昵称"></label>
    <div class="us-error" id="error"></div>
    <div class="us-action"><button class="us-submit" type="button" id="submitBtn">提交</button></div>
  </section>
</main>
<script>
(function () {
  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  document.getElementById('backBtn').onclick = function () { location.replace('/Templates/user/setting.php'); };
  document.getElementById('submitBtn').onclick = function () {
    var username = document.getElementById('username').value.trim();
    var error = document.getElementById('error'); error.textContent = '';
    if (!username) { error.textContent = '昵称不能为空'; return; }
    if (username.length > 20) { error.textContent = '昵称不能超过20个字'; return; }
    fetch('/action.php?do=upuserid', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-TOKEN':csrf}, body:new URLSearchParams({username:username,_csrf:csrf}).toString() })
      .then(function (res) { return res.json(); }).then(function (res) {
        if (res && (res.status == 1 || (res.msg && res.msg.status == 1))) setTimeout(function () { location.replace('/Templates/user/setting.php'); }, 350);
        else error.textContent = (res && (res.msg && res.msg.msg ? res.msg.msg : res.msg)) || '更新失败，请重试';
      }).catch(function () { error.textContent = '网络错误，请重试'; });
  };
}());
</script>
</body>
</html>

