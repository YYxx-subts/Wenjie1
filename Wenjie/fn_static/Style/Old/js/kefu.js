var sendtime = 0;
var id = 1;
var kefuLeaveUrl = '/action.php?do=gamelist';
var kefuPollTimer = null;
var kefuUploadBusy = false;

function kefuCsrf(){
	var meta = document.querySelector('meta[name="csrf-token"]');
	return meta ? String(meta.content || '') : '';
}

function escapeKefuText(value){
	return $('<div/>').text(String(value == null ? '' : value)).html();
}

function normalizeKefuImageSrc(value){
	var raw = String(value || '').trim();
	if (!raw) return '';
	var pathname = raw;
	if (/^https:\/\//i.test(raw)) {
		try {
			var parsed = new URL(raw, window.location.href);
			if (parsed.origin !== window.location.origin && parsed.origin !== 'https://wjh5.atmyx.app') return '';
			pathname = parsed.pathname;
		} catch (e) { return ''; }
	}
	var memberUpload = /^\/upload\/kefu\/[0-9]{6}\/[a-f0-9]{32}\.(jpg|png|webp|gif)$/i;
	var agentUpload = /^\/upload\/kefu_[0-9]+_[0-9]{13}_[a-f0-9]{8}\.(jpg|png|webp|gif)$/i;
	return memberUpload.test(pathname) || agentUpload.test(pathname) ? pathname : '';
}

function safeKefuContent(value){
	var root = document.createElement('div');
	root.innerHTML = String(value == null ? '' : value);
	var nodes = root.querySelectorAll('*');
	for (var i = nodes.length - 1; i >= 0; i--) {
		var node = nodes[i];
		var tag = String(node.tagName || '').toUpperCase();
		if (tag === 'IMG') {
			var src = normalizeKefuImageSrc(node.getAttribute('src'));
			if (!src) { node.remove(); continue; }
			while (node.attributes.length) node.removeAttribute(node.attributes[0].name);
			node.setAttribute('src', src);
			node.setAttribute('alt', '图片');
			node.setAttribute('loading', 'lazy');
			node.setAttribute('decoding', 'async');
			node.setAttribute('role', 'button');
			node.setAttribute('tabindex', '0');
			node.className = 'kefu-chat-image';
		} else if (tag === 'BR') {
			while (node.attributes.length) node.removeAttribute(node.attributes[0].name);
		} else {
			node.replaceWith(document.createTextNode(node.textContent || ''));
		}
	}
	return root.innerHTML;
}

function initKefuImagePreview(){
	if (document.documentElement.getAttribute('data-kefu-image-preview') === '1') return;
	document.documentElement.setAttribute('data-kefu-image-preview', '1');
	var preview = document.createElement('div');
	preview.className = 'kefu-image-preview';
	preview.hidden = true;
	preview.setAttribute('role', 'dialog');
	preview.setAttribute('aria-modal', 'true');
	preview.setAttribute('aria-label', '图片预览');
	preview.innerHTML = '<button type="button" class="kefu-image-preview-close" aria-label="关闭图片预览">×</button><img alt="图片预览">';
	document.body.appendChild(preview);
	var previewImage = preview.querySelector('img');
	var lastTrigger = null;

	function closePreview(){
		if (preview.hidden) return;
		preview.hidden = true;
		previewImage.removeAttribute('src');
		document.body.classList.remove('kefu-preview-open');
		if (lastTrigger && typeof lastTrigger.focus === 'function') {
			try { lastTrigger.focus(); } catch (e) {}
		}
		lastTrigger = null;
	}

	function openPreview(trigger){
		var src = normalizeKefuImageSrc(trigger.getAttribute('src'));
		if (!src) return;
		lastTrigger = trigger;
		previewImage.setAttribute('src', src);
		preview.hidden = false;
		document.body.classList.add('kefu-preview-open');
		var close = preview.querySelector('.kefu-image-preview-close');
		if (close) close.focus();
	}

	document.addEventListener('click', function(event){
		var target = event.target;
		var image = target && target.closest ? target.closest('.kefu-chat-image') : null;
		if (image && document.querySelector('.kefu-chat-content') && document.querySelector('.kefu-chat-content').contains(image)) {
			event.preventDefault();
			openPreview(image);
			return;
		}
		if (target === preview || (target && target.closest && target.closest('.kefu-image-preview-close'))) closePreview();
	});
	document.addEventListener('keydown', function(event){
		var target = event.target;
		if (!preview.hidden && event.key === 'Escape') {
			event.preventDefault();
			closePreview();
			return;
		}
		if (target && target.classList && target.classList.contains('kefu-chat-image') && (event.key === 'Enter' || event.key === ' ')) {
			event.preventDefault();
			openPreview(target);
		}
	});
}
var KEFU_CACHE_DAYS = 14;
var KEFU_CACHE_DB = 'wenjie-kefu-cache-v1';
var KEFU_CACHE_STORE = 'messages';
var kefuCacheDb = null;
var kefuCacheScope = String(location.host || 'wenjie') + '|' + String(window.kefuRoomId || '') + '|' + String(window.kefuUserId || '');

function normalizeKefuCacheRow(row){
	if (!row || row._kefu_closed) return null;
	var rid = parseInt(row.id, 10) || 0;
	if (!rid) return null;
	var stamp = Date.parse(String(row.createdAt || ''));
	if (!isFinite(stamp) || stamp <= 0) stamp = Date.now();
	return {
		pk: kefuCacheScope + '|' + rid,
		scope: kefuCacheScope,
		id: rid,
		nickname: String(row.nickname || ''),
		headimg: String(row.headimg || ''),
		content: String(row.content || ''),
		addtime: String(row.addtime || ''),
		createdAt: String(row.createdAt || new Date(stamp).toISOString()),
		type: String(row.type || 'U2'),
		cacheTime: stamp
	};
}

function kefuFallbackKey(){
	return 'wenjie_kefu_cache_v1:' + encodeURIComponent(kefuCacheScope);
}

function readKefuFallback(){
	try {
		var rows = JSON.parse(localStorage.getItem(kefuFallbackKey()) || '[]');
		return Array.isArray(rows) ? rows : [];
	} catch (e) { return []; }
}

function writeKefuFallback(rows){
	try { localStorage.setItem(kefuFallbackKey(), JSON.stringify(rows.slice(-1000))); } catch (e) {}
}

function openKefuCache(done){
	if (kefuCacheDb) { done(kefuCacheDb); return; }
	if (!window.indexedDB) { done(null); return; }
	try {
		var request = indexedDB.open(KEFU_CACHE_DB, 1);
		request.onupgradeneeded = function(){
			var db = request.result;
			var store = db.objectStoreNames.contains(KEFU_CACHE_STORE)
				? request.transaction.objectStore(KEFU_CACHE_STORE)
				: db.createObjectStore(KEFU_CACHE_STORE, { keyPath: 'pk' });
			if (!store.indexNames.contains('scope')) store.createIndex('scope', 'scope', { unique: false });
		};
		request.onsuccess = function(){
			kefuCacheDb = request.result;
			kefuCacheDb.onversionchange = function(){ try { kefuCacheDb.close(); } catch (e) {} kefuCacheDb = null; };
			done(kefuCacheDb);
		};
		request.onerror = function(){ done(null); };
	} catch (e) { done(null); }
}

function cacheKefuMessages(data){
	if (!data || !data.length) return;
	var cutoff = Date.now() - KEFU_CACHE_DAYS * 86400000;
	var rows = [];
	for (var i = 0; i < data.length; i++) {
		var row = normalizeKefuCacheRow(data[i]);
		if (row && row.cacheTime >= cutoff) rows.push(row);
	}
	if (!rows.length) return;
	openKefuCache(function(db){
		if (!db) {
			var merged = readKefuFallback();
			var byId = {};
			for (var f = 0; f < merged.length; f++) if (Number(merged[f].cacheTime || 0) >= cutoff) byId[String(merged[f].id)] = merged[f];
			for (var r = 0; r < rows.length; r++) byId[String(rows[r].id)] = rows[r];
			var fallbackRows = Object.keys(byId).map(function(key){ return byId[key]; }).sort(function(a,b){ return Number(a.id)-Number(b.id); });
			writeKefuFallback(fallbackRows);
			return;
		}
		try {
			var tx = db.transaction(KEFU_CACHE_STORE, 'readwrite');
			var store = tx.objectStore(KEFU_CACHE_STORE);
			for (var j = 0; j < rows.length; j++) store.put(rows[j]);
			var cursor = store.index('scope').openCursor(IDBKeyRange.only(kefuCacheScope));
			cursor.onsuccess = function(){
				var current = cursor.result;
				if (!current) return;
				if (Number(current.value.cacheTime || 0) < cutoff) current.delete();
				current.continue();
			};
		} catch (e) {}
	});
}

function loadKefuCachedMessages(done){
	var finished = false;
	function finish(rows){
		if (finished) return;
		finished = true;
		rows = (rows || []).filter(function(row){ return Number(row.cacheTime || 0) >= Date.now() - KEFU_CACHE_DAYS * 86400000; });
		rows.sort(function(a,b){ return Number(a.id)-Number(b.id); });
		done(rows);
	}
	openKefuCache(function(db){
		if (!db) { finish(readKefuFallback()); return; }
		try {
			var tx = db.transaction(KEFU_CACHE_STORE, 'readonly');
			var request = tx.objectStore(KEFU_CACHE_STORE).index('scope').getAll(IDBKeyRange.only(kefuCacheScope));
			request.onsuccess = function(){ finish(request.result || []); };
			request.onerror = function(){ finish(readKefuFallback()); };
		} catch (e) { finish(readKefuFallback()); }
	});
	setTimeout(function(){ finish(readKefuFallback()); }, 350);
}

function leaveKefuPage(){
	try {
		window.location.replace(kefuLeaveUrl);
	} catch (e) {
		window.location.href = kefuLeaveUrl;
	}
}

function goBackAndClose(e){
	if (e) {
		try {
			e.preventDefault();
			e.stopPropagation();
		} catch (err) {}
	}
	// 只离开客服页面，不发送退出/关闭房间请求，聊天统一保留 14 天。
	leaveKefuPage();
	return false;
}

function loadKefuQuickReplies(){
	$.ajax({
		url: '/Application/ajax_kefu.php?type=replies',
		type: 'get',
		dataType: 'json',
		success: function(res){
			var items = (res && res.items) ? res.items : [];
			var box = $('#kefuQuickReplies');
			if (!box.length || !items.length) return;
			var html = '';
			for (var i = 0; i < items.length; i++) {
				var title = items[i].title || items[i].content || '';
				var content = items[i].content || title;
				html += '<button type="button" class="kefu-qr-btn" data-title="'+ $('<div/>').text(title).html().replace(/"/g, '&quot;') +'" data-content="'+ $('<div/>').text(content).html().replace(/"/g, '&quot;') +'">'+ $('<div/>').text(title).html() +'</button>';
			}
			box.html(html).show();
		}
	});
}

$(function(){
	FirstGetContent();
	loadKefuQuickReplies();
	initKefuSystemKeyboard();
	initKefuToolbar();
	initKefuImagePreview();
	document.addEventListener('visibilitychange', function(){
		if(!document.hidden && !kefuPollTimer){
			kefuPollTimer = setInterval(updateContent, 1000);
		}
	});

	$(document).off('click.kefuQr').on('click.kefuQr', '.kefu-qr-btn', function(){
		var title = $(this).attr('data-title') || '';
		if (!title) return;
		sendKefuMessage(title, '');
	});

	// 返回：真实链接 + 点击关闭会话，避免 App WebView 里 history.back 无效
	$(document).off('click.kefuBack').on('click.kefuBack', '#kefuBackLink, .kefu-back-link, .roomtop .back', function(e){
		return goBackAndClose(e);
	});

	$('#butSend').off('click').on('click', function() {
		sendKefuMessage($.trim($('#msg').val() || ''), '');
	});

	$('#kefuMediaBtn').off('click').on('click', function(){
		var menu = document.getElementById('kefuMediaMenu');
		if (!menu) return;
		document.getElementById('msg').blur();
		menu.hidden = !menu.hidden;
		var panel = document.getElementById('kefuEmojiPanel');
		if (panel) panel.hidden = true;
	});
	$('#kefuTakePhoto').off('click').on('click', function(){
		document.getElementById('kefuMediaMenu').hidden = true;
		document.getElementById('kefuCameraInput').click();
	});
	$('#kefuChoosePhoto').off('click').on('click', function(){
		document.getElementById('kefuMediaMenu').hidden = true;
		document.getElementById('kefuGalleryInput').click();
	});
	$('#kefuCameraInput,#kefuGalleryInput').off('change').on('change', function(){
		var file = this.files && this.files[0];
		this.value = '';
		if (file) uploadKefuImage(file);
	});
	$('#kefuEmojiBtn').off('click').on('click', function(){
		var panel = document.getElementById('kefuEmojiPanel');
		if (!panel) return;
		document.getElementById('msg').blur();
		panel.hidden = !panel.hidden;
		var menu = document.getElementById('kefuMediaMenu');
		if (menu) menu.hidden = true;
	});
	$(document).off('click.kefuEmoji').on('click.kefuEmoji', '.kefu-emoji-item', function(){
		var emoji = $(this).attr('data-emoji') || '';
		var input = document.getElementById('msg');
		if (!input || !emoji) return;
		var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
		var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
		input.value = input.value.slice(0, start) + emoji + input.value.slice(end);
		try { input.setSelectionRange(start + emoji.length, start + emoji.length); } catch (e) {}
		input.dispatchEvent(new Event('input', { bubbles: true }));
	});

	// 离开客服页面只结束浏览，不再发送关闭或删除会话请求；聊天统一保留 14 天。
});

function initKefuToolbar(){
	var panel = document.getElementById('kefuEmojiPanel');
	if (!panel || panel.getAttribute('data-ready') === '1') return;
	panel.setAttribute('data-ready', '1');
	var emojis = ['😀','😁','😂','😅','😊','😍','😘','😎','🤔','😴','😭','😡','👍','👎','🙏','👏','🎉','💯','✅','❗','💰','🎁','🌹','❤️','🔥','✨','🤝','👌','🙌','😋','😇','🥳'];
	var html = '';
	for (var i = 0; i < emojis.length; i++) {
		html += '<button type="button" class="kefu-emoji-item" data-emoji="'+emojis[i]+'" aria-label="'+emojis[i]+'"><span class="kefu-emoji-char">'+emojis[i]+'</span></button>';
	}
	panel.innerHTML = html;
}

function sendKefuMessage(text, imageUrl){
	var msgtxt = $.trim(text || '');
	var image = String(imageUrl || '');
	var time = new Date().getTime();
	if(time - sendtime < 1500){ alert('发送太快，请稍后再试'); return; }
	if(msgtxt === '' && image === ''){ alert('不能发送空消息!'); return; }
	$.ajax({
		url: '/Application/ajax_kefu.php?type=send',
		type: 'post',
		headers: { 'X-CSRF-TOKEN': kefuCsrf() },
		data: {content: msgtxt, image_url: image, _csrf: kefuCsrf()},
		dataType: 'json',
		success:function(data){
			if(data && data.success){
				sendtime = new Date().getTime();
				$('#msg').val('');
				var sentInput = document.getElementById('msg');
				if (sentInput) sentInput.blur();
				if (window.__syncKefuViewport) {
					setTimeout(window.__syncKefuViewport, 30);
					setTimeout(window.__syncKefuViewport, 200);
				}
				var msgs = [{id:data.id || Date.now(),nickname:data.nickname || nickname || '我',headimg:data.headimg || headimg || '',content:data.content || msgtxt,addtime:data.addtime || '',type:'U2'}];
				// 如果服务端返回了自动回复，立即显示
				if (data.autoReply) {
					msgs.push({id:data.autoReply.id || Date.now()+1,nickname:data.autoReply.nickname || '',headimg:data.autoReply.headimg || '',content:data.autoReply.content || '',addtime:data.autoReply.addtime || '',type:'S1'});
				}
				addMessage(msgs);
				scrollKefuBottom();
			}else alert((data && (data.msg || data.content)) || '发送失败');
		},
		error:function(xhr){
			var tip='发送失败，请检查网络或重新登录';
			if(xhr && xhr.responseText){try{var j=JSON.parse(xhr.responseText);if(j&&(j.msg||j.content))tip=j.msg||j.content;}catch(e){}}
			alert(tip);
		}
	});
}

function uploadKefuImage(file){
	if (kefuUploadBusy) return;
	if (!file || !/^image\/(jpeg|png|webp|gif)$/i.test(String(file.type || ''))) return alert('请选择 JPG、PNG、WEBP 或 GIF 图片');
	if (Number(file.size || 0) > 8 * 1024 * 1024) return alert('图片不能超过8MB');
	kefuUploadBusy = true;
	$('.kefu-media-button').addClass('is-loading');
	var form = new FormData();
	form.append('file', file);
	form.append('_csrf', kefuCsrf());
	$.ajax({
		url:'/Application/ajax_kefu_upload.php', type:'post', data:form, processData:false, contentType:false,
		headers:{'X-CSRF-TOKEN':kefuCsrf()}, dataType:'json',
		success:function(res){ if(res && res.success && res.url) sendKefuMessage('', res.url); else alert((res && res.msg) || '图片上传失败'); },
		error:function(){ alert('图片上传失败，请检查网络后重试'); },
		complete:function(){ kefuUploadBusy=false; $('.kefu-media-button').removeClass('is-loading'); }
	});
}

function FirstGetContent(){
	var serverStarted = false;
	function startServer(){
		if (serverStarted) return;
		serverStarted = true;
		$.ajax({
			url: '/Application/ajax_kefu.php?type=first',
			type: 'get',
			dataType: 'json',
			success:function(data){
				addMessage(data);
				scrollKefuBottom();
				if (data && data.length >= 100) syncKefuOlderHistory(parseInt(data[0].id, 10) || 0);
			},
			error:function(){
				if (!document.querySelector('#chat_list > div')) alert('客服消息加载失败，请检查网络后重试');
			},
			complete:function(){
				if (!kefuPollTimer) kefuPollTimer = setInterval(updateContent, 1000);
			}
		});
	}
	loadKefuCachedMessages(function(rows){
		if (rows.length) {
			addMessage(rows, true, true);
			scrollKefuBottom();
		}
		startServer();
	});
	setTimeout(startServer, 220);
	$('#messageLoading').remove();
}

function syncKefuOlderHistory(beforeId){
	if (!beforeId) return;
	$.ajax({
		url: '/Application/ajax_kefu.php?type=history&limit=100&before_id=' + beforeId,
		type: 'get',
		dataType: 'json',
		success:function(data){
			if (!data || !data.length) return;
			addMessage(data, false, true);
			if (data.length >= 100) {
				var nextId = parseInt(data[0].id, 10) || 0;
				if (nextId > 0 && nextId < beforeId) setTimeout(function(){ syncKefuOlderHistory(nextId); }, 80);
			}
		}
	});
}

function updateContent(){
	$.ajax({
		url: '/Application/ajax_kefu.php?type=update&id=' + id,
		type: 'get',
		dataType: 'json',
		success:function(data){
			if (data && data.length && data[0] && data[0]._kefu_closed) {
				alert(data[0].msg || '客服会话已超时关闭');
				leaveKefuPage();
				return;
			}
			if (data && data.length) {
				addMessage(data);
				scrollKefuBottom();
			}
		}
	});
}

function scrollKefuBottom(){
	try {
		/* 只滚动聊天容器，不能再调用 scrollIntoView 推动整页。 */
		var box = document.querySelector('.kefu-chat-content');
		if (box) box.scrollTop = box.scrollHeight;
	} catch (e) {}
}

/* iOS 键盘会改变 visualViewport。整个客服容器跟随视觉视口，
 * 顶栏、聊天区和输入栏一起缩放，避免输入栏被重复抬升或关闭后留下空白。 */
function initKefuSystemKeyboard(){
	var main = document.querySelector('.mainbox');
	var dock = document.querySelector('.kefu-input-dock');
	var chat = document.querySelector('.kefu-chat-content');
	var input = document.getElementById('msg');
	if (!main || !dock || !chat || !input) return;
	var pending = null;
	function isOpen(){
		return document.activeElement === input;
	}
	function sync(){
		pending = null;
		var open = isOpen();
		var viewport = window.visualViewport;
		var viewportHeight = viewport ? Math.round(viewport.height) : Math.round(window.innerHeight);
		var viewportTop = viewport ? Math.round(viewport.offsetTop) : 0;
		var keyboardVisible = !!(open && viewport && viewportHeight < Math.round(window.innerHeight) - 80);
		dock.classList.toggle('kefu-system-kb-open', keyboardVisible);
		if (keyboardVisible) {
			main.style.top = viewportTop + 'px';
			main.style.bottom = 'auto';
			main.style.height = viewportHeight + 'px';
		} else {
			main.style.top = '0px';
			main.style.bottom = '0px';
			main.style.height = 'auto';
		}
		dock.style.bottom = '0px';
		var dockHeight = Math.ceil(dock.getBoundingClientRect().height || 0);
		chat.style.paddingBottom = Math.max(dockHeight + 8, 16) + 'px';
		if (keyboardVisible) setTimeout(scrollKefuBottom, 60);
	}
	function schedule(){
		if (pending) clearTimeout(pending);
		pending = setTimeout(sync, 20);
	}
	window.__syncKefuViewport = sync;
	input.addEventListener('focus', schedule);
	input.addEventListener('blur', function(){ setTimeout(sync, 40); setTimeout(sync, 180); });
	window.addEventListener('resize', schedule);
	window.addEventListener('orientationchange', function(){ setTimeout(sync, 120); });
	window.addEventListener('pageshow', schedule);
	if (window.visualViewport) {
		window.visualViewport.addEventListener('resize', schedule);
		window.visualViewport.addEventListener('scroll', schedule);
	}
	sync();
}

function addMessage(data, skipCache, prepend){
	if(data==null || !data.length){
		return;
	}
	if (!skipCache) cacheKefuMessages(data);
	var chatBox = document.querySelector('.kefu-chat-content');
	var oldHeight = chatBox ? chatBox.scrollHeight : 0;
	var oldTop = chatBox ? chatBox.scrollTop : 0;
	var chatHtml="";
	for(var i=0;i<data.length;i++){
		var row=data[i];
		if (row && row._kefu_closed) continue;
		var rid = parseInt(row.id, 10) || 0;
		if(rid > 0 && document.getElementById('msg'+rid)){
			if(rid > id) id = rid;
			continue;
		}
		if(rid > id){
			id = rid;
		}
		var type = row.type || 'U2';
		if(typeof lastChartID !== 'undefined' && rid>lastChartID) lastChartID=rid;
		var headerType=String(type).substr(0,1)!="U"?'left-header':'right-header';
		var leftHeader=headerType=='left-header'?'<img src="'+row.headimg+'">':'';
		var rightHeader=headerType=='right-header'?'<img src="'+row.headimg+'">':'';
		var renderedContent = safeKefuContent(row.content);
		var contentInfoClass = renderedContent.indexOf('kefu-chat-image') >= 0 ? 'info-content kefu-chat-image-wrap' : 'info-content';

		chatHtml+='<div id="msg'+row.id+'" class="'+headerType+'">\n' +
			'                <div class="lheader">'+leftHeader+'</div>\n' +
			'                <div class="content-box">\n' +
			'                    <div class="nick-name">'+escapeKefuText(row.nickname)+'</div>\n' +
			'                    <div class="content-info">\n' +
			'                        <div class="chatnarr"></div>\n' +
			'                        <div class="'+contentInfoClass+'">'+renderedContent+'</div>\n' +
			'                    </div>\n' +
			'                </div>\n' +
			'                <div class="rheader">'+rightHeader+'</div>\n' +
			'            </div>';
	}

	if (chatHtml) {
		if (prepend) {
			$(chatHtml).prependTo('#chat_list');
			if (chatBox) chatBox.scrollTop = oldTop + Math.max(0, chatBox.scrollHeight - oldHeight);
		} else {
			$(chatHtml).appendTo('#chat_list');
		}
	}
}

Date.prototype.format = function(format)
{
 var o = {
 "M+" : this.getMonth()+1,
 "d+" : this.getDate(),
 "h+" : this.getHours(),
 "m+" : this.getMinutes(),
 "s+" : this.getSeconds(),
 "q+" : Math.floor((this.getMonth()+3)/3),
 "S" : this.getMilliseconds()
 }
 if(/(y+)/.test(format)) format=format.replace(RegExp.$1,
 (this.getFullYear()+"").substr(4 - RegExp.$1.length));
 for(var k in o)if(new RegExp("("+ k +")").test(format))
 format = format.replace(RegExp.$1,
 RegExp.$1.length==1 ? o[k] :
 ("00"+ o[k]).substr((""+ o[k]).length));
 return format;
}
