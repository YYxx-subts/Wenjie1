/**
 * 游戏房间：滑到顶/底后继续拖动时阻止整页橡皮筋拉伸（iOS/WebView）
 * 注意：绝不能把「轻点」当成拖动拦截，否则返回/进房要点很多次。
 */
(function () {
  function closestScrollable(node) {
    var el = node;
    while (el && el !== document.body && el !== document.documentElement) {
      // iframeBox 是弹出的 P1-P5 内容外壳。它本身不能作为整块滚动容器，
      // 否则在空白区域上下拖动会把整个弹层带着页面一起拖动；需要滚动时由
      // 弹层内部明确声明的滚动区域（例如注单详情 body）接管。
      if (el.id === 'iframeBox') return null;
      if (el.classList && (
        el.classList.contains('chat-content') ||
        el.classList.contains('keybaord') ||
        el.classList.contains('fn-bet-detail-body') ||
        el.classList.contains('rl-list') ||
        el.classList.contains('pc-page') ||
        el.classList.contains('um-page') ||
        el.classList.contains('or-list')
      )) {
        var maxScroll = el.scrollHeight - el.clientHeight;
        if (el.classList.contains('fn-bet-detail-body') || el.classList.contains('or-list') || maxScroll > 0) return el;
      }
      el = el.parentNode;
    }
    return null;
  }

  function isInteractive(node) {
    var el = node;
    while (el && el !== document.body && el !== document.documentElement) {
      if (!el.tagName) {
        el = el.parentNode;
        continue;
      }
      var tag = el.tagName.toLowerCase();
      if (tag === 'a' || tag === 'button' || tag === 'input' || tag === 'textarea' || tag === 'select' || tag === 'label') {
        return true;
      }
      if (el.classList && (
        el.classList.contains('back') ||
        el.classList.contains('fab-btn') ||
        el.classList.contains('game-box') ||
        el.classList.contains('hall-cat-btn') ||
        el.classList.contains('room-live-guide-ok') ||
        el.classList.contains('upstream-maintenance-mask') ||
        el.classList.contains('upstream-maintenance-panel') ||
        el.id === 'goback'
      )) {
        return true;
      }
      el = el.parentNode;
    }
    return false;
  }

  var startY = 0;
  var startX = 0;

  document.addEventListener('touchstart', function (e) {
    if (!e.touches || !e.touches.length) return;
    startY = e.touches[0].clientY;
    startX = e.touches[0].clientX;
  }, { passive: true, capture: true });

  document.addEventListener('touchmove', function (e) {
    if (!e.touches || !e.touches.length) return;
    if (/do=(login|reg|roomdoor)\b/i.test(location.href)) return;
    var target = e.target;
    var active = document.activeElement;
    // 某些 App 路由会隐藏 do 参数；只要触点或当前焦点在表单控件上，
    // 就不能在捕获阶段 preventDefault，否则会让 WebView 丢失输入焦点。
    if ((target && target.closest && target.closest('input,textarea,select,[contenteditable="true"]')) ||
        (active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName))) return;
    if (isInteractive(target)) return;

    var tag = (target && target.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'input' || tag === 'select') return;

    var dy = e.touches[0].clientY - startY;
    var dx = e.touches[0].clientX - startX;
    // 位移很小：当作点击，绝不 preventDefault（否则会吞掉后续 click）
    if (Math.abs(dy) < 10 && Math.abs(dx) < 10) return;

    var scroller = closestScrollable(target);

    if (!scroller) {
      e.preventDefault();
      return;
    }

    var maxScroll = scroller.scrollHeight - scroller.clientHeight;
    if (maxScroll <= 0) {
      e.preventDefault();
      return;
    }

    var top = scroller.scrollTop;
    if ((top <= 0 && dy > 0) || (top >= maxScroll - 1 && dy < 0)) {
      e.preventDefault();
    }
  }, { passive: false, capture: true });
})();
