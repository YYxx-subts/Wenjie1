(function () {
  'use strict';

  var mask, picture, bg, hole, piece, slider, handle, progress, status, audioButton;
  var challenge = null;
  var target = 0;
  var startedAt = 0;
  var dragging = false;
  var startX = 0;
  var startLeft = 0;
  var trace = [];
  var verifiedCallback = null;
  var captchaImageUrls = [
    '/Style/newimg/captcha/fast/landscape-01.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-02.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-03.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-04.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-05.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-06.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-07.webp?v=20260816fast1',
    '/Style/newimg/captcha/fast/landscape-08.webp?v=20260816fast1'
  ];
  var prewarmedCaptchaImages = [];

  function prewarmCaptchaImages() {
    if (prewarmedCaptchaImages.length) return;
    captchaImageUrls.forEach(function (src) {
      var image = new Image();
      image.decoding = 'async';
      image.fetchPriority = 'low';
      image.src = src;
      prewarmedCaptchaImages.push(image);
    });
  }

  function scheduleCaptchaPrewarm() {
    var run = function () { prewarmCaptchaImages(); };
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(run, { timeout: 1200 });
    } else {
      window.setTimeout(run, 500);
    }
  }

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function post(url, data) {
    data = data || {};
    data._csrf = csrf();
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-TOKEN': csrf() },
      body: new URLSearchParams(data).toString()
    }).then(function (res) { return res.json(); });
  }

  function build() {
    if (mask) return;
    mask = document.createElement('div');
    mask.className = 'fn-security-mask';
    mask.innerHTML = '' +
      '<div class="fn-captcha-dialog"><button type="button" class="fn-captcha-close" aria-label="关闭安全验证"><img src="/Style/newimg/captcha/icons/x-lg.svg" alt=""></button><section class="fn-captcha-card" role="dialog" aria-modal="true" aria-label="安全验证">' +
        '<div class="fn-security-head"><div class="fn-security-title">请完成安全验证</div></div>' +
        '<div class="fn-captcha-body">' +
          '<div class="fn-captcha-picture"><img class="fn-captcha-bg" alt="验证图片"><div class="fn-captcha-hole"></div><div class="fn-captcha-piece"></div><div class="fn-captcha-tools"><button type="button" class="fn-captcha-tool fn-captcha-refresh" aria-label="刷新验证图片"><img src="/Style/newimg/captcha/icons/arrow-clockwise.svg" alt=""></button><button type="button" class="fn-captcha-tool fn-captcha-audio" aria-label="播放语音提示"><img src="/Style/newimg/captcha/icons/headphones.svg" alt=""></button></div></div>' +
          '<div class="fn-captcha-slider"><div class="fn-captcha-progress"></div><div class="fn-captcha-tip">向右拖动滑块填充拼图</div><div class="fn-captcha-handle"><img src="/Style/newimg/captcha/icons/arrow-right.svg" alt=""></div></div>' +
          '<div class="fn-captcha-status" aria-live="polite"></div>' +
        '</div>' +
      '</section></div>';
    document.body.appendChild(mask);
    picture = mask.querySelector('.fn-captcha-picture');
    bg = mask.querySelector('.fn-captcha-bg');
    hole = mask.querySelector('.fn-captcha-hole');
    piece = mask.querySelector('.fn-captcha-piece');
    slider = mask.querySelector('.fn-captcha-slider');
    handle = mask.querySelector('.fn-captcha-handle');
    progress = mask.querySelector('.fn-captcha-progress');
    status = mask.querySelector('.fn-captcha-status');
    audioButton = mask.querySelector('.fn-captcha-audio');
    mask.querySelector('.fn-captcha-close').addEventListener('click', close);
    mask.querySelector('.fn-captcha-refresh').addEventListener('click', loadChallenge);
    audioButton.addEventListener('click', playInstruction);
    mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
    handle.addEventListener('touchstart', down, { passive: false });
    document.addEventListener('touchmove', move, { passive: false });
    document.addEventListener('touchend', up, { passive: false });
    document.addEventListener('touchcancel', cancel, { passive: false });
    handle.addEventListener('mousedown', down);
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', up);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && mask.classList.contains('show')) close(); });
  }

  function pieceSize() {
    return Math.max(1, piece ? piece.offsetWidth : 36);
  }

  function playInstruction() {
    var message = '请向右拖动滑块，将拼图移动到缺口位置。';
    if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
      status.className = 'fn-captcha-status';
      status.textContent = message;
      return;
    }
    window.speechSynthesis.cancel();
    var utterance = new SpeechSynthesisUtterance(message);
    utterance.lang = 'zh-CN';
    utterance.rate = 1;
    window.speechSynthesis.speak(utterance);
  }

  function clientX(e) {
    if (e.touches && e.touches.length) return e.touches[0].clientX;
    if (e.changedTouches && e.changedTouches.length) return e.changedTouches[0].clientX;
    return e.clientX;
  }

  function resetPosition() {
    dragging = false;
    handle.style.transition = piece.style.transition = progress.style.transition = '';
    handle.style.left = '0px';
    piece.style.left = '0px';
    progress.style.width = '0px';
    handle.innerHTML = '<img src="/Style/newimg/captcha/icons/arrow-right.svg" alt="">';
    status.className = 'fn-captcha-status';
    status.textContent = '';
    trace = [];
  }

  function applyChallenge(res) {
    challenge = res.challenge;
    target = Number(res.target) || 0;
    resetPosition();
    bg.onload = function () {
      var size = pieceSize();
      var w = picture.clientWidth;
      var h = picture.clientHeight;
      var maxX = Math.max(1, w - size);
      var holeX = Math.round(target * maxX);
      var minY = 28;
      var maxY = Math.max(minY, h - size - 12);
      var holeY = Math.round(minY + Math.random() * (maxY - minY));
      hole.style.left = holeX + 'px';
      hole.style.top = holeY + 'px';
      piece.style.top = holeY + 'px';
      piece.style.backgroundImage = 'url("' + res.image + '")';
      piece.style.backgroundSize = w + 'px ' + h + 'px';
      piece.style.backgroundPosition = (-holeX) + 'px ' + (-holeY) + 'px';
      bg.style.opacity = '1';
      hole.style.opacity = '1';
      piece.style.opacity = '1';
    };
    bg.fetchPriority = 'high';
    bg.src = res.image;
  }

  function loadChallenge() {
    challenge = null;
    resetPosition();
    bg.removeAttribute('src');
    bg.style.opacity = '0';
    hole.style.opacity = '0';
    piece.style.opacity = '0';
    status.className = 'fn-captcha-status';
    status.textContent = '正在加载验证图片…';
    post('/Application/ajax_login_security.php?action=challenge', {}).then(function (res) {
      if (!res || res.status != 1) throw new Error((res && res.msg) || '验证加载失败');
      applyChallenge(res);
    }).catch(function (err) {
      status.textContent = err.message || '验证加载失败，请重试';
    });
  }

  function down(e) {
    if (!challenge) return;
    dragging = true;
    startedAt = Date.now();
    startX = clientX(e);
    startLeft = parseFloat(handle.style.left) || 0;
    trace = [[0, 0]];
    e.preventDefault();
  }

  function move(e) {
    if (!dragging) return;
    var max = Math.max(1, slider.clientWidth - handle.clientWidth);
    var left = Math.max(0, Math.min(max, startLeft + clientX(e) - startX));
    var ratio = left / max;
    handle.style.left = left + 'px';
    progress.style.width = (left + handle.clientWidth / 2) + 'px';
    piece.style.left = (ratio * Math.max(1, picture.clientWidth - pieceSize())) + 'px';
    if (!trace.length || Date.now() - trace[trace.length - 1][1] >= 24) trace.push([Math.round(ratio * 10000), Date.now() - startedAt]);
    e.preventDefault();
  }

  function snapBack(message) {
    handle.style.transition = piece.style.transition = progress.style.transition = '.22s';
    handle.style.left = '0px'; piece.style.left = '0px'; progress.style.width = '0px';
    status.className = 'fn-captcha-status'; status.textContent = message || '位置不正确，请重试';
    setTimeout(function () { handle.style.transition = piece.style.transition = progress.style.transition = ''; }, 260);
  }

  function up(e) {
    if (!dragging) return;
    dragging = false;
    var max = Math.max(1, slider.clientWidth - handle.clientWidth);
    var ratio = (parseFloat(handle.style.left) || 0) / max;
    trace.push([Math.round(ratio * 10000), Date.now() - startedAt]);
    if (Math.abs(ratio - target) > .028) {
      snapBack('位置不正确，请重试');
      if (e) e.preventDefault();
      return;
    }
    status.textContent = '正在确认…';
    post('/Application/ajax_login_security.php?action=verify', {
      challenge: challenge,
      position: ratio.toFixed(5),
      elapsed: Date.now() - startedAt,
      trace: JSON.stringify(trace)
    }).then(function (res) {
      if (!res || res.status != 1 || !res.token) throw new Error((res && res.msg) || '验证未通过');
      handle.textContent = '完成';
      status.className = 'fn-captcha-status ok';
      status.textContent = '验证成功';
      var callback = verifiedCallback;
      setTimeout(function () { close(); if (callback) callback(res.token); }, 220);
    }).catch(function (err) {
      challenge = null;
      snapBack(err.message || '验证未通过，请刷新重试');
      setTimeout(loadChallenge, 500);
    });
    if (e) e.preventDefault();
  }

  function cancel(e) {
    if (!dragging) return;
    dragging = false;
    snapBack('请重新拖动滑块');
    if (e) e.preventDefault();
  }

  function close() {
    if (!mask) return;
    mask.classList.remove('show');
    document.body.classList.remove('fn-security-open');
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    challenge = null;
    verifiedCallback = null;
    resetPosition();
  }

  function open(callback) {
    build();
    verifiedCallback = callback;
    document.body.classList.add('fn-security-open');
    mask.classList.add('show');
    loadChallenge();
  }

  function requestOtp(callback) {
    var otpMask = document.createElement('div');
    otpMask.className = 'fn-security-mask show';
    otpMask.innerHTML = '<section class="fn-otp-card" role="dialog" aria-modal="true" aria-label="二次验证"><div class="fn-security-head"><div class="fn-security-title">二次验证</div><button type="button" class="fn-security-close" aria-label="关闭">关闭</button></div><div class="fn-otp-body"><div class="fn-otp-copy">请输入 Google 验证器中当前显示的 6 位动态验证码。</div><input class="fn-otp-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" aria-label="6位动态验证码"><button type="button" class="fn-otp-submit">确认登录</button><div class="fn-otp-error"></div></div></section>';
    document.body.appendChild(otpMask);
    var input = otpMask.querySelector('.fn-otp-input');
    var error = otpMask.querySelector('.fn-otp-error');
    function remove() { if (otpMask.parentNode) otpMask.parentNode.removeChild(otpMask); }
    otpMask.querySelector('.fn-security-close').onclick = remove;
    otpMask.querySelector('.fn-otp-submit').onclick = function () {
      var code = String(input.value || '').replace(/\D/g, '');
      if (code.length !== 6) { error.textContent = '请输入 6 位动态验证码'; return; }
      remove(); callback(code);
    };
    input.addEventListener('input', function () { input.value = String(input.value || '').replace(/\D/g, '').slice(0, 6); error.textContent = ''; });
    setTimeout(function () { input.focus(); }, 60);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleCaptchaPrewarm, { once: true });
  } else {
    scheduleCaptchaPrewarm();
  }

  window.FnLoginSecurity = { open: open, requestOtp: requestOtp };
}());
