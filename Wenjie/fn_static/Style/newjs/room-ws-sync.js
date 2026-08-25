/**
 * H5房间实时消息WebSocket客户端
 * 连接bridge WebSocket，实时接收chat.message和game.state
 * 作为PHP AJAX轮询的补充，提供真正的实时消息推送
 */
(function(){
    var game = window.ROOM_GAME_CODE || '';
    var roomid = window.ROOM_ROOMID || 0;
    var username = window.ROOM_BOOT_USER || window.ROOM_USERNAME || '';
    if (!game || !roomid) return;
    var wsProto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    var wsUrl = wsProto + '//' + location.host + '/ws';
    var ws = null;
    var reconnectDelay = 1000;
    var seenIds = {};

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function connect() {
        try { ws = new WebSocket(wsUrl); } catch(e) { setTimeout(connect, reconnectDelay); return; }
        ws.onopen = function() { reconnectDelay = 1000; };
        ws.onmessage = function(evt) {
            try {
                var msg = JSON.parse(evt.data);
                if (msg.type === 'chat.message' && msg.payload) {
                    var p = msg.payload;
                    if (String(p.roomid) !== String(roomid)) return;
                    var rid = parseInt(p.id, 10) || 0;
                    if (!rid || seenIds[rid]) return;
                    seenIds[rid] = 1;
                    if (document.getElementById('msg' + rid)) return;
                    if (typeof window.lastChartID !== 'undefined' && rid > parseInt(window.lastChartID, 10)) {
                        window.lastChartID = rid;
                    }
                    if (window.FeiniaoVipRoom && window.FeiniaoVipRoom.bubbleHtml) {
                        var html = window.FeiniaoVipRoom.bubbleHtml(p, username);
                        if (html) {
                            try {
                                var $ = window.jQuery || window.$;
                                if ($) $(html).appendTo('#chat_list');
                                if (typeof scrollToBt === 'function') scrollToBt();
                            } catch(e2) {}
                        }
                    } else {
                        var headerType = (username && username !== p.nickname) ? 'left-header' : 'right-header';
                        var leftH = headerType === 'left-header' ? '<img src="' + esc(p.avatar || p.headimg || '') + '">' : '';
                        var rightH = headerType === 'right-header' ? '<img src="' + esc(p.avatar || p.headimg || '') + '">' : '';
                        var nick = headerType === 'left-header'
                            ? esc(p.nickname || '') + '  ' + esc(p.createdAt || '')
                            : esc(p.createdAt || '') + '  ' + esc(p.nickname || '');
                        var content = (window.FeiniaoVipRoom && window.FeiniaoVipRoom.renderChatContent)
                            ? window.FeiniaoVipRoom.renderChatContent(p)
                            : esc(p.content == null ? '' : String(p.content));
                        var div = document.createElement('div');
                        div.id = 'msg' + rid;
                        div.className = headerType;
                        div.innerHTML = '<div class="lheader">' + leftH + '</div>' +
                            '<div class="content-box"><div class="nick-name">' + nick + '</div>' +
                            '<div class="content-info"><div class="chatnarr"></div>' +
                            '<div class="info-content">' + content + '</div></div></div>' +
                            '<div class="rheader">' + rightH + '</div>';
                        try {
                            var list = document.getElementById('chat_list');
                            if (list) list.appendChild(div);
                            if (typeof scrollToBt === 'function') scrollToBt();
                        } catch(e3) {}
                    }
                }
                // game.state 已禁用：数据格式与 PHP getOpenInfo 不同，调用会覆盖正常显示
            } catch(e) {}
        };
        ws.onclose = function() {
            setTimeout(connect, reconnectDelay);
            reconnectDelay = Math.min(5000, reconnectDelay * 1.5);
        };
        ws.onerror = function() { try { ws.close(); } catch(e) {} };
    }
    connect();
})();
