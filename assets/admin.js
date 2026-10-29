/* SiteShift Migration – admin screen. */
(function () {
	'use strict';

	var cfg = window.MWPM || {};
	var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

	var busy = false;
	var MAX_RETRIES = 6;
	var CHECK_SVG = '<svg class="mwpm-svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>';

	/* Progress steps per job, and which server stage belongs to which step. */
	var STEPS = {
		export: { labels: ['Database', 'Files', 'Archive'], stages: { db: 0, scan: 1, archive: 2 } },
		upload: { labels: ['Upload', 'Files', 'Database', 'URLs', 'Finish'], stages: { validate: 1, extract: 1, db_import: 2, db_replace: 3, db_swap: 4, finish: 4 } },
		restore: { labels: ['Files', 'Database', 'URLs', 'Finish'], stages: { validate: 0, extract: 0, db_import: 1, db_replace: 2, db_swap: 3, finish: 3 } }
	};

	/* ------------------------------------------------------------------
	 * Tabs (WAI-ARIA tabs pattern, arrow keys move between tabs)
	 * ---------------------------------------------------------------- */

	var tabs = $$('.mwpm-tab');

	function showTab(name, focus) {
		if (!$('[data-panel="' + name + '"]')) {
			name = 'export';
		}
		tabs.forEach(function (tab) {
			var active = tab.getAttribute('data-tab') === name;
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.tabIndex = active ? 0 : -1;
			if (active && focus) {
				tab.focus();
			}
		});
		$$('.mwpm-panel').forEach(function (panel) {
			panel.hidden = panel.getAttribute('data-panel') !== name;
		});
		if (history.replaceState) {
			history.replaceState(null, '', '#' + name);
		}
	}

	tabs.forEach(function (tab, i) {
		tab.addEventListener('click', function () { showTab(tab.getAttribute('data-tab')); });
		tab.addEventListener('keydown', function (e) {
			var next = null;
			if (e.key === 'ArrowRight') { next = tabs[(i + 1) % tabs.length]; }
			if (e.key === 'ArrowLeft') { next = tabs[(i - 1 + tabs.length) % tabs.length]; }
			if (e.key === 'Home') { next = tabs[0]; }
			if (e.key === 'End') { next = tabs[tabs.length - 1]; }
			if (next) {
				e.preventDefault();
				showTab(next.getAttribute('data-tab'), true);
			}
		});
	});

	$$('[data-tab-link]').forEach(function (link) {
		link.addEventListener('click', function (e) {
			e.preventDefault();
			showTab(link.getAttribute('data-tab-link'), true);
		});
	});

	showTab((location.hash || '#export').slice(1));

	/* ------------------------------------------------------------------
	 * Dialog helpers (focus trap + restore focus)
	 * ---------------------------------------------------------------- */

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), [tabindex]:not([tabindex="-1"])';
	var openDialogs = [];

	function openDialog(modalEl, initialFocus) {
		modalEl.hidden = false;
		openDialogs.push({ el: modalEl, returnTo: document.activeElement });
		document.body.style.overflow = 'hidden';
		var target = initialFocus || $('.mwpm-dialog', modalEl);
		setTimeout(function () { target.focus(); }, 30);
	}

	function closeDialog(modalEl) {
		modalEl.hidden = true;
		for (var i = openDialogs.length - 1; i >= 0; i--) {
			if (openDialogs[i].el === modalEl) {
				var returnTo = openDialogs[i].returnTo;
				openDialogs.splice(i, 1);
				if (returnTo && document.contains(returnTo)) {
					returnTo.focus();
				}
				break;
			}
		}
		if (!openDialogs.length) {
			document.body.style.overflow = '';
		}
	}

	document.addEventListener('keydown', function (e) {
		var top = openDialogs[openDialogs.length - 1];
		if (!top) {
			return;
		}
		if (e.key === 'Escape') {
			var cancel = $('[data-cancel]', top.el);
			if (cancel) {
				e.preventDefault();
				cancel.click();
			} else if (!busy) {
				e.preventDefault();
				closeProgress(true);
			}
			return;
		}
		if (e.key !== 'Tab') {
			return;
		}
		var items = $$(FOCUSABLE, top.el).filter(function (el) { return el.offsetParent !== null; });
		if (!items.length) {
			e.preventDefault();
			return;
		}
		var first = items[0];
		var last = items[items.length - 1];
		if (e.shiftKey && (document.activeElement === first || !top.el.contains(document.activeElement))) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	});

	/* ------------------------------------------------------------------
	 * Confirm dialog
	 * ---------------------------------------------------------------- */

	var confirmEl = $('#mwpm-confirm');

	/**
	 * @param {Object} o {title, body, confirmLabel, check}
	 * @return {Promise<boolean>}
	 */
	function confirmDialog(o) {
		return new Promise(function (resolve) {
			var ok = $('[data-confirm]', confirmEl);
			var cancel = $('[data-cancel]', confirmEl);
			var checkWrap = $('.mwpm-confirm-check', confirmEl);
			var check = $('input', checkWrap);

			$('#mwpm-confirm-title').textContent = o.title;
			$('#mwpm-confirm-body').textContent = o.body;
			ok.textContent = o.confirmLabel;
			checkWrap.hidden = !o.check;
			check.checked = false;
			$('span', checkWrap).textContent = o.check || '';
			ok.disabled = !!o.check;

			function onCheck() { ok.disabled = !check.checked; }
			function done(result) {
				ok.removeEventListener('click', onOk);
				cancel.removeEventListener('click', onCancel);
				check.removeEventListener('change', onCheck);
				confirmEl.removeEventListener('click', onBackdrop);
				closeDialog(confirmEl);
				resolve(result);
			}
			function onOk() { done(true); }
			function onCancel() { done(false); }
			function onBackdrop(e) { if (e.target === confirmEl) { done(false); } }

			ok.addEventListener('click', onOk);
			cancel.addEventListener('click', onCancel);
			check.addEventListener('change', onCheck);
			confirmEl.addEventListener('click', onBackdrop);
			openDialog(confirmEl, cancel);
		});
	}

	/* ------------------------------------------------------------------
	 * Toast
	 * ---------------------------------------------------------------- */

	var toastEl = $('#mwpm-toast');
	var toastTimer = null;

	function toast(message, isError) {
		toastEl.textContent = message;
		toastEl.classList.toggle('is-error', !!isError);
		toastEl.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4000);
	}

	/* ------------------------------------------------------------------
	 * Progress dialog
	 * ---------------------------------------------------------------- */

	var modal = $('#mwpm-modal');
	var steps = null;
	var timer = null;
	var startedAt = 0;

	function openProgress(title, stepsKey) {
		modal.className = 'mwpm-modal';
		$('#mwpm-modal-title').textContent = title;
		renderSteps(stepsKey);
		setProgress(0, 'Starting…');
		setButtons([]);
		startTimer();
		if (modal.hidden) {
			openDialog(modal);
		}
	}

	function renderSteps(key) {
		steps = STEPS[key] || null;
		var list = $('.mwpm-steps', modal);
		list.innerHTML = '';
		if (!steps) {
			return;
		}
		steps.labels.forEach(function (label) {
			var li = document.createElement('li');
			li.innerHTML = '<span class="mwpm-step-dot">' + CHECK_SVG + '</span>';
			li.appendChild(document.createTextNode(label));
			list.appendChild(li);
		});
		setStep(0);
	}

	function setStep(index) {
		$$('.mwpm-steps li', modal).forEach(function (li, i) {
			li.classList.toggle('is-done', i < index);
			li.classList.toggle('is-active', i === index);
			if (i === index) {
				li.setAttribute('aria-current', 'step');
			} else {
				li.removeAttribute('aria-current');
			}
		});
	}

	function setStage(stage) {
		if (steps && stage && Object.prototype.hasOwnProperty.call(steps.stages, stage)) {
			setStep(steps.stages[stage]);
		}
	}

	function setProgress(percent, status) {
		percent = Math.max(0, Math.min(100, Number(percent) || 0));
		$('.mwpm-bar span', modal).style.transform = 'scaleX(' + (percent / 100) + ')';
		$('.mwpm-bar', modal).setAttribute('aria-valuenow', String(Math.round(percent)));
		$('.mwpm-percent', modal).textContent = (percent >= 100 ? 100 : percent.toFixed(1)) + '%';
		if (status !== undefined) {
			$('.mwpm-status', modal).textContent = status;
		}
	}

	function startTimer() {
		startedAt = Date.now();
		clearInterval(timer);
		tick();
		timer = setInterval(tick, 1000);
	}

	function tick() {
		var s = Math.floor((Date.now() - startedAt) / 1000);
		var h = Math.floor(s / 3600);
		var m = Math.floor((s % 3600) / 60);
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		$('.mwpm-elapsed', modal).textContent = 'Elapsed ' + (h ? h + ':' : '') + pad(m) + ':' + pad(s % 60);
	}

	function stopTimer() {
		clearInterval(timer);
	}

	function finishState(state, title, status) {
		busy = false;
		stopTimer();
		modal.className = 'mwpm-modal is-' + state;
		$('#mwpm-modal-title').textContent = title;
		$('.mwpm-status', modal).textContent = status || '';
		if (state === 'done') {
			setStep(steps ? steps.labels.length : 0);
		}
	}

	/**
	 * @param {Array} buttons [{label, primary, href, onClick}]
	 */
	function setButtons(buttons) {
		var box = $('.mwpm-buttons', modal);
		box.innerHTML = '';
		buttons.forEach(function (b, i) {
			var el = document.createElement(b.href ? 'a' : 'button');
			el.className = 'mwpm-btn ' + (b.primary ? 'mwpm-btn-primary' : 'mwpm-btn-secondary');
			el.textContent = b.label;
			if (b.href) {
				el.href = b.href;
			} else {
				el.type = 'button';
			}
			if (b.onClick) {
				el.addEventListener('click', b.onClick);
			}
			box.appendChild(el);
			if (i === 0) {
				setTimeout(function () { el.focus(); }, 30);
			}
		});
	}

	function closeProgress(reload) {
		stopTimer();
		closeDialog(modal);
		if (reload) {
			location.reload();
		}
	}

	window.addEventListener('beforeunload', function (e) {
		if (busy) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	/* ------------------------------------------------------------------
	 * Requests
	 * ---------------------------------------------------------------- */

	function ServerError(message) {
		this.message = message;
		this.server = true;
	}

	function parseJSON(text) {
		try {
			return JSON.parse(text);
		} catch (e) {
			// Another plugin may have printed something before our JSON.
			var start = text.indexOf('{"success"');
			if (start > -1) {
				try {
					return JSON.parse(text.slice(start));
				} catch (e2) { /* fall through */ }
			}
		}
		return null;
	}

	function post(action, data, blob) {
		var body = new FormData();
		body.append('action', action);
		Object.keys(data || {}).forEach(function (key) {
			body.append(key, data[key]);
		});
		if (blob) {
			body.append('chunk', blob, 'chunk.bin');
		}
		return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (res) {
				return res.text().then(function (text) {
					var json = parseJSON(text);
					if (!json) {
						var snippet = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
						throw new Error('Unexpected server response (HTTP ' + res.status + ')' + (snippet ? ': ' + snippet : ''));
					}
					if (!json.success) {
						throw new ServerError((json.data && json.data.message) || 'Unknown error');
					}
					return json.data;
				});
			});
	}

	function wait(ms) {
		return new Promise(function (resolve) { setTimeout(resolve, ms); });
	}

	/**
	 * Retries network failures and timeouts (every step can safely be repeated).
	 * Errors reported by the plugin itself are not retried automatically.
	 */
	function postRetry(action, data, blob) {
		var attempt = 0;
		function run() {
			return post(action, data, blob).catch(function (err) {
				if (err.server || attempt >= MAX_RETRIES) {
					throw err;
				}
				attempt++;
				$('.mwpm-status', modal).textContent = 'Connection problem, retrying (' + attempt + ' of ' + MAX_RETRIES + ')…';
				return wait(Math.min(2000 * attempt, 10000)).then(run);
			});
		}
		return run();
	}

	/* ------------------------------------------------------------------
	 * Job runner
	 * ---------------------------------------------------------------- */

	function runSteps(job, onDone) {
		function next() {
			return postRetry('mwpm_step', job).then(function (res) {
				setStage(res.stage);
				setProgress(res.progress, res.message);
				if (res.done) {
					return onDone(res);
				}
				return next();
			});
		}
		return next();
	}

	function failed(err, retry) {
		finishState('error', 'Something went wrong', err.message || String(err));
		var buttons = [{ label: 'Close', onClick: function () { closeProgress(true); } }];
		if (retry) {
			buttons.unshift({ label: 'Retry', primary: true, onClick: retry });
		}
		setButtons(buttons);
	}

	function resume(job, onDone) {
		return function () {
			busy = true;
			modal.className = 'mwpm-modal';
			setButtons([]);
			$('#mwpm-modal-title').textContent = 'Resuming…';
			startTimer();
			runSteps(job, onDone).catch(function (err) { failed(err, resume(job, onDone)); });
		};
	}

	/* ------------------------------------------------------------------
	 * Export
	 * ---------------------------------------------------------------- */

	var tiles = $$('.mwpm-tile input');
	var includeError = $('#mwpm-include-error');

	// Fallback styling for browsers without CSS :has().
	tiles.forEach(function (box) {
		var sync = function () { box.closest('.mwpm-tile').classList.toggle('is-checked', box.checked); };
		box.addEventListener('change', function () {
			sync();
			if (tiles.some(function (t) { return t.checked; })) {
				includeError.hidden = true;
			}
		});
		sync();
	});

	function exportDone(res) {
		finishState('done', 'Backup ready', res.file + ' · ' + res.size);
		setButtons([
			{ label: 'Download backup', primary: true, href: res.download_url },
			{ label: 'Close', onClick: function () { closeProgress(true); } }
		]);
	}

	function startExport() {
		if (busy) {
			return;
		}
		if (!tiles.some(function (t) { return t.checked; })) {
			showTab('export');
			includeError.hidden = false;
			tiles[0].focus();
			return;
		}

		var data = { nonce: cfg.nonce };
		$$('[data-opt]').forEach(function (box) {
			var on = box.getAttribute('data-invert') === '1' ? !box.checked : box.checked;
			data[box.getAttribute('data-opt')] = on ? '1' : '0';
		});

		busy = true;
		openProgress('Creating backup', 'export');
		post('mwpm_export_start', data)
			.then(function (job) {
				return runSteps(job, exportDone).catch(function (err) { failed(err, resume(job, exportDone)); });
			})
			.catch(function (err) { failed(err); });
	}

	$('#mwpm-export').addEventListener('click', startExport);
	$$('[data-action="export"]').forEach(function (btn) { btn.addEventListener('click', startExport); });

	/* ------------------------------------------------------------------
	 * Import
	 * ---------------------------------------------------------------- */

	function importDone(res) {
		var text = 'Your site has been imported.';
		if (res.skipped) {
			text += ' ' + res.skipped + ' file(s) could not be written; check folder permissions.';
		}
		if (res.relogin) {
			text += ' The database was replaced, so log in with a username and password of the imported site, then open Settings → Permalinks and click Save.';
		}
		finishState('done', 'Import complete', text);
		setButtons(res.relogin
			? [{ label: 'Log in', primary: true, href: res.login_url }]
			: [{ label: 'Close', primary: true, onClick: function () { closeProgress(true); } }]);
	}

	function confirmImport(name) {
		return confirmDialog({
			title: 'Replace this site?',
			body: '"' + name + '" will overwrite the database, media, themes and plugins of this site. This cannot be undone.',
			check: 'I understand that the current site will be replaced.',
			confirmLabel: 'Yes, import'
		});
	}

	function upload(file, job) {
		var size = file.size;
		var chunk = Math.max(65536, parseInt(cfg.chunkSize, 10) || 1048576);
		var offset = 0;
		var started = Date.now();

		function next() {
			if (offset >= size) {
				return Promise.resolve();
			}
			var blob = file.slice(offset, Math.min(offset + chunk, size));
			return postRetry('mwpm_upload', { job: job.job, token: job.token, offset: offset }, blob).then(function (res) {
				offset = res.complete ? size : res.offset;
				var seconds = (Date.now() - started) / 1000;
				var speed = seconds > 0 ? offset / seconds : 0;
				var eta = speed > 0 ? Math.round((size - offset) / speed) : 0;
				setProgress(offset / size * 100, 'Uploading ' + formatSize(offset) + ' of ' + formatSize(size) +
					(speed ? ' · ' + formatSize(speed) + '/s' : '') + (eta > 0 ? ' · about ' + formatDuration(eta) + ' left' : ''));
				return next();
			});
		}
		return next();
	}

	function startUpload(file) {
		if (busy || !file) {
			return;
		}
		var ext = '.' + (cfg.extension || 'mwpm');
		if (file.name.slice(-ext.length).toLowerCase() !== ext) {
			toast('Please choose a ' + ext + ' backup file made by SiteShift Migration.', true);
			return;
		}
		confirmImport(file.name).then(function (ok) {
			if (!ok) {
				return;
			}
			busy = true;
			openProgress('Importing ' + file.name, 'upload');
			post('mwpm_import_start', { nonce: cfg.nonce, mode: 'upload', name: file.name, size: file.size })
				.then(function (job) {
					var stepJob = { job: job.job, token: job.token };
					function go() {
						upload(file, job).then(function () {
							setStep(1);
							setProgress(0, 'Preparing import…');
							return runSteps(stepJob, importDone).catch(function (err) { failed(err, resume(stepJob, importDone)); });
						}, function (err) {
							failed(err, function () {
								busy = true;
								modal.className = 'mwpm-modal';
								setButtons([]);
								startTimer();
								go();
							});
						});
					}
					go();
				})
				.catch(function (err) { failed(err); });
		});
	}

	var drop = $('#mwpm-drop');
	var input = $('#mwpm-file');

	$('#mwpm-choose').addEventListener('click', function () { input.click(); });
	input.addEventListener('change', function () {
		startUpload(input.files[0]);
		input.value = '';
	});

	var dragDepth = 0;
	drop.addEventListener('dragenter', function (e) {
		e.preventDefault();
		dragDepth++;
		drop.classList.add('is-over');
	});
	drop.addEventListener('dragover', function (e) {
		e.preventDefault();
	});
	drop.addEventListener('dragleave', function () {
		dragDepth = Math.max(0, dragDepth - 1);
		if (!dragDepth) {
			drop.classList.remove('is-over');
		}
	});
	drop.addEventListener('drop', function (e) {
		e.preventDefault();
		dragDepth = 0;
		drop.classList.remove('is-over');
		if (e.dataTransfer && e.dataTransfer.files.length) {
			startUpload(e.dataTransfer.files[0]);
		}
	});

	/* ------------------------------------------------------------------
	 * Backups list
	 * ---------------------------------------------------------------- */

	$$('.mwpm-restore').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var file = btn.closest('.mwpm-item').getAttribute('data-file');
			if (busy) {
				return;
			}
			confirmImport(file).then(function (ok) {
				if (!ok) {
					return;
				}
				busy = true;
				openProgress('Restoring backup', 'restore');
				post('mwpm_import_start', { nonce: cfg.nonce, mode: 'backup', file: file })
					.then(function (job) {
						return runSteps(job, importDone).catch(function (err) { failed(err, resume(job, importDone)); });
					})
					.catch(function (err) { failed(err); });
			});
		});
	});

	$$('.mwpm-delete').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var row = btn.closest('.mwpm-item');
			var file = row.getAttribute('data-file');
			confirmDialog({
				title: 'Delete this backup?',
				body: '"' + file + '" will be removed from the server permanently.',
				confirmLabel: 'Delete backup'
			}).then(function (ok) {
				if (!ok) {
					return;
				}
				btn.disabled = true;
				post('mwpm_delete', { nonce: cfg.nonce, file: file })
					.then(function () {
						row.classList.add('is-removing');
						setTimeout(function () {
							var list = row.parentNode;
							var focusNext = row.nextElementSibling || row.previousElementSibling;
							list.removeChild(row);
							var left = $$('.mwpm-item', list).length;
							$$('[data-stat="count"]').forEach(function (el) { el.textContent = left; });
							if (!left) {
								list.hidden = true;
								$('#mwpm-empty').hidden = false;
								$('#mwpm-empty .mwpm-btn').focus();
							} else if (focusNext) {
								$('.mwpm-btn', focusNext).focus();
							}
						}, 200);
						toast('Backup deleted.');
					})
					.catch(function (err) {
						btn.disabled = false;
						toast(err.message || String(err), true);
					});
			});
		});
	});

	/* ------------------------------------------------------------------ */

	function formatSize(bytes) {
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = 0;
		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}
		return bytes.toFixed(i ? 1 : 0) + ' ' + units[i];
	}

	function formatDuration(seconds) {
		if (seconds < 60) {
			return seconds + ' s';
		}
		var m = Math.round(seconds / 60);
		return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
	}
})();
