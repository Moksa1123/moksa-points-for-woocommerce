/**
 * （由 mowp 的 floating-chat.js 移入共用層；REST 與字串改由 Host 餵入）
 * Moksa AI 浮動對話窗 — 後台右下角 AI 助手。訊息經 REST(moksa-for-woocommerce/v1/ai-chat)走 agentic 迴圈。
 * 破壞性動作 → REST 回 confirm,跳「確認執行 / 取消」按鈕,按確認才 POST /ai-confirm 真正執行。
 * 配色:白底 + 黑金(深炭黑 #1d2327 + 金 #c9a227),圖示用 WP dashicons-cart(電商感)。
 * 樣式全用 #moksa-ai-panel / #moksa-ai-fab 前綴壓過 WP admin 預設。
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || ! document.body ) {
		return;
	}
	var apiFetch = wp.apiFetch;
	var cfg = window.moksaAiChat || {};
	var S = cfg.strings || {};
	var NAME = S.name || 'Moksa AI';
	var GREETING = S.greeting || '';
	var EXAMPLES = S.examples || [];

	var style = document.createElement( 'style' );
	style.textContent =
		'#moksa-ai-fab{position:fixed;right:22px;bottom:22px;z-index:99998;display:flex;align-items:center;gap:7px;' +
		'background:#1d2327;color:#fff;border:0;border-radius:24px;padding:11px 18px;' +
		'cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.28);font-size:14px;font-weight:600;transition:transform .15s,box-shadow .15s;}' +
		'#moksa-ai-fab:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.36);}' +
		'#moksa-ai-fab .dashicons{color:#d4af37;font-size:18px;width:18px;height:18px;line-height:18px;}' +
		'#moksa-ai-panel{position:fixed;right:22px;bottom:80px;z-index:99999;width:360px;max-width:92vw;height:520px;max-height:78vh;' +
		'display:none;flex-direction:column;background:#fff;border:1px solid #e8e8ea;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.18);overflow:hidden;' +
		'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans TC",sans-serif;animation:mo-ai-in .18s ease;}' +
		'@keyframes mo-ai-in{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}' +
		'#moksa-ai-panel .mo-ai-head{background:#fff;color:#1d2327;padding:13px 16px;display:flex;align-items:center;gap:9px;' +
		'border-bottom:2px solid;border-image:linear-gradient(90deg,#c9a227,rgba(201,162,39,0)) 1;}' +
		'#moksa-ai-panel .mo-ai-dot{width:9px;height:9px;border-radius:50%;background:#34d399;box-shadow:0 0 0 3px rgba(52,211,153,.25);}' +
		'#moksa-ai-panel .mo-ai-head b{font-size:15px;font-weight:700;}' +
		'#moksa-ai-panel .mo-ai-beta{font-size:11px;color:#8a8f94;font-weight:400;}' +
		'#moksa-ai-panel .mo-ai-clear{margin-left:auto;background:#f0f0f1;border:0;color:#50575e;border-radius:8px;padding:4px 9px;font-size:12px;cursor:pointer;box-shadow:none;}' +
		'#moksa-ai-panel .mo-ai-clear:hover{background:#e2e4e7;}' +
		'#moksa-ai-panel .mo-ai-close{background:transparent;border:0;color:#787c82;cursor:pointer;padding:2px 4px;box-shadow:none;display:flex;align-items:center;}' +
		'#moksa-ai-panel .mo-ai-close .dashicons{font-size:20px;width:20px;height:20px;line-height:20px;}' +
		'#moksa-ai-panel .mo-ai-close:hover{color:#1d2327;}' +
		'#moksa-ai-panel .mo-ai-msgs{flex:1;overflow-y:auto;padding:14px;background:#f6f7f7;font-size:13.5px;line-height:1.6;}' +
		'#moksa-ai-panel .mo-ai-row{margin:10px 0;display:flex;gap:8px;align-items:flex-start;}' +
		'#moksa-ai-panel .mo-ai-row.user{justify-content:flex-end;}' +
		'#moksa-ai-panel .mo-ai-col{display:flex;flex-direction:column;max-width:78%;}' +
		'#moksa-ai-panel .mo-ai-row.user .mo-ai-col{align-items:flex-end;}' +
		'#moksa-ai-panel .mo-ai-time{font-size:10.5px;color:#a7abb0;margin:3px 5px 0;}' +
		'#moksa-ai-panel .mo-ai-av{width:28px;height:28px;border-radius:50%;flex:0 0 28px;display:flex;align-items:center;justify-content:center;background:#1d2327;}' +
		'#moksa-ai-panel .mo-ai-av .dashicons{color:#d4af37;font-size:16px;width:16px;height:16px;line-height:16px;}' +
		'#moksa-ai-panel .mo-ai-bub{max-width:100%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;}' +
		'#moksa-ai-panel .mo-ai-bub a{color:#a16207;text-decoration:underline;}' +
		'#moksa-ai-panel .mo-ai-row.bot .mo-ai-bub{background:#fff;border:1px solid #e2e4e7;color:#1d2327;border-bottom-left-radius:4px;}' +
		'#moksa-ai-panel .mo-ai-row.user .mo-ai-bub{background:#1d2327;color:#fff;border-bottom-right-radius:4px;}' +
		'#moksa-ai-panel .mo-ai-row.user .mo-ai-bub a{color:#fde68a;}' +
		'#moksa-ai-panel .mo-ai-confirm{margin-top:9px;display:flex;gap:8px;}' +
		'#moksa-ai-panel .mo-ai-confirm button{border:0;border-radius:8px;padding:6px 13px;font-size:12.5px;cursor:pointer;box-shadow:none;}' +
		'#moksa-ai-panel .mo-ai-yes{background:#1d2327;color:#fff;}#moksa-ai-panel .mo-ai-yes:hover{background:#2c3338;}' +
		'#moksa-ai-panel .mo-ai-no{background:#f0f0f1;color:#50575e;}#moksa-ai-panel .mo-ai-no:hover{background:#e2e4e7;}' +
		'#moksa-ai-panel .mo-ai-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;border-radius:50%;background:#b9bcc0;animation:mo-ai-blink 1.2s infinite both;}' +
		'#moksa-ai-panel .mo-ai-typing span:nth-child(2){animation-delay:.2s;}#moksa-ai-panel .mo-ai-typing span:nth-child(3){animation-delay:.4s;}' +
		'@keyframes mo-ai-blink{0%,80%,100%{opacity:.25;}40%{opacity:1;}}' +
		'#moksa-ai-panel .mo-ai-chips{padding:0 14px 8px;display:flex;flex-wrap:wrap;gap:6px;background:#f6f7f7;}' +
		'#moksa-ai-panel .mo-ai-chip{background:#fff;border:1px solid #e0c068;color:#a16207;border-radius:14px;padding:5px 11px;font-size:12.5px;cursor:pointer;box-shadow:none;}' +
		'#moksa-ai-panel .mo-ai-chip:hover{background:#fdf6e3;}' +
		'#moksa-ai-panel .mo-ai-foot{display:flex;gap:8px;padding:10px;border-top:1px solid #e2e4e7;background:#fff;}' +
		'#moksa-ai-panel .mo-ai-input{flex:1;border:1px solid #c3c4c7;border-radius:20px;padding:9px 14px;font-size:13.5px;outline:none;box-shadow:none;background:#fff;-webkit-appearance:none;appearance:none;min-height:0;}' +
		'#moksa-ai-panel .mo-ai-input:focus{border-color:#c9a227;box-shadow:0 0 0 2px rgba(201,162,39,.18);outline:none;}' +
		'#moksa-ai-panel .mo-ai-send{background:#1d2327;color:#fff;border:0;border-radius:50%;width:38px;height:38px;flex:0 0 38px;cursor:pointer;font-size:16px;box-shadow:none;}' +
		'#moksa-ai-panel .mo-ai-send:hover{background:#2c3338;}#moksa-ai-panel .mo-ai-send:disabled{opacity:.5;cursor:default;}';
	document.head.appendChild( style );

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	var fab = document.createElement( 'button' );
	fab.id = 'moksa-ai-fab';
	fab.type = 'button';
	fab.innerHTML = '<span class="dashicons dashicons-cart"></span><span>' + esc( NAME ) + '</span>';

	var panel = document.createElement( 'div' );
	panel.id = 'moksa-ai-panel';
	panel.innerHTML =
		'<div class="mo-ai-head"><span class="mo-ai-dot"></span><b>' + esc( NAME ) + '</b>' +
		'<span class="mo-ai-beta">Beta</span><button type="button" class="mo-ai-clear">' + esc( S.clear || '清除' ) + '</button>' +
		'<button type="button" class="mo-ai-close" title="關閉" aria-label="關閉"><span class="dashicons dashicons-no-alt"></span></button></div>' +
		'<div class="mo-ai-msgs"></div>' +
		'<div class="mo-ai-chips"></div>' +
		'<div class="mo-ai-foot"><input type="text" class="mo-ai-input" placeholder="' + esc( S.placeholder || '' ) + '">' +
		'<button type="button" class="mo-ai-send" title="' + esc( S.send || '送出' ) + '">➤</button></div>';

	document.body.appendChild( fab );
	document.body.appendChild( panel );

	var msgs = panel.querySelector( '.mo-ai-msgs' );
	var chips = panel.querySelector( '.mo-ai-chips' );
	var input = panel.querySelector( '.mo-ai-input' );
	var send = panel.querySelector( '.mo-ai-send' );
	var busy = false;
	var started = false;

	// 對話保留:純 localStorage(不碰資料庫),per-user key,上限 100 則防膨脹。
	var LS_KEY = 'moksaAiChat:' + ( cfg.userId || 0 );
	var HISTORY = [];

	function pad2( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}
	function stamp( ms ) {
		var d = new Date( ms );
		return d.getFullYear() + '-' + pad2( d.getMonth() + 1 ) + '-' + pad2( d.getDate() ) +
			' ' + pad2( d.getHours() ) + ':' + pad2( d.getMinutes() ) + ':' + pad2( d.getSeconds() );
	}
	function save_history() {
		try {
			if ( HISTORY.length > 100 ) {
				HISTORY = HISTORY.slice( -100 );
			}
			window.localStorage.setItem( LS_KEY, JSON.stringify( HISTORY ) );
		} catch ( e ) {}
	}
	function load_history() {
		try {
			var raw = window.localStorage.getItem( LS_KEY );
			var arr = raw ? JSON.parse( raw ) : null;
			return Array.isArray( arr ) ? arr : null;
		} catch ( e ) {
			return null;
		}
	}

	// 行內:**粗體** 與 http(s) 連結(XSS-safe;同頁跳轉)。
	function inline_into( parent, text ) {
		var re = /(\*\*([^*]+)\*\*)|(https?:\/\/[^\s]+)/g;
		var last = 0, m;
		while ( ( m = re.exec( text ) ) !== null ) {
			if ( m.index > last ) {
				parent.appendChild( document.createTextNode( text.slice( last, m.index ) ) );
			}
			if ( m[ 1 ] ) {
				var b = document.createElement( 'strong' );
				b.textContent = m[ 2 ];
				parent.appendChild( b );
			} else {
				var a = document.createElement( 'a' );
				a.href = m[ 3 ];
				a.textContent = m[ 3 ];
				parent.appendChild( a );
			}
			last = m.index + m[ 0 ].length;
		}
		if ( last < text.length ) {
			parent.appendChild( document.createTextNode( text.slice( last ) ) );
		}
	}

	function fill_bubble( bubble, text ) {
		bubble.innerHTML = '';
		text = ( text == null ? '' : String( text ) ).replace( /\r\n/g, '\n' );
		text.split( '\n' ).forEach( function ( line, i ) {
			if ( i > 0 ) {
				bubble.appendChild( document.createElement( 'br' ) );
			}
			var bullet = line.match( /^\s*[*\-]\s+(.*)$/ );
			if ( bullet ) {
				bubble.appendChild( document.createTextNode( '• ' ) );
				inline_into( bubble, bullet[ 1 ] );
			} else {
				inline_into( bubble, line );
			}
		} );
	}

	function bot_avatar() {
		var av = document.createElement( 'div' );
		av.className = 'mo-ai-av';
		av.innerHTML = '<span class="dashicons dashicons-cart"></span>';
		return av;
	}

	// 一列 = [bot 頭像?] + 直欄(泡泡 + 時間戳)。
	function make_row( who ) {
		var row = document.createElement( 'div' );
		row.className = 'mo-ai-row ' + who;
		if ( who === 'bot' ) {
			row.appendChild( bot_avatar() );
		}
		var col = document.createElement( 'div' );
		col.className = 'mo-ai-col';
		row.appendChild( col );
		return { row: row, col: col };
	}

	function add_time( col, ts ) {
		var t = document.createElement( 'div' );
		t.className = 'mo-ai-time';
		t.textContent = stamp( ts );
		col.appendChild( t );
	}

	function reset() {
		msgs.innerHTML = '';
		started = false;
		chips.style.display = 'flex';
		HISTORY = [];
		try {
			window.localStorage.removeItem( LS_KEY );
		} catch ( e ) {}
		if ( GREETING ) {
			add_msg( GREETING, 'bot' );
		}
		render_chips();
	}

	// 重開面板時還原先前對話(localStorage);沒有就跑問候。
	function restore() {
		var saved = load_history();
		if ( ! saved || ! saved.length ) {
			reset();
			return;
		}
		msgs.innerHTML = '';
		started = true;
		chips.style.display = 'none';
		saved.forEach( function ( m ) {
			add_msg( m.t, m.w, m.ts, true );
		} );
		HISTORY = saved.slice();
	}

	function render_chips() {
		chips.innerHTML = '';
		EXAMPLES.forEach( function ( ex ) {
			var c = document.createElement( 'button' );
			c.type = 'button';
			c.className = 'mo-ai-chip';
			c.textContent = ex;
			c.addEventListener( 'click', function () {
				input.value = ex;
				ask();
			} );
			chips.appendChild( c );
		} );
	}

	function add_msg( text, who, ts, skip_persist ) {
		ts = ts || Date.now();
		var r = make_row( who );
		var bubble = document.createElement( 'div' );
		bubble.className = 'mo-ai-bub';
		fill_bubble( bubble, text );
		r.col.appendChild( bubble );
		add_time( r.col, ts );
		msgs.appendChild( r.row );
		msgs.scrollTop = msgs.scrollHeight;
		if ( ! skip_persist ) {
			HISTORY.push( { t: String( text == null ? '' : text ), w: who, ts: ts } );
			save_history();
		}
		return bubble;
	}

	function add_typing() {
		var r = make_row( 'bot' );
		var bub = document.createElement( 'div' );
		bub.className = 'mo-ai-bub mo-ai-typing';
		bub.innerHTML = '<span></span><span></span><span></span>';
		r.col.appendChild( bub );
		msgs.appendChild( r.row );
		msgs.scrollTop = msgs.scrollHeight;
		return r.row;
	}

	// 破壞性動作 → 顯示摘要 + 確認執行 / 取消。
	function render_confirm( confirm ) {
		var r = make_row( 'bot' );
		var bubble = document.createElement( 'div' );
		bubble.className = 'mo-ai-bub';
		fill_bubble( bubble, confirm.summary );
		var box = document.createElement( 'div' );
		box.className = 'mo-ai-confirm';
		var yes = document.createElement( 'button' );
		yes.type = 'button';
		yes.className = 'mo-ai-yes';
		yes.textContent = '確認執行';
		var no = document.createElement( 'button' );
		no.type = 'button';
		no.className = 'mo-ai-no';
		no.textContent = '取消';
		box.appendChild( yes );
		box.appendChild( no );
		bubble.appendChild( box );
		r.col.appendChild( bubble );
		add_time( r.col, Date.now() );
		msgs.appendChild( r.row );
		msgs.scrollTop = msgs.scrollHeight;

		no.addEventListener( 'click', function () {
			box.remove();
			add_msg( '已取消。', 'bot' );
		} );
		yes.addEventListener( 'click', function () {
			yes.disabled = true;
			no.disabled = true;
			yes.textContent = '執行中…';
			apiFetch( { url: cfg.restConfirm, method: 'POST', data: { token: confirm.token } } )
				.then( function ( r ) {
					box.remove();
					add_msg( r && r.reply ? r.reply : ( r && r.error ? '⚠ ' + r.error : ( S.emptyReply || '（無回覆）' ) ), 'bot' );
					// 列印類動作:確認後開新分頁載入列印頁。
					if ( r && r.print_url ) {
						window.open( r.print_url, '_blank' );
					}
				} )
				.catch( function ( e ) {
					box.remove();
					add_msg( '⚠ ' + ( e && e.message ? e.message : ( S.errorPrefix || '發生錯誤' ) ), 'bot' );
				} );
		} );
	}

	function ask() {
		var text = ( input.value || '' ).trim();
		if ( ! text || busy ) {
			return;
		}
		if ( ! started ) {
			started = true;
			chips.style.display = 'none';
		}
		busy = true;
		send.disabled = true;
		input.value = '';
		add_msg( text, 'user' );
		var typing = add_typing();

		// 帶最近 10 則對話(不含本則)給 Agent 當上下文記憶。
		var prior = HISTORY.slice( 0, -1 ).slice( -10 ).map( function ( m ) {
			return { role: m.w === 'user' ? 'user' : 'assistant', text: m.t };
		} );

		apiFetch( { url: cfg.restChat, method: 'POST', data: { message: text, history: prior } } )
			.then( function ( r ) {
				typing.remove();
				if ( r && r.confirm && r.confirm.token ) {
					render_confirm( r.confirm );
				} else {
					add_msg( r && r.reply ? r.reply : ( r && r.error ? '⚠ ' + r.error : ( S.emptyReply || '（無回覆）' ) ), 'bot' );
				}
			} )
			.catch( function ( e ) {
				typing.remove();
				add_msg( '⚠ ' + ( e && e.message ? e.message : ( S.errorPrefix || '發生錯誤' ) ), 'bot' );
			} )
			.finally( function () {
				busy = false;
				send.disabled = false;
				input.focus();
			} );
	}

	// 業界慣例:開啟時隱藏啟動鈕(FAB),用 header 的 X 關閉。
	function open_panel() {
		panel.style.display = 'flex';
		fab.style.display = 'none';
		if ( msgs.children.length === 0 ) {
			restore();
		}
		input.focus();
	}
	function close_panel() {
		panel.style.display = 'none';
		fab.style.display = 'flex';
	}
	fab.addEventListener( 'click', open_panel );
	panel.querySelector( '.mo-ai-close' ).addEventListener( 'click', close_panel );
	panel.querySelector( '.mo-ai-clear' ).addEventListener( 'click', reset );
	send.addEventListener( 'click', ask );
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			ask();
		}
	} );
} )( window.wp );
