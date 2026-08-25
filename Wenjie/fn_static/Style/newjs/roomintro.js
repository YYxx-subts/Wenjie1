(function () {
  var boot = window.ROOM_INTRO_BOOT || {};
  var games = Array.isArray(boot.games) ? boot.games : [];
  var selectedId = Number(boot.defaultId) || (games[0] && games[0].id) || 0;
  var pickerOpen = false;

  var RULES = {
    pk10: [
      {
        title: '1、【3/大/5】',
        body: '这种可识别为【第三名 大-5】，大小单双玩法1~10名都可以识别，龙虎玩法只有1~5名能识别。'
      },
      {
        title: '2、【123大/5】',
        body: '这种【不写位置】的，默认识别为只投第一名【第一名 1-5，第一名 2-5，第一名 3-5，第一名 大-5】，大小单双龙虎以及所有号码都支持这种识别方式。'
      },
      {
        title: '3、【1大 5】',
        body: '这种【投注的号码是非数字玩法】的，可以忽略“/”。\n*【1大5】实际是【1/大/5】，识别为【第一名 大-5】。'
      },
      {
        title: '4、【和】',
        body: '这种被识别为【冠亚】玩法。\n*比如【和/大/5】会被识别为【冠亚和大小 大-5】，【和/345/5】会被识别为【冠亚 3-5、4-5、5-5】。\n*同时冠亚玩法也可以省略位置和号码之间的分隔符，比如【和345/5】，也会被识别为【冠亚 3-5、4-5、5-5】。'
      },
      {
        title: '5、【位置10和号码10】',
        body: '第十名和号码10，都用0来代替。'
      }
    ],
    pc28: [
      { title: '1、和值玩法', body: '每期公布三个开奖号码，三数相加得到和值；和值0-13为小，14-27为大。' },
      { title: '2、大小单双', body: '可按和值大小、单双以及组合玩法进行投注，具体可投注项以游戏页面为准。' },
      { title: '3、投注说明', body: '输入和值、大小、单双或组合玩法后，按页面提示下注。' }
    ],
    ssc: [
      { title: '1、开奖号码', body: '每期开出5个号码，支持大小单双、定位胆等对应玩法。' },
      { title: '2、投注说明', body: '具体可投注项目、封盘时间和赔率以游戏页面及房主后台设置为准。' }
    ],
    lhc: [
      { title: '1、六合彩玩法', body: '每期开出一组号码及特别号，可按号码、生肖、波色等玩法投注。' },
      { title: '2、投注说明', body: '特码、正码和生肖等玩法的可投注范围，以当前游戏页面显示为准。' }
    ],
    default: [
      { title: '1、开奖号码', body: '每期公布实时开奖结果，支持号码及大小单双等对应玩法。' },
      { title: '2、投注说明', body: '具体可投注项目、封盘时间和赔率以游戏页面及房主后台设置为准。' }
    ]
  };

  function $(id) {
    return document.getElementById(id);
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function findGame(id) {
    var i;
    for (i = 0; i < games.length; i++) {
      if (Number(games[i].id) === Number(id)) return games[i];
    }
    return games[0] || null;
  }

  function rulesFor(game) {
    if (!game) return RULES.default;
    var list = RULES[game.kind] || RULES.default;
    var extra = [];
    if (game.rulesText) {
      extra.push({ title: '房间补充规则', body: game.rulesText });
    }
    return list.concat(extra);
  }

  function renderNotice() {
    var el = $('riNotice');
    if (!el) return;
    if (boot.noticeOn === false) {
      el.textContent = '房间公告已关闭';
      return;
    }
    el.textContent = boot.notice || '暂无房间公告';
  }

  function syncTriggerName() {
    var nameEl = $('riGameName');
    if (!nameEl) return;
    var game = findGame(selectedId);
    nameEl.textContent = game ? game.name : (games.length ? '选择彩种' : '暂无开放彩种');
  }

  function renderGameGrid() {
    var grid = $('riGameGrid');
    if (!grid) return;
    if (!games.length) {
      grid.innerHTML = '<div class="ri-rule-empty" style="grid-column:1/-1">暂无开放彩种</div>';
      return;
    }
    grid.innerHTML = games.map(function (g) {
      var on = Number(g.id) === Number(selectedId);
      return (
        '<button type="button" class="ri-game-chip' + (on ? ' is-on' : '') + '" role="option" aria-selected="' +
        (on ? 'true' : 'false') + '" data-id="' + g.id + '">' +
        escapeHtml(g.name) +
        '</button>'
      );
    }).join('');
  }

  function setPickerOpen(open) {
    pickerOpen = !!open;
    var trigger = $('riGameTrigger');
    var panel = $('riGamePanel');
    var mask = $('riGameMask');
    var card = document.querySelector('.ri-rules-card');
    if (trigger) {
      trigger.classList.toggle('is-open', pickerOpen);
      trigger.setAttribute('aria-expanded', pickerOpen ? 'true' : 'false');
    }
    if (panel) panel.hidden = !pickerOpen;
    if (mask) mask.hidden = !pickerOpen;
    if (card) card.classList.toggle('is-picking', pickerOpen);
    if (pickerOpen) renderGameGrid();
  }

  function selectGame(id) {
    selectedId = Number(id) || selectedId;
    syncTriggerName();
    renderGameGrid();
    setPickerOpen(false);
    var onOdds = document.querySelector('.ri-tab[data-tab="odds"].is-on');
    if (onOdds) renderOdds();
    else renderRules();
  }

  function renderRules() {
    var box = $('riRuleList');
    if (!box) return;
    var game = findGame(selectedId);
    var rules = rulesFor(game);
    if (!rules.length) {
      box.innerHTML = '<div class="ri-rule-empty">暂无玩法说明</div>';
      return;
    }
    box.innerHTML = rules.map(function (r) {
      return '<article><strong>' + escapeHtml(r.title) + '</strong><p>' + escapeHtml(r.body) + '</p></article>';
    }).join('');
  }

  function renderOdds() {
    var box = $('riOddsList');
    if (!box) return;
    var game = findGame(selectedId);
    var odds = (game && game.odds) || [];
    if (!odds.length) {
      box.innerHTML = '<div class="ri-odds-empty">暂无赔率数据</div>';
      return;
    }
    box.innerHTML = odds.map(function (o) {
      var v = Number(o.value);
      var txt = (Math.round(v * 100) / 100).toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
      return '<div class="ri-odds-item"><b>' + escapeHtml(o.label) + '</b><em>' + txt + '</em></div>';
    }).join('');
  }

  function switchTab(name) {
    setPickerOpen(false);
    var tabs = document.querySelectorAll('.ri-tab');
    var i;
    for (i = 0; i < tabs.length; i++) {
      tabs[i].classList.toggle('is-on', tabs[i].getAttribute('data-tab') === name);
      try { tabs[i].blur(); } catch (e) {}
    }
    var rulesPane = $('riPaneRules');
    var oddsPane = $('riPaneOdds');
    if (name === 'odds') {
      if (rulesPane) rulesPane.hidden = true;
      if (oddsPane) oddsPane.hidden = false;
      renderOdds();
    } else {
      if (rulesPane) rulesPane.hidden = false;
      if (oddsPane) oddsPane.hidden = true;
      renderRules();
    }
  }

  function bind() {
    var tabs = document.querySelectorAll('.ri-tab');
    var i;
    for (i = 0; i < tabs.length; i++) {
      tabs[i].addEventListener('click', function () {
        switchTab(this.getAttribute('data-tab'));
        try { this.blur(); } catch (e) {}
      });
    }

    var trigger = $('riGameTrigger');
    if (trigger) {
      trigger.addEventListener('click', function () {
        if (!games.length) return;
        setPickerOpen(!pickerOpen);
      });
    }

    var grid = $('riGameGrid');
    if (grid) {
      grid.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.ri-game-chip') : null;
        if (!btn) return;
        selectGame(btn.getAttribute('data-id'));
      });
    }

    var mask = $('riGameMask');
    if (mask) {
      mask.addEventListener('click', function () {
        setPickerOpen(false);
      });
    }
  }

  renderNotice();
  syncTriggerName();
  renderGameGrid();
  renderRules();
  bind();
})();
