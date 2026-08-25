/**
 * 房间 VIP 气泡渲染 + 进场特效
 */
(function (window) {
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function safeContentHtml(value) {
    var template = document.createElement('template');
    template.innerHTML = String(value == null ? '' : value);
    var blocked = template.content.querySelectorAll(
      'script,iframe,object,embed,link,meta,base,form,input,button,textarea,select,svg,math'
    );
    for (var i = 0; i < blocked.length; i++) blocked[i].remove();
    var nodes = template.content.querySelectorAll('*');
    for (var n = 0; n < nodes.length; n++) {
      var el = nodes[n];
      var attrs = Array.prototype.slice.call(el.attributes || []);
      for (var a = 0; a < attrs.length; a++) {
        var name = String(attrs[a].name || '').toLowerCase();
        var raw = String(attrs[a].value || '').trim();
        if (name.indexOf('on') === 0 || name === 'srcdoc' || name === 'xlink:href') {
          el.removeAttribute(attrs[a].name);
          continue;
        }
        if (name === 'src' || name === 'href') {
          var allowed = raw.charAt(0) === '/' || raw.charAt(0) === '#'
            || /^https:\/\//i.test(raw)
            || (name === 'src' && /^data:image\/(?:png|jpeg|gif|webp);base64,/i.test(raw));
          if (!allowed) el.removeAttribute(attrs[a].name);
        }
      }
      if (el.tagName === 'A' && el.getAttribute('target') === '_blank') {
        el.setAttribute('rel', 'noopener noreferrer');
      }
    }
    return template.innerHTML;
  }

  function headerHtml(row, side) {
    var src = row && row.headimg ? String(row.headimg) : '';
    // 旧头像域名需要改回当前房间本域，否则 WebView 会因 Cookie/跨域策略显示破图。
    src = src.replace(/^https?:\/\/(?:wjagent|fnagent|fnh5)\.atmyx\.app(\/upload\/)/i, '$1');
    if (!src) return '';
    var img = '<img src="' + esc(src) + '" onerror="this.onerror=null;this.src=\'/Style/newimg/laba.png\'">';
    if (row.vipFrame && row.vipFrameClass) {
      return '<span class="' + esc(row.vipFrameClass) + '">' + img + '</span>';
    }
    return img;
  }

  function nickHtml(row, isRight) {
    var name = esc(row && row.nickname ? row.nickname : '');
    var time = esc(row && row.addtime ? row.addtime : '');
    var badge = '';
    if (row && row.vipBadge && row.vipBadgeIcon) {
      badge = '<img class="vip-chat-badge" src="' + esc(row.vipBadgeIcon) + '" alt="V' + esc(row.vipLevel || 0) + '">';
    }
    var adminBadge = '';
    var messageUserId = String(row && row.userid != null ? row.userid : '').toLowerCase();
    if (messageUserId === 'system' || messageUserId === 'admin') {
      adminBadge = '<img class="admin-chat-badge" src="/Style/newimg/glylogo.png?v=20260817adminlogo1" alt="管理员">';
    }
    var cls = 'nick-name' + (row && row.vipNameColor ? ' is-vip-hl' : '');
    if (isRight) {
      return '<div class="' + cls + '">' + time + '  ' + name + adminBadge + badge + '</div>';
    }
    return '<div class="' + cls + '">' + name + adminBadge + badge + '  ' + time + '</div>';
  }

  function bubbleHtml(row, username) {
    var id = row && row.id != null ? row.id : '';
    var rawContent = String(row && row.content != null ? row.content : '');
    var contentHtml = safeContentHtml(rawContent);
    // 封盘倒计时是房间级公告，不属于管理员聊天消息：不显示头像、昵称、时间和 VIP 标识。
    if (rawContent.indexOf('fn-close-countdown-card') !== -1) {
      return (
        '<div id="msg' + id + '" class="fn-close-countdown-notice" role="status" aria-label="封盘公告">' +
        '<div class="fn-close-countdown-notice-inner">' + contentHtml + '</div></div>'
      );
    }
    var headerType = username !== (row && row.nickname) ? 'left-header' : 'right-header';
    var isRight = headerType === 'right-header';
    var leftHeader = !isRight ? headerHtml(row, 'left') : '';
    var rightHeader = isRight ? headerHtml(row, 'right') : '';
    return (
      '<div id="msg' + id + '" class="' + headerType + '">' +
      '<div class="lheader">' + leftHeader + '</div>' +
      '<div class="content-box">' +
      nickHtml(row, isRight) +
      '<div class="content-info"><div class="chatnarr"></div>' +
      '<div class="info-content">' + contentHtml + '</div></div></div>' +
      '<div class="rheader">' + rightHeader + '</div></div>'
    );
  }

  function ensureEntryDom() {
    var el = document.getElementById('fnVipEntry');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'fnVipEntry';
    el.className = 'fn-vip-entry';
    el.setAttribute('hidden', 'hidden');
    el.innerHTML = '<div class="fn-vip-entry-inner"><span class="fn-vip-entry-text"></span></div>';
    document.body.appendChild(el);
    return el;
  }

  function tryEntry() {
    try {
      if (!window.sessionStorage) return;
      var key = 'fn_vip_entry_' + (window.ROOM_GAME_CODE || 'room');
      if (sessionStorage.getItem(key) === '1') return;
      if (typeof window.jQuery === 'undefined') return;
      window.jQuery.ajax({
        url: '/Application/ajax_vip.php',
        method: 'GET',
        dataType: 'json',
        data: { type: 'entry' },
        success: function (res) {
          if (!res || !res.success || !res.data) return;
          sessionStorage.setItem(key, '1');
          var el = ensureEntryDom();
          var text = (res.data.text || ((res.data.username || '') + ' 驾到'));
          var tip = el.querySelector('.fn-vip-entry-text');
          if (tip) tip.textContent = text;
          el.removeAttribute('hidden');
          el.classList.add('is-show');
          setTimeout(function () {
            el.classList.remove('is-show');
            el.setAttribute('hidden', 'hidden');
          }, 2800);
        }
      });
    } catch (e) {}
  }

  window.FeiniaoVipRoom = {
    bubbleHtml: bubbleHtml,
    tryEntry: tryEntry
  };
})(window);
