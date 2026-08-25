/**
 * 弹珠28 / PC28 开奖球：波色圆球 + 加法等式
 * 绿 1/4/7… 蓝 2/5/8… 红 3/6/9… 黄 0/13/14/27
 */
(function (global) {
  function pc28Wave(n) {
    n = parseInt(n, 10);
    if (isNaN(n)) return 'y';
    if (n === 0 || n === 13 || n === 14 || n === 27) return 'y';
    var m = n % 3;
    if (m === 1) return 'g';
    if (m === 2) return 'b';
    return 'r';
  }

  function pc28BallHtml(n, extraClass) {
    var num = parseInt(n, 10);
    if (isNaN(num)) num = 0;
    var cls = 'pc28-ball pc-' + pc28Wave(num) + (extraClass ? ' ' + extraClass : '');
    return '<span class="' + cls + '">' + num + '</span>';
  }

  function pc28OpHtml(op, light) {
    return '<span class="pc28-op' + (light ? ' is-light' : '') + '">' + op + '</span>';
  }

  /** 大厅/房间：n1 + n2 + n3 = sum */
  function pc28EquationHtml(nums, opts) {
    opts = opts || {};
    var a = parseInt(nums[0], 10);
    var b = parseInt(nums[1], 10);
    var c = parseInt(nums[2], 10);
    if (isNaN(a) || isNaN(b) || isNaN(c)) return '';
    var sum = a + b + c;
    var light = !!opts.light;
    return (
      pc28BallHtml(a) +
      pc28OpHtml('+', light) +
      pc28BallHtml(b) +
      pc28OpHtml('+', light) +
      pc28BallHtml(c) +
      pc28OpHtml('=', light) +
      pc28BallHtml(sum, 'is-sum')
    );
  }

  /** 历史结果文案色：大→红，小→蓝 */
  function pc28ResultTextClass(dx) {
    return String(dx) === '大' ? 'pc28-txt-da' : 'pc28-txt-xiao';
  }

  global.pc28Wave = pc28Wave;
  global.pc28BallHtml = pc28BallHtml;
  global.pc28EquationHtml = pc28EquationHtml;
  global.pc28ResultTextClass = pc28ResultTextClass;
})(window);
