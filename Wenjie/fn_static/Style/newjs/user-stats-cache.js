/*! 大厅/房间右上角积分缓存：设备本地秒显，后台静默刷新 */
(function (w) {
  'use strict';
  var KEY_PREFIX = 'fn_user_stats_v1_';
  var FIELDS = ['user_money', 'user_earn', 'user_hs', 'user_ls'];
  var prefetching = false;

  function roomId() {
    return String(w.ROOM_ROOMID || w.FEINIAO_ROOM_ID || '');
  }
  function userId() {
    return String(w.ROOM_BOOT_USERID || w.FEINIAO_USER_ID || '');
  }
  function storageKey() {
    var r = roomId() || '0';
    var u = userId() || '0';
    return KEY_PREFIX + r + '_' + u;
  }
  function fmt(v, field) {
    if (v == null || v === '') return '0.00';
    var n = Number(v);
    if (!isFinite(n)) return String(v);
    if (field === 'user_earn' || field === 'user_ls') return String(Math.round(n));
    return n.toFixed(2);
  }
  function read() {
    try {
      var raw = localStorage.getItem(storageKey());
      if (!raw) return null;
      var o = JSON.parse(raw);
      return o && typeof o === 'object' ? o : null;
    } catch (e) { return null; }
  }
  function write( partial ) {
    if (!partial) return;
    var cur = read() || {};
    var next = {};
    for (var i = 0; i < FIELDS.length; i++) {
      var k = FIELDS[i];
      if (typeof partial[k] !== 'undefined' && partial[k] !== null && partial[k] !== '') {
        next[k] = fmt(partial[k], k);
      } else if (typeof cur[k] !== 'undefined') {
        next[k] = cur[k];
      }
    }
    next.ts = Date.now();
    try { localStorage.setItem(storageKey(), JSON.stringify(next)); } catch (e) {}
    return next;
  }
  function setEl(id, val) {
    var el = document.getElementById(id);
    if (!el) return;
    var s = fmt(val, id);
    if (el.textContent !== s) el.textContent = s;
    try { if (w.jQuery) w.jQuery(el).html(s); } catch (e) {}
  }
  function paint(data) {
    if (!data) return;
    if (typeof data.user_money !== 'undefined') setEl('user_money', data.user_money);
    if (typeof data.user_earn !== 'undefined') setEl('user_earn', data.user_earn);
    if (typeof data.user_hs !== 'undefined') setEl('user_hs', data.user_hs);
    if (typeof data.user_ls !== 'undefined') setEl('user_ls', data.user_ls);
  }
  function applyFromRes(res) {
    if (!res || typeof res !== 'object') return;
    var d = {};
    var hit = false;
    for (var i = 0; i < FIELDS.length; i++) {
      var k = FIELDS[i];
      if (typeof res[k] !== 'undefined') { d[k] = res[k]; hit = true; }
    }
    if (!hit) return;
    paint(d);
    write(d);
  }
  function bootPaint() {
    // 1) 设备缓存秒显
    paint(read());
    // 2) 服务端首屏注入（若有）覆盖
    if (w.ROOM_BOOT_STATS) paint(w.ROOM_BOOT_STATS), write(w.ROOM_BOOT_STATS);
  }
  function prefetch() {
    if (!roomId() && !w.FEINIAO_ROOM_ID) return;
    if (prefetching) return;
    prefetching = true;
    function done() { prefetching = false; }
    try {
      var url = '/Application/ajax_user_stats.php?_=' + Date.now();
      if (w.jQuery && w.jQuery.getJSON) {
        w.jQuery.getJSON(url).done(function (res) {
          if (res && res.success) applyFromRes(res);
        }).always(done);
      } else if (w.fetch) {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (res) { if (res && res.success) applyFromRes(res); })
          .catch(function () {})
          .then(done, done);
      } else {
        done();
      }
    } catch (e) { done(); }
  }
  function hookJquery() {
    if (!w.jQuery || w.jQuery.__fnStatsHooked) return;
    var $ = w.jQuery;
    $.fn.__fnStatsHooked = true;
    w.jQuery.__fnStatsHooked = true;
    var map = { user_money: 1, user_earn: 1, user_hs: 1, user_ls: 1 };
    function wrap(method) {
      var orig = $.fn[method];
      if (!orig || orig.__fnStats) return;
      var wrapped = function (v) {
        // 轮询直接写入 DOM 时也必须用与首屏相同的整数规则。
        if (arguments.length > 0 && this.length && this[0].id && map[this[0].id]) {
          v = fmt(v, this[0].id);
          arguments[0] = v;
        }
        var ret = orig.apply(this, arguments);
        if (arguments.length > 0 && this.length) {
          this.each(function () {
            if (this.id && map[this.id]) {
              var o = {};
              o[this.id] = v;
              write(o);
            }
          });
        }
        return ret;
      };
      wrapped.__fnStats = true;
      $.fn[method] = wrapped;
    }
    wrap('html');
    wrap('text');
  }

  var api = {
    read: read,
    write: write,
    paint: paint,
    applyFromRes: applyFromRes,
    bootPaint: bootPaint,
    prefetch: prefetch,
    hookJquery: hookJquery
  };
  w.FnUserStats = api;

  // 尽早绘一次（元素已在则立刻，否则等 DOM）
  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  ready(function () {
    bootPaint();
    hookJquery();
    // 房间首屏已有服务端余额时，先让页面完成可交互，再静默校正，避免
    // ajax_user_stats 与 HTML、图片、聊天请求在同一瞬间争抢连接和 PHP session。
    // 大厅没有首屏注入时也稍微后置，避免进入动画期间触发额外请求。
    setTimeout(prefetch, w.ROOM_BOOT_STATS ? 1200 : 400);
  });
})(window);
