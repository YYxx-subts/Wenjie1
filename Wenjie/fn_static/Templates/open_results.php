<?php
include_once dirname(dirname(__FILE__)) . '/Public/config.php';
if (!isset($_SESSION['userid']) || $_SESSION['userid'] === '') {
    header('Location: /action.php?do=login');
    exit;
}

$gameTypeMap = array(
    'pk10' => 1, 'xyft' => 2, 'cqssc' => 3, 'xy28' => 4, 'jnd28' => 5,
    'jsmt' => 6, 'jssc' => 7, 'jsssc' => 8, 'azxy5' => 9, 'dzlhc' => 10,
);
$codeFromGet = isset($_GET['game']) ? preg_replace('/[^a-z0-9]/', '', strtolower((string)$_GET['game'])) : '';
$gameCode = $codeFromGet !== '' ? $codeFromGet : (isset($_COOKIE['game']) ? (string)$_COOKIE['game'] : 'xy28');
if (!isset($gameTypeMap[$gameCode])) $gameCode = 'xy28';
$typeId = (int)$gameTypeMap[$gameCode];
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$games = array();
foreach ($gameTypeMap as $code => $id) {
    $games[] = array(
        'code' => $code,
        'id' => (int)$id,
        'name' => function_exists('getGameTxtName') ? getGameTxtName($id) : $code,
    );
}
$kind = 'pk10';
if ($gameCode === 'jnd28') $kind = 'pc28';
elseif ($gameCode === 'jsssc' || $gameCode === 'azxy5') $kind = 'ssc';
elseif ($gameCode === 'dzlhc') $kind = 'lhc';

$dateEsc = db_escape_string($date);
select_query('fn_open', '*', "`type` = {$typeId} AND DATE(`time`) = '{$dateEsc}' ORDER BY `id` DESC LIMIT 200");
$rows = array();
while ($con = db_fetch_array()) {
    $code = preg_split('/\s*,\s*/', trim((string)$con['code']));
    $balls = array();
    foreach ($code as $n) {
        if ($n === '') continue;
        $balls[] = (int)$n;
    }
    $term = function_exists('formatDisplayTerm') ? formatDisplayTerm($con['term']) : $con['term'];
    $tm = isset($con['time']) ? (string)$con['time'] : '';
    $hm = $tm !== '' ? date('H:i', strtotime($tm)) : '';
    $rows[] = array(
        'term' => (string)$term,
        'time' => $hm,
        'balls' => $balls,
    );
}
$boot = array(
    'game' => $gameCode,
    'typeId' => $typeId,
    'kind' => $kind,
    'date' => $date,
    'games' => $games,
    'rows' => $rows,
);
$gameName = function_exists('getGameTxtName') ? getGameTxtName($typeId) : $gameCode;

// 同页切换游戏/日期：只换内容，不堆浏览器历史
if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (function_exists('session_write_close')) { @session_write_close(); }
    $boot['success'] = true;
    $boot['gameName'] = $gameName;
    echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>开奖结果</title>
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/common.css" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/open_results.css?v=20260821a" />
    <link rel="Stylesheet" type="text/css" href="/Style/newcss/mj-open-assets-20260819.css?v=20260820e" />
    <style>.or-tag.is-long{background:#ff8a00!important}.or-tag.is-hu{background:#2f86e0!important}.or-tag.is-he{background:#9aa3af!important}.or-cells.is-xingtai{display:flex!important;flex-direction:row!important;align-items:center;gap:0 10px!important}.or-xg-col{display:flex;align-items:center}.or-xg-sum{margin-right:20px}.or-xg-tags{gap:12px!important}.or-xg-tags .or-tag{border-radius:50%!important;min-width:28px!important;height:28px!important;font-size:13px!important;padding:0 6px!important;font-family:"PingFang SC","PingFang SC-Light",sans-serif!important;font-weight:300!important}.or-xg-xt{gap:10px!important;margin-left:16px!important}.or-xg-xt-val{font-size:14px!important;font-weight:300!important;font-family:"PingFang SC","PingFang SC-Light",sans-serif!important}
    .or-ptr{flex:0 0 auto;max-height:0;overflow:hidden;display:flex;flex-direction:row;align-items:center;justify-content:center;color:#333;font-size:13px;background:#f2f3f5;transition:max-height .2s;padding:0 16px;}
    .or-ptr.is-active{transition:none;}
    .or-ptr-icon{width:20px;height:20px;flex-shrink:0;margin-right:10px;transition:transform .15s;position:relative;}
    .or-ptr-icon .or-ptr-arrow{font-size:20px;color:#2f86e0;line-height:1;transition:transform .15s;}
    .or-ptr-icon .or-ptr-spinner{position:absolute;top:0;left:0;width:20px;height:20px;border:2px solid #ccc;border-top-color:#2f86e0;border-radius:50%;display:none;}
    .or-ptr.is-loading .or-ptr-arrow{display:none;}
    .or-ptr.is-loading .or-ptr-spinner{display:block;animation:or-spin .6s linear infinite;}
    .or-ptr-info{display:flex;flex-direction:column;align-items:flex-start;}
    .or-ptr-info .or-ptr-text{color:#333;font-size:14px;font-weight:500;line-height:1.3;}
    .or-ptr-info .or-ptr-sub{color:#333;font-size:14px;font-weight:500;line-height:1;padding-top:10px;}
    @keyframes or-spin{to{transform:rotate(360deg)}}
    .or-ball{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:24px!important;height:22px!important;border-radius:4px!important;color:#fff!important;font-size:13px!important;font-weight:800!important;text-shadow:0 0 1px #000!important;box-sizing:border-box!important;flex-shrink:0!important;background-color:transparent!important}
    .or-ball.n1{background:#e6de00!important}
    .or-ball.n2{background:#0092dd!important}.or-ball.n3{background:#4b4b4b!important}
    .or-ball.n4{background:#ff7600!important}.or-ball.n5{background:#17e2e5!important}
    .or-ball.n6{background:#5234ff!important}.or-ball.n7{background:#bfbfbf!important}
    .or-ball.n8{background:#ff2600!important}.or-ball.n9{background:#780b00!important}
    .or-ball.n10{background:#07bf00!important}.or-ball.n0{background:#07bf00!important}</style>
    <script type="text/javascript" src="/Style/newjs/jquery-1.10.1.min.js"></script>
    <script type="text/javascript" src="/Style/newjs/room-overscroll.js?v=20260820or1"></script>
</head>
<body class="fn-page-locked">
<div class="or-page" id="orPage">
    <header class="or-nav">
        <button type="button" class="or-back" id="orBack" aria-label="返回">
            <img src="/Style/newimg/leftar.png" alt="">
        </button>
        <h1 class="or-title">开奖结果</h1>
        <a class="or-ext" href="https://www.cjcp.com.cn/" target="_blank" rel="noopener noreferrer">全国开奖网</a>
    </header>

    <div class="or-filters">
        <button type="button" class="or-game-btn" id="orGameBtn">
            <span id="orGameName"><?= htmlspecialchars($gameName, ENT_QUOTES, 'UTF-8'); ?></span>
            <img class="arrow" src="/Style/newimg/arrdown.png" alt="">
        </button>
        <button type="button" class="or-date-btn" id="orDateBtn">
            <span id="orDateText"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></span>
            <svg class="cal" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
        </button>
        <input type="date" id="orDateInput" class="or-date-input" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="or-tabs" id="orTabs" data-kind="<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="lab">期数/时间</span>
        <button type="button" class="or-tab is-on" data-mode="num">号码</button>
        <button type="button" class="or-tab" data-mode="dx">大小</button>
        <button type="button" class="or-tab" data-mode="ds">单双</button>
        <button type="button" class="or-tab" data-mode="gy" id="orTabGy">冠亚/龙虎</button>
    </div>

    <div class="or-ptr" id="orPtr"><div class="or-ptr-icon"><span class="or-ptr-arrow">↑</span><div class="or-ptr-spinner"></div></div><div class="or-ptr-info"><span class="or-ptr-text">下拉刷新</span><span class="or-ptr-sub" id="orPtrSub"></span></div></div>
    <div class="or-list" id="orList" data-fn-scroll="1"></div>
</div>

<div class="or-sheet-mask" id="orGameSheet" hidden>
    <div class="or-sheet" id="orGameSheetBody"></div>
</div>

<script>
window.OR_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
(function () {
  var boot = window.OR_BOOT || { rows: [], games: [], kind: 'pk10', date: '', game: '' };
  var mode = 'num';
  var listEl = document.getElementById('orList');
  var tabs = document.getElementById('orTabs');
  var kind = boot.kind || 'pk10';
  var loading = false;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function pad2(n) {
    n = parseInt(n, 10);
    if (isNaN(n) || n < 0) n = 0;
    return (n < 10 ? '0' : '') + n;
  }

  /** 与首页六合彩相同：lhc-ball + lhcballs/XX.png */
  function lhcBallHtml(n) {
    var v = parseInt(n, 10);
    if (isNaN(v) || v < 0) v = 0;
    if (v === 0) return '<span class="lhc-ball lhc-n00">0</span>';
    if (v > 49) v = 49;
    var pad = pad2(v);
    return '<span class="lhc-ball lhc-n' + pad + '">' + pad + '</span>';
  }

  function pc28BallHtml(n) {
    var v = parseInt(n, 10) || 0;
    var wave = v % 3 === 1 ? 'g' : (v % 3 === 2 ? 'b' : 'r');
    return '<span class="or-pc28-ball pc-' + wave + '">' + v + '</span>';
  }

  function pkDx(n) { return n >= 6 ? '大' : '小'; }
  function pkDs(n) { return (n % 2 === 0) ? '双' : '单'; }
  function sscDx(n) { return n >= 5 ? '大' : '小'; }
  function checkXingtai(a, b, c) {
    var arr = [a, b, c].sort(function(x, y) { return x - y; });
    if (arr[0] === arr[1] && arr[1] === arr[2]) return '豹子';
    var isShunzi = (arr[2] - arr[1] === 1 && arr[1] - arr[0] === 1)
      || (arr[0] === 0 && arr[1] === 1 && arr[2] === 9)
      || (arr[0] === 0 && arr[1] === 8 && arr[2] === 9);
    if (isShunzi) return '顺子';
    if (arr[0] === arr[1] || arr[1] === arr[2]) return '对子';
    var nums = [a, b, c], isBan = false;
    for (var i = 0; i < 3; i++) {
      for (var j = i + 1; j < 3; j++) {
        var diff = Math.abs(nums[i] - nums[j]);
        if (diff === 1 || diff === 9) { isBan = true; break; }
      }
      if (isBan) break;
    }
    if (isBan) return '半顺';
    return '杂六';
  }
  function xingtaiColor(txt) {
    if (txt === '半顺') return '#ff8a00';
    if (txt === '顺子') return '#2f86e0';
    if (txt === '对子') return '#4caf50';
    if (txt === '豹子') return '#e53935';
    return '#333';
  }
  function tagClass(txt) {
    if (txt === '大') return 'is-da';
    if (txt === '小') return 'is-xiao';
    if (txt === '单') return 'is-dan';
    if (txt === '双') return 'is-shuang';
    if (txt === '龙') return 'is-long';
    if (txt === '虎') return 'is-hu';
    if (txt === '和') return 'is-he';
    return '';
  }
  function tagHtml(txt) {
    return '<span class="or-tag ' + tagClass(txt) + '">' + esc(txt) + '</span>';
  }
  function ballHtml(n) {
    var v = parseInt(n, 10) || 0;
    return '<span class="or-ball n' + v + '">' + v + '</span>';
  }

  function cellsFor(row) {
    var balls = row.balls || [];
    var html = '';
    var grid = 'is-simple';
    if (kind === 'pc28') {
      var a = balls[0] || 0, b = balls[1] || 0, c = balls[2] || 0;
      var sum = a + b + c;
      grid = 'is-pc28';
      if (mode === 'num') {
        html = pc28BallHtml(a) + '<span class="or-op">+</span>' + pc28BallHtml(b)
          + '<span class="or-op">+</span>' + pc28BallHtml(c)
          + '<span class="or-op">=</span>' + pc28BallHtml(sum);
      } else if (mode === 'dx') {
        grid = 'is-simple';
        html = tagHtml(sum >= 14 ? '大' : '小');
      } else if (mode === 'ds') {
        grid = 'is-simple';
        html = tagHtml((sum % 2 === 0) ? '双' : '单');
      } else {
        grid = 'is-simple';
        html = '<span class="or-sum">' + sum + '</span>' + tagHtml(sum >= 14 ? '大' : '小') + tagHtml((sum % 2 === 0) ? '双' : '单');
      }
      return { html: html, grid: grid };
    }
    if (kind === 'ssc') {
      var i, sum2 = 0;
      for (i = 0; i < 5; i++) sum2 += (balls[i] || 0);
      if (mode === 'num') {
        grid = 'is-ssc';
        for (i = 0; i < 5; i++) html += ballHtml(balls[i] || 0);
      } else if (mode === 'dx') {
        grid = 'is-tags-6';
        for (i = 0; i < 5; i++) html += tagHtml(sscDx(balls[i] || 0));
        var b1 = balls[0] || 0, b5 = balls[4] || 0;
        html += tagHtml(b1 > b5 ? '龙' : (b1 < b5 ? '虎' : '和'));
      } else if (mode === 'ds') {
        grid = 'is-tags-5';
        for (i = 0; i < 5; i++) html += tagHtml(pkDs(balls[i] || 0));
      } else {
        grid = 'is-xingtai';
        var b1s = balls[0] || 0, b5s = balls[4] || 0;
        var qt = checkXingtai(balls[0]||0, balls[1]||0, balls[2]||0);
        var zt = checkXingtai(balls[1]||0, balls[2]||0, balls[3]||0);
        var ht = checkXingtai(balls[2]||0, balls[3]||0, balls[4]||0);
        html = '<div class="or-xg-col or-xg-sum"><span class="or-sum">' + sum2 + '</span></div>'
          + '<div class="or-xg-col or-xg-tags">' + tagHtml(sum2 >= 23 ? '大' : '小') + tagHtml((sum2 % 2 === 0) ? '双' : '单') + tagHtml(b1s > b5s ? '龙' : (b1s < b5s ? '虎' : '和')) + '</div>'
          + '<div class="or-xg-col or-xg-xt"><span class="or-xg-xt-val" style="color:' + xingtaiColor(qt) + '">' + qt + '</span><span class="or-xg-xt-val" style="color:' + xingtaiColor(zt) + '">' + zt + '</span><span class="or-xg-xt-val" style="color:' + xingtaiColor(ht) + '">' + ht + '</span></div>';
      }
      return { html: html, grid: grid };
    }
    if (kind === 'lhc') {
      var j, tema = balls[6] || 0;
      if (mode === 'num') {
        grid = 'is-lhc';
        for (j = 0; j < 6; j++) html += lhcBallHtml(balls[j] || 0);
        html += '<span class="lhc-plus">+</span>' + lhcBallHtml(tema);
      } else if (mode === 'dx') {
        grid = 'is-simple';
        html = tagHtml(tema >= 25 ? '大' : '小');
      } else if (mode === 'ds') {
        grid = 'is-simple';
        html = tagHtml(pkDs(tema));
      } else {
        grid = 'is-simple';
        html = '<span class="or-sum">' + tema + '</span>' + tagHtml(tema >= 25 ? '大' : '小') + tagHtml(pkDs(tema));
      }
      return { html: html, grid: grid };
    }
    // pk10 family
    var k;
    if (mode === 'num') {
      grid = 'is-pk10';
      for (k = 0; k < 10; k++) html += ballHtml(balls[k] || 0);
    } else if (mode === 'dx') {
      grid = 'is-tags-10';
      for (k = 0; k < 10; k++) html += tagHtml(pkDx(balls[k] || 0));
    } else if (mode === 'ds') {
      grid = 'is-tags-10';
      for (k = 0; k < 10; k++) html += tagHtml(pkDs(balls[k] || 0));
    } else {
      grid = 'is-gy';
      var gy = (balls[0] || 0) + (balls[1] || 0);
      html += '<span class="or-sum">' + gy + '</span>';
      html += tagHtml(gy > 11 ? '大' : '小');
      html += tagHtml((gy % 2 === 0) ? '双' : '单');
      var pairs = [[0,9],[1,8],[2,7],[3,6],[4,5]];
      for (k = 0; k < pairs.length; k++) {
        var L = balls[pairs[k][0]] || 0, R = balls[pairs[k][1]] || 0;
        html += tagHtml(L > R ? '龙' : '虎');
      }
    }
    return { html: html, grid: grid };
  }

  function render() {
    var rows = boot.rows || [];
    if (!rows.length) {
      listEl.innerHTML = '<div class="or-empty">当日暂无开奖记录</div>';
      return;
    }
    var html = '';
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var cell = cellsFor(r);
      html += '<div class="or-row">'
        + '<div class="or-meta"><span class="or-term">' + esc(r.term) + '</span><span class="or-time">' + esc(r.time) + '</span></div>'
        + '<div class="or-cells ' + cell.grid + '">' + cell.html + '</div>'
        + '</div>';
    }
    listEl.innerHTML = html;
  }

  function setMode(m) {
    mode = m;
    var btns = tabs.querySelectorAll('.or-tab');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('is-on', btns[i].getAttribute('data-mode') === m);
    }
    render();
  }

  function applyKindTabs() {
    kind = boot.kind || 'pk10';
    tabs.setAttribute('data-kind', kind);
    var gyTab = document.getElementById('orTabGy');
    if (!gyTab) return;
    if (kind === 'pc28') gyTab.textContent = '形态';
    else if (kind === 'ssc') gyTab.textContent = '总和/形态';
    else if (kind === 'lhc') gyTab.textContent = '特码';
    else gyTab.textContent = '冠亚/龙虎';
  }

  function applyBoot(data) {
    if (!data || !data.rows) return;
    boot = data;
    window.OR_BOOT = data;
    kind = data.kind || 'pk10';
    var nameEl = document.getElementById('orGameName');
    if (nameEl) nameEl.textContent = data.gameName || data.game || '';
    var dateText = document.getElementById('orDateText');
    if (dateText) dateText.textContent = data.date || '';
    var dateInputEl = document.getElementById('orDateInput');
    if (dateInputEl && data.date) dateInputEl.value = data.date;
    applyKindTabs();
    setMode(mode || 'num');
  }

  function loadResults(game, date) {
    if (loading) return;
    loading = true;
    var g = game || boot.game;
    var d = date || boot.date;
    var url = '/action.php?do=open-results&ajax=1&game=' + encodeURIComponent(g) + '&date=' + encodeURIComponent(d);
    listEl.innerHTML = '<div class="or-empty">加载中...</div>';
    $.ajax({
      url: url,
      method: 'get',
      dataType: 'json',
      cache: false,
      timeout: 8000
    }).done(function (data) {
      if (!data || data.success === false) {
        listEl.innerHTML = '<div class="or-empty">加载失败，请重试</div>';
        return;
      }
      applyBoot(data);
      // 关键：只替换当前历史，切几次游戏也只算一页
      var pageUrl = '/action.php?do=open-results&game=' + encodeURIComponent(data.game || g) + '&date=' + encodeURIComponent(data.date || d);
      try { history.replaceState({ or: 1, game: data.game, date: data.date }, '', pageUrl); } catch (e) {}
    }).fail(function () {
      listEl.innerHTML = '<div class="or-empty">加载失败，请重试</div>';
    }).always(function () { loading = false; });
  }

  tabs.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('or-tab')) return;
    setMode(t.getAttribute('data-mode') || 'num');
  });

  document.getElementById('orBack').addEventListener('click', function () {
    // 开奖页始终只占一层历史，返回即离开本页
    if (window.history.length > 1) history.back();
    else location.href = '/action.php?do=gamelist';
  });

  // game sheet
  var sheet = document.getElementById('orGameSheet');
  var sheetBody = document.getElementById('orGameSheetBody');
  function openSheet() {
    var html = '';
    (boot.games || []).forEach(function (g) {
      html += '<button type="button" class="or-sheet-item' + (g.code === boot.game ? ' is-on' : '') + '" data-code="' + esc(g.code) + '">' + esc(g.name) + '</button>';
    });
    sheetBody.innerHTML = html;
    sheet.hidden = false;
    sheet.classList.add('is-open');
  }
  function closeSheet() {
    sheet.classList.remove('is-open');
    sheet.hidden = true;
  }
  document.getElementById('orGameBtn').addEventListener('click', openSheet);
  sheet.addEventListener('click', function (e) {
    if (e.target === sheet) { closeSheet(); return; }
    var item = e.target.closest ? e.target.closest('.or-sheet-item') : null;
    if (!item) return;
    var code = item.getAttribute('data-code');
    closeSheet();
    if (!code || code === boot.game) return;
    loadResults(code, boot.date || '');
  });

  // date
  var dateBtn = document.getElementById('orDateBtn');
  var dateInput = document.getElementById('orDateInput');
  dateBtn.addEventListener('click', function () {
    if (dateInput.showPicker) {
      try { dateInput.showPicker(); return; } catch (e) {}
    }
    dateInput.click();
  });
  dateInput.addEventListener('change', function () {
    var v = dateInput.value;
    if (!v) return;
    loadResults(boot.game, v);
  });

  applyKindTabs();
  setMode('num');

  // === 下拉刷新 ===
  var ptrEl = document.getElementById('orPtr');
  var ptrText = ptrEl ? ptrEl.querySelector('.or-ptr-text') : null;
  var ptrArrow = ptrEl ? ptrEl.querySelector('.or-ptr-arrow') : null;
  var ptrSub = document.getElementById('orPtrSub');
  var ptrThreshold = 80;
  var ptrStartY = 0;
  var ptrPulling = false;
  var ptrLoading = false;

  function updatePtrTime() {
    var now = new Date();
    var h = String(now.getHours()).padStart(2, '0');
    var m = String(now.getMinutes()).padStart(2, '0');
    if (ptrSub) ptrSub.textContent = '最后更新：今天' + h + ':' + m;
  }
  updatePtrTime();

  listEl.addEventListener('touchstart', function (e) {
    if (ptrLoading) return;
    if (listEl.scrollTop > 5) return;
    if (!e.touches || !e.touches.length) return;
    ptrStartY = e.touches[0].clientY;
    ptrPulling = true;
  }, { passive: true });

  listEl.addEventListener('touchmove', function (e) {
    if (!ptrPulling || ptrLoading) return;
    if (!e.touches || !e.touches.length) return;
    var dy = e.touches[0].clientY - ptrStartY;
    if (dy < 0) { ptrEl.style.maxHeight = '0px'; return; }
    if (listEl.scrollTop > 0) { ptrPulling = false; ptrEl.style.maxHeight = '0px'; return; }
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

  listEl.addEventListener('touchend', function () {
    if (!ptrPulling) return;
    ptrPulling = false;
    var h = parseInt(ptrEl.style.maxHeight) || 0;
    if (h >= ptrThreshold && !ptrLoading) {
      ptrLoading = true;
      ptrEl.classList.add('is-loading');
      if (ptrText) ptrText.textContent = '刷新中...';
      ptrEl.style.maxHeight = '70px';
      loadResults(boot.game, boot.date);
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
})();
</script>
</body>
</html>
