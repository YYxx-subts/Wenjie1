(function (window, $) {
  if (!$) return;
  var ROOT_ID = 'fnHsSheet';
  var busy = false;
  var infoRequest = null;
  var infoData = null;
  var infoAt = 0;

  function ensureDom() {
    if (document.getElementById(ROOT_ID)) return;
    var html =
      '<div id="' + ROOT_ID + '" class="fn-hs-mask" style="display:none">' +
      '  <div class="fn-hs-sheet">' +
      '    <div class="fn-hs-title">自助回水</div>' +
      '    <div class="fn-hs-row"><span>回水积分</span><b id="fnHsLiu">0.00</b></div>' +
      '    <div class="fn-hs-row"><span>可返积分</span><b id="fnHsAvail">0.00</b></div>' +
      '    <div class="fn-hs-row fn-hs-vip" id="fnHsVipRow" style="display:none"><span>含 VIP 加成</span><b id="fnHsVipBonus">0%</b></div>' +
      '    <div class="fn-hs-actions">' +
      '      <button type="button" class="fn-hs-cancel" id="fnHsCancel">取消回水</button>' +
      '      <button type="button" class="fn-hs-ok" id="fnHsOk">确认回水</button>' +
      '    </div>' +
      '  </div>' +
      '</div>';
    $('body').append(html);
    $('#fnHsCancel, #' + ROOT_ID).on('click', function (e) {
      if (e.target === this || $(e.target).hasClass('fn-hs-cancel')) close();
    });
    $('.fn-hs-sheet').on('click', function (e) { e.stopPropagation(); });
    $('#fnHsOk').on('click', function () { claim(); });
  }

  function fmt(n) {
    var x = Number(n || 0);
    if (!isFinite(x)) x = 0;
    return x.toFixed(2);
  }

  function fill(data) {
    // 回水积分 = 已回水；可返积分 = 还可领
    var claimed = data && (data.rebatePoints != null ? data.rebatePoints : data.claimed);
    var avail = data && (data.availablePoints != null ? data.availablePoints : data.available);
    $('#fnHsLiu').text(fmt(claimed));
    $('#fnHsAvail').text(fmt(avail));
    var bonus = data && Number(data.vipBonusPct || 0);
    if (bonus > 0) {
      $('#fnHsVipBonus').text('+' + bonus + '%（比例 ' + fmt(data.rate) + '%）');
      $('#fnHsVipRow').show();
    } else {
      $('#fnHsVipRow').hide();
    }
  }

  function loadInfo(force) {
    if (!force && infoRequest) return infoRequest;
    if (!force && infoData && (Date.now() - infoAt) <= 2000) {
      return $.Deferred().resolve({ success: true, data: infoData }).promise();
    }
    infoRequest = $.ajax({
      url: '/Application/ajax_huishui.php?type=info',
      method: 'get',
      dataType: 'json'
    }).done(function (res) {
      if (res && res.success && res.data) {
        infoData = res.data;
        infoAt = Date.now();
      }
    }).always(function () {
      infoRequest = null;
    });
    return infoRequest;
  }

  function open() {
    ensureDom();
    $('#' + ROOT_ID).css('display', 'flex');
    if (infoData) fill(infoData);
    loadInfo(false).done(function (res) {
      if (res && res.success && res.data) fill(res.data);
      else if (typeof jqtoast === 'function') jqtoast((res && res.msg) || '加载失败');
    }).fail(function () {
      if (typeof jqtoast === 'function') jqtoast('网络错误');
    });
  }

  function close() {
    $('#' + ROOT_ID).hide();
  }

  function claim() {
    if (busy) return;
    busy = true;
    $.ajax({
      url: '/Application/ajax_huishui.php?type=claim',
      method: 'post',
      dataType: 'json',
      success: function (res) {
        busy = false;
        if (!res || !res.success) {
          if (typeof jqtoast === 'function') jqtoast((res && res.msg) || '回水失败');
          if (res && res.data) fill(res.data);
          return;
        }
        if (res.data) fill(res.data);
        infoData = res.data || null;
        infoAt = Date.now();
        if (typeof res.money !== 'undefined') {
          try { $('#user_money').text(fmt(res.money)); } catch (e) {}
        }
        try {
          // 顶部回水 = 已回水积分
          var claimed = res.data && (res.data.claimed != null ? res.data.claimed : res.data.rebatePoints);
          $('#user_hs').text(fmt(claimed));
        } catch (e) {}
        if (typeof jqtoast === 'function') jqtoast(res.msg || ('已领取 ' + fmt(res.amount)));
        close();
      },
      error: function () {
        busy = false;
        if (typeof jqtoast === 'function') jqtoast('网络错误');
      }
    });
  }

  $(function () {
    ensureDom();
    // 房间稳定后提前取一次，点击弹窗时直接复用结果；领取仍走强制实时计算。
    window.setTimeout(function () { loadInfo(false); }, 300);
    $(document).on('click', '.fast-keyboard-url li[data-action="huishui"]', function (e) {
      e.preventDefault();
      e.stopPropagation();
      open();
    });
  });

  window.FeiniaoHuishui = { open: open, close: close };
})(window, window.jQuery);
