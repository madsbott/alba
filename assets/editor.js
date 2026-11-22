/**
 * Alba – Sprachfilter für die Begriffsauswahl im Editor.
 * Block-Editor: REST-Anfragen für Begriffe bekommen mml_lang, neue Begriffe werden in der gewählten Sprache angelegt.
 * Klassischer Editor: Kategorie-Checkliste wird per JS gefiltert, Tag-Vorschläge und Schnell-Hinzufügen bekommen mml_lang.
 * Die Sprache wird live aus dem Dropdown der Box "Sprache & Übersetzungen" gelesen (#mml_lang).
 */
(function () {
	'use strict';

	var cfg = window.mmlEditor;
	if (!cfg) {
		return;
	}

	function curLang() {
		var s = document.getElementById('mml_lang');
		return (s && s.value) ? s.value : cfg.lang;
	}

	/* ---------------------------------------------------------------
	 * Block-Editor (apiFetch)
	 * ------------------------------------------------------------- */
	if (window.wp && wp.apiFetch && typeof wp.apiFetch.use === 'function') {
		wp.apiFetch.use(function (options, next) {
			var path = options && options.path;
			if (typeof path === 'string') {
				var m = path.match(/^\/wp\/v2\/([\w-]+)(\?.*)?$/);
				if (m && cfg.bases.indexOf(m[1]) !== -1) {
					var method = String(options.method || 'GET').toUpperCase();
					if (method === 'GET') {
						// bereits zugewiesene Begriffe (include=…) bleiben immer sichtbar
						if (path.indexOf('include=') === -1 && path.indexOf('mml_lang=') === -1) {
							options = Object.assign({}, options, {
								path: path + (path.indexOf('?') === -1 ? '?' : '&') + 'mml_lang=' + encodeURIComponent(curLang())
							});
						}
					} else if (method === 'POST' && options.data && typeof options.data === 'object' && !options.data.mml_lang) {
						options = Object.assign({}, options, {
							data: Object.assign({}, options.data, { mml_lang: curLang() })
						});
					}
				}
			}
			return next(options);
		});
	}

	function refreshBlockEditor() {
		try {
			if (window.wp && wp.data && typeof wp.data.dispatch === 'function') {
				var d = wp.data.dispatch('core');
				if (d && typeof d.invalidateResolutionForStoreSelector === 'function') {
					d.invalidateResolutionForStoreSelector('getEntityRecords');
				}
			}
		} catch (e) { /* ignorieren */ }
	}

	/* ---------------------------------------------------------------
	 * Klassischer Editor: Checklisten filtern
	 * ------------------------------------------------------------- */
	var pending = false;

	function applyChecklists() {
		pending = false;
		var lang = curLang();
		var taxes = cfg.terms || {};
		Object.keys(taxes).forEach(function (tx) {
			var map = taxes[tx];
			['#' + tx + 'checklist', '#' + tx + 'checklist-pop'].forEach(function (sel) {
				var root = document.querySelector(sel);
				if (!root) {
					return;
				}
				var items = root.querySelectorAll('li');
				// rückwärts: verschachtelte Einträge zuerst, damit Eltern sichtbar bleiben, wenn ein Kind passt
				for (var i = items.length - 1; i >= 0; i--) {
					var li = items[i];
					var input = li.querySelector(':scope > label input[type="checkbox"]');
					if (!input) {
						continue;
					}
					var termLang = map[String(input.value)] || lang; // unbekannte (neu angelegte) Begriffe bleiben sichtbar
					var show = termLang === lang || input.checked || li.querySelector('li:not([data-mml-hide])') !== null;
					if (show) {
						li.removeAttribute('data-mml-hide');
						li.style.display = '';
					} else {
						li.setAttribute('data-mml-hide', '1');
						li.style.display = 'none';
					}
				}
			});
		});
	}

	function scheduleApply() {
		if (pending) {
			return;
		}
		pending = true;
		(window.requestAnimationFrame || window.setTimeout)(applyChecklists, 0);
	}

	function watchChecklists() {
		Object.keys(cfg.terms || {}).forEach(function (tx) {
			var root = document.getElementById(tx + 'checklist');
			if (!root) {
				return;
			}
			root.addEventListener('change', scheduleApply);
			if (window.MutationObserver) {
				new MutationObserver(scheduleApply).observe(root, { childList: true, subtree: true });
			}
		});
		scheduleApply();
	}

	/* ---------------------------------------------------------------
	 * Klassischer Editor: Ajax (Tag-Vorschläge, Schnell-Hinzufügen)
	 * ------------------------------------------------------------- */
	if (window.jQuery && typeof jQuery.ajaxPrefilter === 'function') {
		jQuery.ajaxPrefilter(function (o) {
			var url = o.url || '';
			if (url.indexOf('action=ajax-tag-search') !== -1 && url.indexOf('mml_lang=') === -1) {
				o.url = url + '&mml_lang=' + encodeURIComponent(curLang());
			} else if (typeof o.data === 'string' && /(^|&)action=add-(?!meta)/.test(o.data) && o.data.indexOf('mml_lang=') === -1) {
				o.data += '&mml_lang=' + encodeURIComponent(curLang());
			}
		});
	}

	/* ---------------------------------------------------------------
	 * Sprachwechsel im Dropdown
	 * ------------------------------------------------------------- */
	document.addEventListener('change', function (e) {
		if (e.target && e.target.id === 'mml_lang') {
			scheduleApply();
			refreshBlockEditor();
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', watchChecklists);
	} else {
		watchChecklists();
	}
	window.addEventListener('load', scheduleApply);
})();
