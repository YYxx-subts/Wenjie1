/*
 * 个人中心页面的根节点约束。
 *
 * 这里刻意不注册 touchstart/touchmove，也绝不调用 preventDefault：
 * 原生左缘返回由 WebViewController 的 UIScreenEdgePanGestureRecognizer 完整接管。
 * 页面防止背景跟随橡皮筋仅依赖根节点与明确内容容器的 CSS 分层。
 */
(function () {
  'use strict';

  function installRootLock() {
    if (document.getElementById('fnUserPageRootLock')) return;
    var style = document.createElement('style');
    style.id = 'fnUserPageRootLock';
    style.textContent =
      'html{height:100%;overflow:hidden!important;overscroll-behavior:none!important;}' +
      'body{min-height:100%;overscroll-behavior:none!important;}' +
      '.pc-page,.um-page,.rl-page,.vip-page,.vd-main,.wx_cfb_account_center_container{' +
        'overscroll-behavior:contain!important;-webkit-overflow-scrolling:touch;' +
      '}' +
      '.pc-page,.um-page,.rl-page,.vip-page,.vd-main,.wx_cfb_account_center_container{' +
        'overflow-x:hidden!important;' +
      '}';
    (document.head || document.documentElement).appendChild(style);
  }

  installRootLock();
  document.addEventListener('DOMContentLoaded', installRootLock, { once: true });
}());
