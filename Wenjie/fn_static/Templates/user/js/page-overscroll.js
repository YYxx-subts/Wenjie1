/**
 * 记录页：禁止整页橡皮筋拉伸，仅允许 .rl-list 正常滚动
 */
(function () {
  try {
    document.documentElement.style.overscrollBehavior = 'none';
    document.body.style.overscrollBehavior = 'none';
  } catch (e) {}

  function closestScroller(node) {
    var el = node;
    while (el && el !== document.body && el !== document.documentElement) {
      if (el.classList && el.classList.contains('rl-list')) return el;
      el = el.parentNode;
    }
    return null;
  }

  var startY = 0;
  document.addEventListener('touchstart', function (e) {
    if (!e.touches || !e.touches.length) return;
    startY = e.touches[0].clientY;
  }, { passive: true, capture: true });

  document.addEventListener('touchmove', function (e) {
    if (!e.touches || !e.touches.length) return;
    var tag = (e.target && e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

    var scroller = closestScroller(e.target);
    var dy = e.touches[0].clientY - startY;

    if (!scroller) {
      e.preventDefault();
      return;
    }

    var top = scroller.scrollTop;
    var max = scroller.scrollHeight - scroller.clientHeight;
    if (max < 0) max = 0;

    if ((top <= 0 && dy > 0) || (top >= max - 1 && dy < 0)) {
      e.preventDefault();
    }
  }, { passive: false, capture: true });
})();
