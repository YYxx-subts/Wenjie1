<?php
include dirname(dirname(dirname(preg_replace('@\(.*\(.*$@', '', __FILE__)))) . "/Public/config.php";
require "function.php";
$info = getinfo($_SESSION['userid']);
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$userid = isset($_SESSION['userid']) ? $_SESSION['userid'] : '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no,viewport-fit=cover" />
    <meta content="telephone=no" name="format-detection">
    <title>代理报表</title>
    <link rel="stylesheet" type="text/css" href="css/common.css?v=1.2" />
    <script type="text/javascript" src="/Style/plus.js?v=20260812pageload8"></script>
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; overflow: hidden; background: #f2f3f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    .ar-page { display: flex; flex-direction: column; height: 100vh; height: 100dvh; overflow: hidden; }
    .ar-nav {
        flex: 0 0 auto; display: flex; align-items: center; justify-content: center; min-height: 44px;
        padding: calc(max(env(safe-area-inset-top, 0px), 20px) + 6px) 12px 10px;
        background: linear-gradient(90deg, #2b7adb 0%, #3aa6e8 52%, #4fc0f0 100%);
    }
    .ar-back {
        position: absolute; left: 8px; bottom: 4px; width: 40px; height: 40px;
        border: 0; background: transparent; display: flex; align-items: center; justify-content: center;
        z-index: 2; outline: none; -webkit-tap-highlight-color: transparent;
    }
    .ar-back img { width: 16px; height: auto; filter: brightness(0) invert(1); }
    .ar-nav-title { margin: 0; text-align: center; font-size: 17px; font-weight: 600; color: #fff; line-height: 40px; }
    /* 固定顶部区域：搜索+日期+汇总 */
    .ar-top { flex: 0 0 auto; overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch; padding: 12px 12px 0; }
    /* 下线会员表格区域：唯一可滚动区域 */
    .ar-table-wrap {
        flex: 1 1 0; overflow-y: auto; overflow-x: hidden; -webkit-overflow-scrolling: touch;
        padding: 0 12px 12px;
    }
    /* 搜索栏 */
    .ar-search { position: relative; margin-bottom: 12px; }
    .ar-search input {
        width: 100%; height: 40px; border: none; border-radius: 20px;
        background: #fff; padding: 0 16px 0 40px; font-size: 14px; color: #333;
        box-shadow: 0 1px 4px rgba(0,0,0,0.06); outline: none;
    }
    .ar-search input::placeholder { color: #bbb; }
    .ar-search-icon {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        width: 16px; height: 16px; opacity: 0.4;
    }
    /* 日期栏 */
    .ar-date-bar {
        display: flex; align-items: center; justify-content: center;
        height: 38px; border-radius: 8px; background: #ebebeb;
        margin-bottom: 12px; cursor: pointer; position: relative;
    }
    .ar-date-bar span { font-size: 14px; color: #888; font-weight: 500; }
    .ar-date-arrow {
        position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
        font-size: 12px; color: #888; transition: transform 0.2s;
    }
    /* 日期展开面板 */
    .ar-date-panel {
        display: none; background: #fff; border-radius: 10px;
        margin-bottom: 12px; padding: 14px 16px;
        box-shadow: 0 1px 6px rgba(0,0,0,0.06);
    }
    .ar-date-panel.show { display: block; }
    .ar-date-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 12px 0; border-bottom: 1px solid #f0f0f0; cursor: pointer;
    }
    .ar-date-row:last-child { border-bottom: none; }
    .ar-date-row-label { font-size: 14px; color: #666; }
    .ar-date-row-value { font-size: 14px; color: #333; font-weight: 500; }
    .ar-date-row-arrow { font-size: 12px; color: #ccc; margin-left: 6px; }
    .ar-date-confirm {
        display: none; margin-top: 10px; width: 100%; height: 38px;
        border: none; border-radius: 8px; background: #2b7adb;
        color: #fff; font-size: 14px; font-weight: 500; cursor: pointer;
    }
    .ar-date-confirm.show { display: block; }
    /* 滚轮日期选择器 */
    .ar-picker-mask {
        display: none; position: fixed; inset: 0; z-index: 999;
        background: rgba(0,0,0,0.45);
        align-items: flex-end; justify-content: center;
    }
    .ar-picker-mask.show { display: flex; }
    .ar-picker-panel {
        width: 100%; background: #fff; border-radius: 14px 14px 0 0;
        padding-bottom: env(safe-area-inset-bottom, 0px);
        animation: arSlideUp 0.25s ease-out;
    }
    @keyframes arSlideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
    .ar-picker-header {
        display: flex; align-items: center; justify-content: space-between;
        height: 48px; padding: 0 16px; border-bottom: 1px solid #e8e8e8;
    }
    .ar-picker-header button {
        border: 0; background: transparent; font-size: 16px; padding: 8px 12px;
        cursor: pointer; -webkit-tap-highlight-color: transparent;
    }
    .ar-picker-cancel { color: #999; }
    .ar-picker-confirm { color: #2b7adb; font-weight: 600; }
    .ar-picker-title { font-size: 15px; color: #333; font-weight: 500; }
    .ar-picker-body {
        display: flex; width: 100%; height: 220px; position: relative;
        overflow: hidden; padding: 0 8px;
    }
    .ar-picker-col {
        flex: 1; position: relative; overflow: hidden; height: 100%;
    }
    .ar-picker-col-mask {
        position: absolute; top: 0; left: 0; right: 0; height: 88px;
        background: linear-gradient(180deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.6) 100%);
        pointer-events: none; z-index: 2;
    }
    .ar-picker-col-mask-bottom {
        position: absolute; bottom: 0; left: 0; right: 0; height: 88px;
        background: linear-gradient(0deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.6) 100%);
        pointer-events: none; z-index: 2;
    }
    .ar-picker-highlight {
        position: absolute; top: 88px; left: 0; right: 0; height: 44px;
        background: #f0f0f0; border-radius: 8px; z-index: 1;
    }
    .ar-picker-scroller {
        position: absolute; top: 0; left: 0; right: 0;
        transition: transform 0.3s cubic-bezier(0.23, 1, 0.32, 1);
        z-index: 3;
    }
    .ar-picker-item {
        height: 44px; display: flex; align-items: center; justify-content: center;
        font-size: 18px; color: #333; user-select: none; -webkit-user-select: none;
    }
    .ar-picker-item.selected { font-weight: 600; }
    /* 汇总卡片 */
    .ar-summary {
        display: flex; background: #fff; border-radius: 12px;
        padding: 16px 8px; margin-bottom: 12px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .ar-summary-cell { flex: 1; text-align: center; }
    .ar-summary-cell .val { font-size: 17px; font-weight: 400; color: #333; }
    .ar-summary-cell .val.red { color: #e74c3c; }
    .ar-summary-cell .lbl { font-size: 12px; color: #999; margin-top: 4px; }
    /* 表格卡片 */
    .ar-table-card {
        background: #fff; border-radius: 12px; overflow: hidden;
        box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .ar-table-header {
        display: flex; padding: 12px 12px 8px; border-bottom: 1px solid #f5f5f5;
    }
    .ar-table-header span { flex: 1; text-align: center; font-size: 13px; color: #666; font-weight: 500; }
    .ar-table-body { min-height: 100px; }
    .ar-table-row {
        display: flex; padding: 14px 12px; border-bottom: 1px solid #f8f8f8;
        align-items: center;
    }
    .ar-table-row span { flex: 1; text-align: center; font-size: 13px; color: #333; font-weight: 400; }
    .ar-table-row span.username { color: #2b7adb; font-weight: 500; }
    .ar-empty {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 60px 20px; color: #ccc;
    }
    .ar-empty svg { width: 80px; height: 80px; opacity: 0.3; margin-bottom: 12px; }
    .ar-empty p { font-size: 14px; }
    .ar-loading { text-align: center; padding: 40px; color: #999; font-size: 14px; }
    /* === 下拉刷新（与 open_results 同款） === */
    .or-ptr{flex:0 0 auto;max-height:0;overflow:hidden;display:flex;flex-direction:row;align-items:center;justify-content:center;color:#333;font-size:13px;background:#fff;transition:max-height .2s;padding:0 16px;}
    .or-ptr.is-active{transition:none;}
    .or-ptr-icon{width:20px;height:20px;flex-shrink:0;margin-right:10px;transition:transform .15s;position:relative;}
    .or-ptr-icon .or-ptr-arrow{font-size:20px;color:#2f86e0;line-height:1;transition:transform .15s;}
    .or-ptr-icon .or-ptr-spinner{position:absolute;top:0;left:0;width:20px;height:20px;border:2px solid #ccc;border-top-color:#2f86e0;border-radius:50%;display:none;}
    .or-ptr.is-loading .or-ptr-arrow{display:none;}
    .or-ptr.is-loading .or-ptr-spinner{display:block;animation:or-spin .6s linear infinite;}
    .or-ptr-info{display:flex;flex-direction:column;align-items:flex-start;}
    .or-ptr-info .or-ptr-text{color:#333;font-size:14px;font-weight:500;line-height:1.3;}
    .or-ptr-info .or-ptr-sub{color:#333;font-size:12px;line-height:1;padding-top:2px;}
    @keyframes or-spin{to{transform:rotate(360deg)}}
    </style>
</head>
<body>
<div class="ar-page">
    <header class="ar-nav" style="position:relative;">
        <button type="button" class="ar-back" onclick="window.history.back()" aria-label="返回">
            <img src="/Style/newimg/leftar.png" alt="">
        </button>
        <h1 class="ar-nav-title">代理报表</h1>
    </header>

    <!-- 固定顶部：搜索+日期+汇总 -->
    <div class="ar-top">
        <div class="ar-search">
            <svg class="ar-search-icon" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" id="arKeyword" placeholder="请输入玩家用户名/昵称" />
        </div>

        <!-- 日期栏（收起状态） -->
        <div class="ar-date-bar" id="arDateBar">
            <span id="arDateText"></span>
            <span class="ar-date-arrow" id="arDateArrow">▲</span>
        </div>

        <!-- 日期展开面板 -->
        <div class="ar-date-panel" id="arDatePanel">
            <div class="ar-date-row" id="arPickStart">
                <span class="ar-date-row-label">开始日期</span>
                <span><span class="ar-date-row-value" id="arStartDateText"></span><span class="ar-date-row-arrow">›</span></span>
            </div>
            <div class="ar-date-row" id="arPickEnd">
                <span class="ar-date-row-label">结束日期</span>
                <span><span class="ar-date-row-value" id="arEndDateText"></span><span class="ar-date-row-arrow">›</span></span>
            </div>
            <button class="ar-date-confirm" id="arDateConfirm">确定</button>
        </div>

        <div class="ar-summary" id="arSummary">
            <div class="ar-summary-cell"><div class="val" id="arTotalFlow">0</div><div class="lbl">旗下流水</div></div>
            <div class="ar-summary-cell"><div class="val red" id="arTotalCommission">0.00</div><div class="lbl">返佣总金额</div></div>
            <div class="ar-summary-cell"><div class="val" id="arPaid">0.00</div><div class="lbl">已返佣</div></div>
            <div class="ar-summary-cell"><div class="val" id="arUnpaid">0.00</div><div class="lbl">未返佣</div></div>
        </div>
    </div>

    <!-- 可滚动的下线会员区域 -->
    <div class="ar-table-wrap" id="arTableWrap">
        <div class="ar-table-card">
            <!-- 下拉刷新 -->
            <div class="or-ptr" id="arPtr">
                <div class="or-ptr-icon">
                    <span class="or-ptr-arrow">↑</span>
                    <div class="or-ptr-spinner"></div>
                </div>
                <div class="or-ptr-info">
                    <span class="or-ptr-text">下拉刷新</span>
                    <span class="or-ptr-sub" id="arPtrTime"></span>
                </div>
            </div>
            <div class="ar-table-header">
                <span>下线会员</span>
                <span>会员流水</span>
                <span>返佣比例</span>
                <span>已返佣</span>
                <span>未返佣</span>
            </div>
            <div class="ar-table-body" id="arTableBody">
                <div class="ar-loading">加载中...</div>
            </div>
        </div>
    </div>
</div>

<!-- 滚轮日期选择器弹窗 -->
<div class="ar-picker-mask" id="arPickerMask">
    <div class="ar-picker-panel">
        <div class="ar-picker-header">
            <button class="ar-picker-cancel" id="arPickerCancel">取消</button>
            <span class="ar-picker-title" id="arPickerTitle">选择日期</span>
            <button class="ar-picker-confirm" id="arPickerConfirm">确定</button>
        </div>
        <div class="ar-picker-body" id="arPickerBody">
            <div class="ar-picker-col" id="arColYear"></div>
            <div class="ar-picker-col" id="arColMonth"></div>
            <div class="ar-picker-col" id="arColDay"></div>
        </div>
    </div>
</div>

<script>
(function(){
    var userInfo = <?php echo json_encode(['userid'=>$userid,'username'=>$username,'roomid'=>$_SESSION['roomid']]); ?>;

    function pad(n) { return String(n).padStart(2, '0'); }
    function fmtDate(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
    function todayStr() { var t = new Date(); return fmtDate(t.getFullYear(), t.getMonth()+1, t.getDate()); }

    var startDate = todayStr();
    var endDate = todayStr();
    var panelOpen = false;
    var pickingWhich = '';

    function updateDateText() {
        document.getElementById('arDateText').textContent = startDate + ' - ' + endDate;
        document.getElementById('arStartDateText').textContent = startDate;
        document.getElementById('arEndDateText').textContent = endDate;
    }
    updateDateText();

    /* ===== 日期栏展开/收起 ===== */
    document.getElementById('arDateBar').addEventListener('click', function() {
        panelOpen = !panelOpen;
        document.getElementById('arDatePanel').classList.toggle('show', panelOpen);
        document.getElementById('arDateArrow').textContent = panelOpen ? '▼' : '▲';
    });

    /* ===== 点击开始/结束日期 → 打开滚轮 ===== */
    document.getElementById('arPickStart').addEventListener('click', function() {
        pickingWhich = 'start';
        document.getElementById('arPickerTitle').textContent = '选择开始日期';
        openPicker(startDate);
    });
    document.getElementById('arPickEnd').addEventListener('click', function() {
        pickingWhich = 'end';
        document.getElementById('arPickerTitle').textContent = '选择结束日期';
        openPicker(endDate);
    });

    /* ===== 确定按钮（展开面板内） ===== */
    document.getElementById('arDateConfirm').addEventListener('click', function() {
        panelOpen = false;
        document.getElementById('arDatePanel').classList.remove('show');
        document.getElementById('arDateArrow').textContent = '▲';
        loadData();
    });

    /* ===== 滚轮日期选择器 ===== */
    var ITEM_H = 44;
    var MASK_H = ITEM_H * 2;

    function createCol(container, items, selectedIdx) {
        var scroller = document.createElement('div');
        scroller.className = 'ar-picker-scroller';
        scroller.style.paddingTop = MASK_H + 'px';
        scroller.style.paddingBottom = MASK_H + 'px';

        items.forEach(function(item, i) {
            var div = document.createElement('div');
            div.className = 'ar-picker-item' + (i === selectedIdx ? ' selected' : '');
            div.textContent = item.label;
            div.dataset.index = i;
            scroller.appendChild(div);
        });

        container.innerHTML = '';
        container.appendChild(document.createElement('div')).className = 'ar-picker-col-mask';
        container.appendChild(document.createElement('div')).className = 'ar-picker-highlight';
        container.appendChild(document.createElement('div')).className = 'ar-picker-col-mask-bottom';
        container.appendChild(scroller);

        var currentIndex = selectedIdx;
        var startY = 0, startOffset = 0, lastOffset = 0;
        var isDragging = false;

        function setOffset(offset) {
            var maxOff = 0;
            var minOff = -(items.length - 1) * ITEM_H;
            if (offset > maxOff) offset = maxOff + (offset - maxOff) * 0.3;
            if (offset < minOff) offset = minOff + (offset - minOff) * 0.3;
            scroller.style.transition = 'none';
            scroller.style.transform = 'translateY(' + offset + 'px)';
            lastOffset = offset;
        }

        function snap() {
            var idx = Math.round(-lastOffset / ITEM_H);
            idx = Math.max(0, Math.min(items.length - 1, idx));
            currentIndex = idx;
            var targetOffset = -idx * ITEM_H;
            scroller.style.transition = 'transform 0.3s cubic-bezier(0.23,1,0.32,1)';
            scroller.style.transform = 'translateY(' + targetOffset + 'px)';
            lastOffset = targetOffset;
            var allItems = scroller.querySelectorAll('.ar-picker-item');
            allItems.forEach(function(el, j) { el.classList.toggle('selected', j === idx); });
        }

        function onStart(e) {
            isDragging = true;
            startY = (e.touches ? e.touches[0] : e).clientY;
            startOffset = lastOffset;
            scroller.style.transition = 'none';
        }
        function onMove(e) {
            if (!isDragging) return;
            e.preventDefault();
            var y = (e.touches ? e.touches[0] : e).clientY;
            setOffset(startOffset + (y - startY));
        }
        function onEnd() {
            if (!isDragging) return;
            isDragging = false;
            snap();
        }

        container.addEventListener('touchstart', onStart, {passive:true});
        container.addEventListener('touchmove', onMove, {passive:false});
        container.addEventListener('touchend', onEnd);
        container.addEventListener('mousedown', onStart);
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onEnd);
        container.addEventListener('wheel', function(e) {
            e.preventDefault();
            var delta = e.deltaY > 0 ? 1 : -1;
            currentIndex = Math.max(0, Math.min(items.length - 1, currentIndex + delta));
            lastOffset = -currentIndex * ITEM_H;
            scroller.style.transition = 'transform 0.2s ease-out';
            scroller.style.transform = 'translateY(' + lastOffset + 'px)';
            scroller.querySelectorAll('.ar-picker-item').forEach(function(el, j) { el.classList.toggle('selected', j === currentIndex); });
        }, {passive:false});

        return { getValue: function() { return items[currentIndex].value; } };
    }

    var NOW = new Date();
    var thisYear = NOW.getFullYear();

    function yearItems() {
        var arr = [];
        for (var y = thisYear - 5; y <= thisYear + 1; y++) arr.push({value: y, label: y + '年'});
        return arr;
    }
    function monthItems() {
        var arr = [];
        for (var m = 1; m <= 12; m++) arr.push({value: m, label: m + '月'});
        return arr;
    }
    function dayItems(y, m) {
        var maxDay = new Date(y, m, 0).getDate();
        var arr = [];
        for (var d = 1; d <= maxDay; d++) arr.push({value: d, label: d + '日'});
        return arr;
    }

    var colYear, colMonth, colDay;

    function openPicker(dateStr) {
        var parts = dateStr.split('-');
        var iy = parseInt(parts[0]), im = parseInt(parts[1]), id = parseInt(parts[2]);
        var yIdx = iy - (thisYear - 5);
        var mIdx = im - 1;
        var maxDay = new Date(iy, im, 0).getDate();
        var dIdx = Math.min(id - 1, maxDay - 1);

        var yearC = document.getElementById('arColYear');
        var monthC = document.getElementById('arColMonth');
        var dayC = document.getElementById('arColDay');

        colYear = createCol(yearC, yearItems(), yIdx);
        colMonth = createCol(monthC, monthItems(), mIdx);
        colDay = createCol(dayC, dayItems(iy, im), dIdx);

        function refreshDays() {
            var y = colYear.getValue(), m = colMonth.getValue();
            var maxD = new Date(y, m, 0).getDate();
            var oldD = Math.min(parseInt(dayC.querySelector('.ar-picker-item.selected')?.textContent) || 1, maxD);
            colDay = createCol(dayC, dayItems(y, m), oldD - 1);
        }
        yearC.ontouchend = yearC.onmouseup = function() { setTimeout(refreshDays, 60); };
        monthC.ontouchend = monthC.onmouseup = function() { setTimeout(refreshDays, 60); };

        document.getElementById('arPickerMask').classList.add('show');
    }

    document.getElementById('arPickerCancel').addEventListener('click', function() {
        document.getElementById('arPickerMask').classList.remove('show');
    });

    document.getElementById('arPickerConfirm').addEventListener('click', function() {
        var y = colYear.getValue(), m = colMonth.getValue(), d = colDay.getValue();
        var dateStr = fmtDate(y, m, d);
        if (pickingWhich === 'start') {
            startDate = dateStr;
        } else {
            endDate = dateStr;
        }
        updateDateText();
        document.getElementById('arPickerMask').classList.remove('show');
    });

    document.getElementById('arPickerMask').addEventListener('click', function(e) {
        if (e.target === this) document.getElementById('arPickerMask').classList.remove('show');
    });

    /* 搜索 */
    var searchTimer = null;
    document.getElementById('arKeyword').addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(loadData, 400);
    });

    /* 加载数据 */
    var loading = false;
    function loadData(finishPull) {
        var keyword = document.getElementById('arKeyword').value.trim();
        var url = 'agent-report-api.php?start=' + encodeURIComponent(startDate) + '&end=' + encodeURIComponent(endDate);
        if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
        if (!finishPull) document.getElementById('arTableBody').innerHTML = '<div class="ar-loading">加载中...</div>';
        loading = true;
        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                loading = false;
                if (!data.ok) {
                    document.getElementById('arTableBody').innerHTML = '<div class="ar-empty"><p>' + (data.message || '加载失败') + '</p></div>';
                    return;
                }
                var rows = data.data.rows || [];
                var sum = data.data.sum || {};
                document.getElementById('arTotalFlow').textContent = Number(sum.turnover || 0).toLocaleString('zh-CN', {minimumFractionDigits:0, maximumFractionDigits:0});
                document.getElementById('arTotalCommission').textContent = Number(sum.total || 0).toFixed(2);
                document.getElementById('arPaid').textContent = Number(sum.paid || 0).toFixed(2);
                document.getElementById('arUnpaid').textContent = Number(sum.unpaid || 0).toFixed(2);
                if (rows.length === 0) {
                    document.getElementById('arTableBody').innerHTML =
                        '<div class="ar-empty">' +
                        '<svg viewBox="0 0 100 100"><rect x="20" y="15" width="55" height="70" rx="4" fill="none" stroke="#ddd" stroke-width="3"/><circle cx="47" cy="45" r="12" fill="none" stroke="#ddd" stroke-width="3"/><path d="M42 65 L52 55 L62 65" fill="none" stroke="#ddd" stroke-width="3"/><line x1="30" y1="25" x2="65" y2="25" stroke="#ddd" stroke-width="2"/><line x1="30" y1="33" x2="58" y2="33" stroke="#ddd" stroke-width="2"/></svg>' +
                        '<p>暂无数据~</p></div>';
                    return;
                }
                var html = '';
                rows.forEach(function(row) {
                    html += '<div class="ar-table-row">' +
                        '<span class="username">' + escHtml(row.nickname || row.username || '--') + '</span>' +
                        '<span>' + Number(row.turnover || 0).toFixed(2) + '</span>' +
                        '<span>' + (row.commissionRate != null ? Number(row.commissionRate).toFixed(1) + '%' : '--') + '</span>' +
                        '<span>' + Number(row.paid || 0).toFixed(2) + '</span>' +
                        '<span>' + Number(row.unpaid || 0).toFixed(2) + '</span>' +
                        '</div>';
                });
                document.getElementById('arTableBody').innerHTML = html;
            })
            .catch(function(e) {
                loading = false;
                document.getElementById('arTableBody').innerHTML = '<div class="ar-empty"><p>网络异常</p></div>';
            });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    /* ===== 下拉刷新（与 open_results 同款） ===== */
    var ptrEl = document.getElementById('arPtr');
    var ptrText = ptrEl ? ptrEl.querySelector('.or-ptr-text') : null;
    var ptrArrow = ptrEl ? ptrEl.querySelector('.or-ptr-arrow') : null;
    var ptrSub = document.getElementById('arPtrTime');
    var ptrThreshold = 80;
    var ptrStartY = 0;
    var ptrPulling = false;
    var ptrLoading = false;
    var tableWrap = document.getElementById('arTableWrap');

    function updatePtrTime() {
        var now = new Date();
        var h = String(now.getHours()).padStart(2, '0');
        var m = String(now.getMinutes()).padStart(2, '0');
        if (ptrSub) ptrSub.textContent = '最后更新：今天' + h + ':' + m;
    }
    updatePtrTime();

    tableWrap.addEventListener('touchstart', function (e) {
        if (ptrLoading) return;
        if (tableWrap.scrollTop > 5) return;
        if (!e.touches || !e.touches.length) return;
        ptrStartY = e.touches[0].clientY;
        ptrPulling = true;
    }, { passive: true });

    tableWrap.addEventListener('touchmove', function (e) {
        if (!ptrPulling || ptrLoading) return;
        if (!e.touches || !e.touches.length) return;
        var dy = e.touches[0].clientY - ptrStartY;
        if (dy < 0) { ptrEl.style.maxHeight = '0px'; return; }
        if (tableWrap.scrollTop > 0) { ptrPulling = false; ptrEl.style.maxHeight = '0px'; return; }
        var h = Math.min(dy * 0.4, 150);
        ptrEl.style.maxHeight = h + 'px';
        ptrEl.classList.add('is-active');
        if (ptrArrow) {
            var deg = Math.min((h / ptrThreshold) * 180, 180);
            ptrArrow.style.transform = 'rotate(' + deg + 'deg)';
        }
        if (ptrText) {
            if (h >= ptrThreshold) ptrText.textContent = '松开立即刷新';
            else ptrText.textContent = '下拉刷新';
        }
    }, { passive: true });

    tableWrap.addEventListener('touchend', function () {
        if (!ptrPulling) return;
        ptrPulling = false;
        var h = parseInt(ptrEl.style.maxHeight) || 0;
        if (h >= ptrThreshold && !ptrLoading) {
            ptrLoading = true;
            ptrEl.classList.add('is-loading');
            if (ptrText) ptrText.textContent = '刷新中...';
            ptrEl.style.maxHeight = '70px';
            loadData(true);
            var check = setInterval(function () {
                if (!loading) {
                    clearInterval(check);
                    ptrLoading = false;
                    ptrEl.classList.remove('is-loading', 'is-active');
                    ptrEl.style.maxHeight = '0px';
                    if (ptrArrow) ptrArrow.style.transform = '';
                    if (ptrText) ptrText.textContent = '下拉刷新';
                    updatePtrTime();
                }
            }, 200);
        } else {
            ptrEl.classList.remove('is-active');
            ptrEl.style.maxHeight = '0px';
            if (ptrArrow) ptrArrow.style.transform = '';
        }
    }, { passive: true });

    loadData();
})();
</script>
</body>
</html>
