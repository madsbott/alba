(function () {
	function mmlT(s) { return (window.mmlI18n && window.mmlI18n[s]) || s; }
	function mmlF(s) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return mmlT(s).replace(/%(\d+\$)?s/g, function (m, p) { var k = p ? parseInt(p, 10) - 1 : i++; return a[k]; }); }
	var form = document.getElementById('mml-tr-form');
	if (!form) {
		return;
	}
	var rows = [].slice.call(form.querySelectorAll('.mml-row'));
	var counter = document.getElementById('mml-count');
	var slugRow = rows.filter(function (r) { return r.getAttribute('data-field') === 'slug'; })[0] || null;
	var titleRow = rows.filter(function (r) { return r.getAttribute('data-field') === 'title'; })[0] || null;
	var counted = rows.filter(function (r) { return r !== slugRow; });
	var slugTouched = !!(slugRow && area(slugRow).value.replace(/\s+/g, '') !== '');

	function slugify(s) {
		return String(s).toLowerCase()
			.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
			.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
	}
	var dirty = false;

	function area(row) {
		return row.querySelector('textarea');
	}

	function grow(t) {
		t.style.height = 'auto';
		t.style.height = Math.max(t.scrollHeight + 2, 40) + 'px';
	}

	function counters() {
		rows.forEach(function (r) {
			var c = r.querySelector('.mml-cnt');
			if (!c) {
				return;
			}
			var n = area(r).value.length;
			var max = parseInt(c.getAttribute('data-max'), 10);
			c.textContent = n + ' / ' + max;
			c.style.color = n > max ? '#d63638' : '#787c82';
			c.style.marginLeft = '8px';
			c.style.textTransform = 'none';
		});
	}

	function update() {
		counters();
		var n = 0;
		rows.forEach(function (r) {
			var done = area(r).value.replace(/\s+/g, '') !== '';
			if (done) {
				n++;
			}
			r.classList.toggle('mml-done', done);
		});
		counter.textContent = mmlF('%1$s of %2$s translated', counted.filter(function (r) { return r.classList.contains('mml-done'); }).length, counted.length);
		rows.forEach(checkTerms);
	}

	function esc(s) {
		return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	// Glossar: Begriff in der Übersetzung vorhanden? Vorschlag nur bei leerem Feld sichtbar
	function checkTerms(r) {
		var v = area(r).value;
		var empty = v.replace(/\s+/g, '') === '';
		var tm = r.querySelector('.mml-tm');
		if (tm) {
			tm.classList.toggle('mml-hide', !empty);
		}
		[].slice.call(r.querySelectorAll('.mml-term')).forEach(function (t) {
			var target = t.getAttribute('data-target') || '';
			var re = new RegExp('(^|[^\\p{L}\\p{N}_])' + esc(target) + '(?![\\p{L}\\p{N}_])', t.getAttribute('data-cs') === '1' ? 'u' : 'iu');
			t.classList.toggle('mml-ok', !empty && re.test(v));
			t.classList.toggle('mml-miss', !empty && !re.test(v));
		});
	}

	rows.forEach(function (r) {
		var t = area(r);
		grow(t);
		t.addEventListener('input', function () {
			if (r === slugRow) {
				slugTouched = true;
			} else if (r === titleRow && slugRow && !slugTouched) {
				area(slugRow).value = slugify(t.value);
			}
			grow(t);
			update();
			dirty = true;
		});
		var cp = r.querySelector('.mml-copy');
		if (cp) {
			cp.addEventListener('click', function () {
				t.value = t.getAttribute('data-src') || '';
				grow(t);
				update();
				dirty = true;
			});
		}
		var gen = r.querySelector('.mml-slug-gen');
		if (gen) {
			gen.addEventListener('click', function () {
				var src = titleRow ? area(titleRow).value : '';
				t.value = slugify(src);
				slugTouched = true;
				grow(t);
				update();
				dirty = true;
			});
		}
	});

	rows.forEach(function (r) {
		var b = r.querySelector('.mml-tm-use');
		if (b) {
			b.addEventListener('click', function () {
				var t = area(r);
				t.value = r.querySelector('.mml-tm').getAttribute('data-tm') || '';
				grow(t);
				update();
				dirty = true;
			});
		}
	});

	var tmAll = document.getElementById('mml-tm-all');
	if (tmAll) {
		tmAll.addEventListener('click', function () {
			rows.forEach(function (r) {
				var tm = r.querySelector('.mml-tm');
				var t = area(r);
				if (tm && t.value.replace(/\s+/g, '') === '') {
					t.value = tm.getAttribute('data-tm') || '';
					grow(t);
				}
			});
			update();
			dirty = true;
			tmAll.style.display = 'none';
		});
	}

	document.getElementById('mml-fill').addEventListener('click', function () {
		rows.forEach(function (r) {
			var t = area(r);
			if (r !== slugRow && t.value.replace(/\s+/g, '') === '') {
				t.value = t.getAttribute('data-src') || '';
				grow(t);
			}
		});
		update();
		dirty = true;
	});

	document.getElementById('mml-on').addEventListener('change', function () {
		dirty = true;
	});

	// Automatische Übersetzungsvorschläge (MyMemory / LibreTranslate): nur Anzeige, Übernehmen bestätigt der Nutzer
	var mt = window.mmlMT || null;

	function mtBox(r, kind, text) {
		var tgt = r.querySelector('.mml-tgt');
		var box = tgt.querySelector('.mml-mt');
		if (!box) {
			box = document.createElement('div');
			tgt.appendChild(box);
		}
		box.className = 'mml-mt' + (kind === 'err' ? ' mml-err' : '');
		box.textContent = '';
		var l = document.createElement('span');
		l.className = 'mml-tm-l';
		var t = document.createElement('span');
		t.className = 'mml-mt-t';
		box.appendChild(l);
		box.appendChild(t);
		if (kind === 'load') {
			l.textContent = mmlT('Fetching suggestion …');
		} else if (kind === 'err') {
			l.textContent = mmlT('No suggestion:');
			t.textContent = text;
		} else {
			l.textContent = mmlF('Automatic suggestion (%s) – please check:', mt.provider);
			t.textContent = text;
			var use = document.createElement('button');
			use.type = 'button';
			use.className = 'button-link';
			use.textContent = mmlT('Apply');
			use.addEventListener('click', function () {
				var a = area(r);
				a.value = text;
				grow(a);
				update();
				dirty = true;
				box.remove();
			});
			var no = document.createElement('button');
			no.type = 'button';
			no.className = 'button-link';
			no.style.marginLeft = '10px';
			no.textContent = mmlT('Discard');
			no.addEventListener('click', function () { box.remove(); });
			box.appendChild(use);
			box.appendChild(no);
		}
	}

	function mtFetch(list) {
		list.forEach(function (r) { mtBox(r, 'load'); });
		var fd = new FormData();
		fd.append('action', 'mml_mt');
		fd.append('nonce', mt.nonce);
		fd.append('from', mt.from);
		fd.append('to', mt.to);
		fd.append('texts', JSON.stringify(list.map(function (r) { return area(r).getAttribute('data-raw') || ''; })));
		return fetch(mt.url, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (res) { return res.json(); })
			.then(function (j) {
				if (!j || !j.success) {
					var msg = (j && j.data) ? String(j.data) : mmlT('Unknown error.');
					list.forEach(function (r) { mtBox(r, 'err', msg); });
					return false;
				}
				list.forEach(function (r, i) { mtBox(r, 'ok', j.data.texts[i]); });
				return true;
			})
			.catch(function () {
				list.forEach(function (r) { mtBox(r, 'err', mmlT('The service is not reachable.')); });
				return false;
			});
	}

	if (mt) {
		rows.forEach(function (r) {
			var b = r.querySelector('.mml-mt-get');
			if (b) {
				b.addEventListener('click', function () { mtFetch([r]); });
			}
		});
		var mtAll = document.getElementById('mml-mt-all');
		if (mtAll) {
			mtAll.addEventListener('click', function () {
				var todo = rows.filter(function (r) {
					var tm = r.querySelector('.mml-tm');
					return r.querySelector('.mml-mt-get') && area(r).value.replace(/\s+/g, '') === '' && !(tm && !tm.classList.contains('mml-hide'));
				});
				if (!todo.length) {
					return;
				}
				mtAll.disabled = true;
				var i = 0;
				(function next() {
					if (i >= todo.length) {
						mtAll.disabled = false;
						return;
					}
					var batch = todo.slice(i, i + 3);
					i += 3;
					mtFetch(batch).then(function (ok) {
						if (ok) { next(); } else { mtAll.disabled = false; }
					});
				})();
			});
		}
	}

	form.addEventListener('submit', function () {
		var data = { title: '', excerpt: '', seg: {}, on: document.getElementById('mml-on').checked };
		rows.forEach(function (r) {
			var f = r.getAttribute('data-field');
			var v = area(r).value;
			if (f === 'seg') {
				data.seg[r.getAttribute('data-hash')] = v;
			} else {
				data[f] = v;
			}
		});
		document.getElementById('mml-data').value = JSON.stringify(data);
		dirty = false;
	});

	window.addEventListener('beforeunload', function (e) {
		if (dirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	update();
})();
