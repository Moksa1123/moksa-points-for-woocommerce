<?php

declare( strict_types=1 );

namespace Moksa\Kit;

defined( 'ABSPATH' ) || exit;

/**
 * 共用設定 UI 資產(design system)— the `mowp-` shell CSS + behaviour JS every Moksa plugin's settings
 * screen is built on, aligned with mo-ectools' settings-shell / settings-polish visual language
 * (orange accent headings, iOS-style module toggles, collapsible section cards).
 *
 * This file used to exist five times over, byte-identical apart from its namespace; it now lives here
 * once, so a tweak to the design system reaches every plugin on the next kit sync instead of being
 * hand-carried four times. Enqueued inline, so there is no asset file and no packaging whitelist.
 *
 * Context-free by design: no options, no textdomain, no plugin identity — just the two strings.
 */
final class SettingsUi {

	/** The shared shell/design-system CSS (class prefix `mowp-`). */
	public static function css(): string {
		return <<<'CSS'
.mowp-shell{max-width:1000px}
.mowp-shell *{box-sizing:border-box}
.mowp-shell .mowp-intro{position:relative;margin:6px 0 22px;padding:0 0 14px}
.mowp-shell .mowp-intro h1,.mowp-shell .mowp-intro h2{position:relative;display:inline-block;margin:0 0 6px;padding:0;font-size:20px;font-weight:700;color:#0f172a;line-height:1.3}
.mowp-shell .mowp-intro h1::after,.mowp-shell .mowp-intro h2::after{content:"";position:absolute;left:0;bottom:-8px;width:100%;height:2px;background:linear-gradient(90deg,#f97316 0%,rgba(249,115,22,0) 100%);border-radius:1px}
.mowp-shell .mowp-intro h1{margin:0 0 6px;padding:0;font-weight:700}.mowp-shell .mowp-intro p{margin:14px 0 0;color:#64748b;font-size:13.5px;line-height:1.6;max-width:760px}
.mowp-shell .mowp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 20px;padding:0;border:0}
.mowp-shell .mowp-tabs .nav-tab{margin:0;padding:7px 15px;border:1px solid #e2e8f0;border-radius:999px;background:#fff;color:#475569;font-size:13px;font-weight:500;line-height:1.4;box-shadow:none}
.mowp-shell .mowp-tabs .nav-tab:hover{background:#f8fafc;color:#1d2327}
.mowp-shell .mowp-tabs .nav-tab-active,.mowp-shell .mowp-tabs .nav-tab-active:hover{background:#2271b1;border-color:#2271b1;color:#fff}
.mowp-shell .mowp-category{margin:0 0 26px}
.mowp-shell .mowp-category__title{margin:0 0 12px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#64748b}
.mowp-shell .mowp-list{display:grid;gap:10px;grid-template-columns:1fr}
.mowp-shell .mowp-card{display:grid;grid-template-columns:1fr auto;align-items:center;gap:18px;padding:15px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;transition:border-color .15s ease,box-shadow .15s ease}
.mowp-shell .mowp-card.is-on{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1 inset}
.mowp-shell .mowp-card__main{min-width:0}
.mowp-shell .mowp-card__head{display:flex;align-items:baseline;flex-wrap:wrap;gap:10px}
.mowp-shell .mowp-card__name{font-size:15px;font-weight:600;color:#1d2327}
.mowp-shell .mowp-card__tagline{font-size:12px;color:#64748b;line-height:1.5;margin-top:3px}
.mowp-shell .mowp-card__methods{margin-top:8px;display:flex;flex-wrap:wrap;gap:4px}
.mowp-shell .mowp-chip{display:inline-block;padding:2px 9px;background:#f1f5f9;color:#334155;border-radius:11px;font-size:11px;line-height:18px}
.mowp-shell .mowp-card__action{display:flex;align-items:center;gap:14px}
.mowp-shell .mowp-link{color:#2271b1;text-decoration:none;font-size:12px;white-space:nowrap;cursor:pointer}
.mowp-shell .mowp-link:hover{text-decoration:underline}
.mowp-shell .mowp-toggle{position:relative;display:inline-block;width:40px;height:22px;flex:0 0 auto;margin:0}
.mowp-shell .mowp-toggle input{opacity:0;width:0;height:0;position:absolute}
.mowp-shell .mowp-toggle__slider{position:absolute;cursor:pointer;inset:0;background:#c3c4c7;border-radius:22px;transition:background .15s ease}
.mowp-shell .mowp-toggle__slider::before{content:"";position:absolute;height:16px;width:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .15s ease;box-shadow:0 1px 2px rgba(0,0,0,.15)}
.mowp-shell .mowp-toggle input:checked + .mowp-toggle__slider{background:#2271b1}
.mowp-shell .mowp-toggle input:checked + .mowp-toggle__slider::before{transform:translateX(18px)}
.mowp-shell .mowp-toggle input:focus-visible + .mowp-toggle__slider{outline:2px solid #2271b1;outline-offset:2px}
.mowp-shell .mowp-section-card{margin:0 0 16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden}
.mowp-shell .mowp-section-card__head{position:relative;padding:14px 44px 14px 22px;margin:0;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);border-bottom:1px solid #e5e7eb;cursor:pointer;user-select:none;transition:background .15s ease}
.mowp-shell .mowp-section-card__head:hover{background:linear-gradient(180deg,#f1f5f9 0%,#e2e8f0 100%)}
.mowp-shell .mowp-section-card__title{position:relative;display:inline-block;font-size:14px;font-weight:600;color:#0f172a;line-height:1.4}
.mowp-shell .mowp-section-card__title::after{content:"";position:absolute;left:0;bottom:-6px;width:100%;height:2px;background:linear-gradient(90deg,#f97316 0%,rgba(249,115,22,0) 100%)}
.mowp-shell .mowp-section-card__chev{position:absolute;right:20px;top:50%;width:8px;height:8px;border:solid #64748b;border-width:0 2px 2px 0;transform:translateY(-50%) rotate(45deg);margin-top:-3px;transition:transform .2s ease}
.mowp-shell .mowp-section-card.is-collapsed .mowp-section-card__head{border-bottom:0}
.mowp-shell .mowp-section-card.is-collapsed .mowp-section-card__chev{transform:translateY(-50%) rotate(-45deg);margin-top:0}
.mowp-shell .mowp-section-card.is-collapsed .mowp-section-card__title::after{display:none}
.mowp-shell .mowp-section-card.is-collapsed .mowp-section-card__body{display:none}
.mowp-shell .mowp-section-card__desc{margin:0;padding:10px 22px 2px;color:#475569;font-size:13px;line-height:1.55}
.mowp-shell .mowp-section-card__body{padding:6px 0 2px}
.mowp-shell .mowp-section-card .mowp-list{gap:0}
.mowp-shell .mowp-section-card .mowp-card{border:0;border-bottom:1px solid #f1f5f9;border-radius:0;box-shadow:none;padding:13px 22px}
.mowp-shell .mowp-section-card .mowp-card:last-child{border-bottom:0}
.mowp-shell .mowp-section-card .mowp-card.is-on{box-shadow:none;background:#fbfdff}
.mowp-shell .mowp-field{padding:13px 22px;border-bottom:1px solid #f1f5f9}
.mowp-shell .mowp-field:last-child{border-bottom:0}
.mowp-shell .mowp-field__label{display:block;font-size:14px;font-weight:600;color:#1d2327}
.mowp-shell .mowp-field__desc{display:block;margin:2px 0 8px;color:#64748b;font-size:12px;line-height:1.55}
.mowp-shell .mowp-field input[type=text],.mowp-shell .mowp-field input[type=number],.mowp-shell .mowp-field input[type=url],.mowp-shell .mowp-field input[type=email],.mowp-shell .mowp-field select,.mowp-shell .mowp-field textarea{max-width:420px;width:100%}
.mowp-shell .mowp-field textarea{min-height:88px;font-family:inherit}
.mowp-shell .mowp-field input[type=color]{width:56px;height:34px;padding:2px;vertical-align:middle}
.mowp-shell .mowp-field--number input[type=number]{max-width:160px}
.mowp-shell .mowp-section-card table.form-table{margin:0}
.mowp-shell .mowp-section-card table.form-table th{padding-left:22px}
.mowp-shell .mowp-save{margin:22px 0 8px}
.mowp-shell .mowp-note{margin:0 0 14px;padding:10px 14px;border-radius:6px;font-size:13px;line-height:1.5}
.mowp-shell .mowp-note--warn{background:#fef9e7;border:1px solid #f5e0a3;color:#8a6d1f}
@media (max-width:782px){.mowp-shell .mowp-card{grid-template-columns:1fr auto;padding:13px 14px}.mowp-shell .mowp-section-card__head{padding:13px 40px 13px 16px}.mowp-shell .mowp-field,.mowp-shell .mowp-section-card .mowp-card{padding-left:16px;padding-right:16px}}
.mowp-shell .mowp-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin:0 0 22px}
.mowp-shell .mowp-tile{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px}
.mowp-shell .mowp-tile__num{font-size:26px;font-weight:700;color:#0f172a;line-height:1.2}
.mowp-shell .mowp-tile__label{color:#64748b;font-size:12.5px;margin-top:3px}
.mowp-shell .mowp-panels{display:flex;gap:16px;flex-wrap:wrap;margin:0 0 22px}
.mowp-shell .mowp-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;flex:1 1 300px;min-width:280px;align-self:flex-start}
.mowp-shell .mowp-panel--wide{flex-basis:100%}
.mowp-shell .mowp-panel__head{padding:12px 18px;background:linear-gradient(180deg,#f8fafc 0%,#f1f5f9 100%);border-bottom:1px solid #e5e7eb;font-size:13.5px;font-weight:600;color:#0f172a}
.mowp-shell .mowp-panel__body{padding:14px 18px}
.mowp-shell .mowp-panel__legend{color:#64748b;font-size:12px;margin-bottom:8px;display:flex;flex-wrap:wrap;gap:12px;align-items:center}
.mowp-shell .mowp-panel__empty{color:#64748b;font-size:13px;margin:2px 0 0}
.mowp-shell .mowp-datatable{width:100%;border-collapse:collapse;font-size:13px}
.mowp-shell .mowp-datatable td{padding:5px 10px 5px 0;border-bottom:1px solid #f1f5f9}
.mowp-shell .mowp-datatable tr:last-child td{border-bottom:0}
.mowp-shell .mowp-datatable .num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;color:#0f172a}
.mowp-shell .mowp-datatable code{background:#f1f5f9;color:#334155;border-radius:4px;padding:1px 6px;font-size:11.5px}
.mowp-shell .mowp-bar{background:#eef2f7;border-radius:4px;height:8px;width:110px;display:inline-block;vertical-align:middle;overflow:hidden}
.mowp-shell .mowp-bar__fill{background:#2271b1;height:100%;border-radius:4px}
.mowp-shell .mowp-linkcards{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin:6px 0 0}
.mowp-shell .mowp-linkcard{display:block;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;text-decoration:none;color:inherit;transition:border-color .15s ease,box-shadow .15s ease}
.mowp-shell .mowp-linkcard:hover{border-color:#2271b1;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.mowp-shell .mowp-linkcard__t{display:block;font-size:14.5px;font-weight:600;color:#2271b1}
.mowp-shell .mowp-linkcard__d{display:block;color:#64748b;font-size:12.5px;margin-top:4px}
.mowp-shell .mowp-callout{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #f97316;border-radius:8px;padding:14px 20px;margin:0 0 22px;max-width:760px}
.mowp-shell .mowp-callout__title{font-size:14px;font-weight:600;color:#0f172a}
.mowp-shell .mowp-callout ol{margin:8px 0 6px 20px}
.mowp-shell .mowp-callout li{margin:4px 0}
.mowp-shell .mowp-callout__done{color:#1a7f37;font-weight:600}
.mowp-shell .mowp-callout__doletext{color:#64748b}
.mowp-shell h2.mowp-h2{margin:2px 0 12px;font-size:15px;font-weight:700;color:#0f172a}
CSS;
	}

	/** The shared behaviour JS: collapsible section-cards (localStorage) + optional pill tabs + jump links. */
	public static function js(): string {
		return <<<'JS'
(function(){
	function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
	ready(function(){
		var shell = document.querySelector('.mowp-shell');
		if(!shell){ return; }
		var ns  = shell.getAttribute('data-ns') || 'moksa';
		var KEY = 'moksa_set_collapsed_' + ns;
		function load(){ try{ return JSON.parse(localStorage.getItem(KEY)) || {}; }catch(e){ return {}; } }
		function save(s){ try{ localStorage.setItem(KEY, JSON.stringify(s)); }catch(e){} }
		var state = load();
		shell.querySelectorAll('.mowp-section-card').forEach(function(card){
			var head = card.querySelector('.mowp-section-card__head');
			if(!head){ return; }
			var t   = card.querySelector('.mowp-section-card__title');
			var key = (card.getAttribute('data-key') || (t ? t.textContent : '') || '').trim();
			if(Object.prototype.hasOwnProperty.call(state, key)){ card.classList.toggle('is-collapsed', !!state[key]); }
			head.setAttribute('role','button');
			head.setAttribute('tabindex','0');
			head.setAttribute('aria-expanded', card.classList.contains('is-collapsed') ? 'false' : 'true');
			function toggle(){
				card.classList.toggle('is-collapsed');
				var c = card.classList.contains('is-collapsed');
				head.setAttribute('aria-expanded', c ? 'false' : 'true');
				var st = load(); st[key] = c ? 1 : 0; save(st);
			}
			head.addEventListener('click', toggle);
			head.addEventListener('keydown', function(e){ if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); toggle(); } });
		});
		var tabs = shell.querySelectorAll('.mowp-tabs .nav-tab');
		function activate(slug){
			tabs.forEach(function(t){ t.classList.toggle('nav-tab-active', t.getAttribute('data-tab') === slug); });
			shell.querySelectorAll('[data-pane]').forEach(function(p){ p.style.display = (p.getAttribute('data-pane') === slug) ? '' : 'none'; });
		}
		if(tabs.length){
			tabs.forEach(function(t){ t.addEventListener('click', function(e){ e.preventDefault(); activate(t.getAttribute('data-tab')); }); });
			var firstTab = shell.querySelector('.mowp-tabs .nav-tab-active') || tabs[0];
			if(firstTab){ activate(firstTab.getAttribute('data-tab')); }
		}
		shell.querySelectorAll('.mowp-jump').forEach(function(a){
			a.addEventListener('click', function(e){
				e.preventDefault();
				var id = a.getAttribute('data-target');
				if(!id){ return; }
				var target = document.getElementById(id);
				if(!target){ return; }
				var pane = target.closest ? target.closest('[data-pane]') : null;
				if(pane && tabs.length){ activate(pane.getAttribute('data-pane')); }
				target.classList.remove('is-collapsed');
				if(target.scrollIntoView){ target.scrollIntoView({ behavior:'smooth', block:'start' }); }
			});
		});
	});
})();
JS;
	}
}
