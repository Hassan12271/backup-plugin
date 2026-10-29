/* My WP Migration – admin screen. */
(function () {
	'use strict';

	var cfg = window.MWPM || {};
	var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
	var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

	var busy = false;
	var MAX_RETRIES = 6;

	/* ------------------------------------------------------------------
	 * Tabs
	 * ---------------------------------------------------------------- */

	function showTab(name) {
		if (!$('[data-panel="' + name + '"]')) {
			name = 'export';
		}
		$$('.mwpm-tabs .nav-tab').forEach(function (tab) {
			var active = tab.getAttribute('data-tab') === name;
			tab.classList.toggle('nav-tab-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});
		$$('.mwpm-panel').forEach(function (panel) {
			panel.hidden = panel.getAttribute('data-panel') !== name;
		});
	}

	$$('[data-tab], [data-tab-link]').forEach(function (link) {
		link.addEventListener('click', function (e) {
			e.preventDefault();
			var name = link.getAttribute('data-tab') || link.getAttribute('data-tab-link');
			showTab(name);
			if (history.replaceState) {
				history.replaceState(null, '', '#' + name);
			}
		});
	});
	showTab((location.hash || '#export').slice(1));

	/* ------------------------------------------------------------------
	 * Modal
	 * ---------------------------------------------------------------- */

	var modal = $('#mwpm-modal');

	function openModal(title) {
		modal.hidden = false;
		modal.className = 'mwpm-modal';
		$('#mwpm-modal-title').textContent = title;
		setProgress(0, 'Starting…');
		setButtons([]);
	}

	function setProgress(percent, status) {
		percent = Math.max(0, Math.min(100, percent));
		$('.mwpm-bar span', modal).style.width = percent + '%';
		$('.mwpm-bar', modal).setAttribute('aria-valuenow', Math.round(percent));
		$('.mwpm-percent', modal).textContent = percent.toFixed(percent < 100 ? 1 : 0) + '%';
		if (status !== undefined) {
			$('.mwpm-status', modal).textContent = status;
		}
	}

	function setState(state, title, status) {
		modal.className = 'mwpm-modal is-' + state;
		$('.mwpm-icon', modal).className = 'mwpm-icon dashicons ' + (state === 'error' ? 'dashicons-warning' : 'dashicons-yes-alt');
		$('#mwpm-modal-title').textContent = title;
		$('.mwpm-status', modal).textContent = status || '';
	}

	/**
	 * @param {Array} buttons [{label, primary, href, onClick}]
	 */
	function setButtons(buttons) {
		var box = $('.mwpm-buttons', modal);
		box.innerHTML = '';
		buttons.forEach(function (b) {
			var el = document.createElement(b.href ? 'a' : 'button');
			el.className = 'button' + (b.primary ? ' button-primary' : '');
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
		});
	}

	function closeModal(reload) {
		modal.hidden = true;
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
	function postRetry(action, data, blob, onRetry) {
		var attempt = 0;
		function run() {
			return post(action, data, blob).catch(function (err) {
				if (err.server || attempt >= MAX_RETRIES) {
					throw err;
				}
				attempt++;
				if (onRetry) {
					onRetry(attempt, err);
				}
				return wait(Math.min(2000 * attempt, 10000)).then(run);
			});
		}
		return run();
	}

	/* ------------------------------------------------------------------
	 * Job runner
	 * ---------------------------------------------------------------- */

	function runSteps(job, onDone) {
		var status = $('.mwpm-status', modal);
		function next() {
			return postRetry('mwpm_step', job, null, function (attempt) {
				status.textContent = 'Connection problem, retrying (' + attempt + '/' + MAX_RETRIES + ')…';
			}).then(function (res) {
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
		busy = false;
		setState('error', 'Something went wrong', err.message || String(err));
		var buttons = [{ label: 'Close', onClick: function () { closeModal(true); } }];
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
			runSteps(job, onDone).catch(function (err) { failed(err, resume(job, onDone)); });
		};
	}

	/* ------------------------------------------------------------------
	 * Export
	 * ---------------------------------------------------------------- */

	function exportDone(res) {
		busy = false;
		setProgress(100);
		setState('done', 'Export complete', res.file + ' (' + res.size + ')');
		setButtons([
			{ label: 'Download backup', primary: true, href: res.download_url },
			{ label: 'Close', onClick: function () { closeModal(true); } }
		]);
	}

	function startExport() {
		if (busy) {
			return;
		}
		var data = { nonce: cfg.nonce };
		$$('.mwpm-options input[type="checkbox"]').forEach(function (box) {
			data[box.name] = box.checked ? '1' : '0';
		});

		busy = true;
		openModal('Exporting site');
		post('mwpm_export_start', data)
			.then(function (job) {
				return runSteps(job, exportDone).catch(function (err) { failed(err, resume(job, exportDone)); });
			})
			.catch(function (err) { failed(err); });
	}

	$('#mwpm-export').addEventListener('click', startExport);
	$('#mwpm-backup-now').addEventListener('click', startExport);

	/* ------------------------------------------------------------------
	 * Import
	 * ---------------------------------------------------------------- */

	function importDone(res) {
		busy = false;
		setProgress(100);
		var text = 'The site was imported successfully.';
		if (res.skipped) {
			text += ' ' + res.skipped + ' file(s) could not be written (check folder permissions).';
		}
		if (res.relogin) {
			text += ' The database was replaced, so please log in again with a username and password of the imported site. Then visit Settings → Permalinks and click Save.';
		}
		setState('done', 'Import complete', text);
		setButtons(res.relogin
			? [{ label: 'Log in', primary: true, href: res.login_url }]
			: [{ label: 'Close', primary: true, onClick: function () { closeModal(true); } }]);
	}

	function confirmImport(name) {
		return window.confirm(
			'Import "' + name + '"?\n\n' +
			'This will REPLACE the database, media, themes and plugins of this site. This cannot be undone.'
		);
	}

	function upload(file, job) {
		var size = file.size;
		var chunk = Math.max(65536, parseInt(cfg.chunkSize, 10) || 1048576);
		var offset = 0;
		var started = Date.now();
		var status = $('.mwpm-status', modal);

		function next() {
			if (offset >= size) {
				return Promise.resolve();
			}
			var blob = file.slice(offset, Math.min(offset + chunk, size));
			var data = { job: job.job, token: job.token, offset: offset };
			return postRetry('mwpm_upload', data, blob, function (attempt) {
				status.textContent = 'Connection problem, retrying (' + attempt + '/' + MAX_RETRIES + ')…';
			}).then(function (res) {
				offset = res.offset;
				var seconds = (Date.now() - started) / 1000;
				var speed = seconds > 0 ? offset / seconds : 0;
				setProgress(offset / size * 100, 'Uploading: ' + formatSize(offset) + ' of ' + formatSize(size) + (speed ? ' · ' + formatSize(speed) + '/s' : ''));
				if (res.complete) {
					offset = size;
				}
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
			window.alert('Please choose a ' + ext + ' backup file created by My WP Migration.');
			return;
		}
		if (!confirmImport(file.name)) {
			return;
		}

		busy = true;
		openModal('Uploading backup');
		post('mwpm_import_start', { nonce: cfg.nonce, mode: 'upload', name: file.name, size: file.size })
			.then(function (job) {
				var stepJob = { job: job.job, token: job.token };
				var retryUpload = function () {
					busy = true;
					modal.className = 'mwpm-modal';
					setButtons([]);
					$('#mwpm-modal-title').textContent = 'Uploading backup';
					go();
				};
				function go() {
					upload(file, job)
						.then(function () {
							$('#mwpm-modal-title').textContent = 'Importing site';
							setProgress(0, 'Preparing import…');
							return runSteps(stepJob, importDone).catch(function (err) { failed(err, resume(stepJob, importDone)); });
						}, function (err) { failed(err, retryUpload); });
				}
				go();
			})
			.catch(function (err) { failed(err); });
	}

	var drop = $('#mwpm-drop');
	var input = $('#mwpm-file');

	input.addEventListener('change', function () {
		startUpload(input.files[0]);
		input.value = '';
	});
	['dragenter', 'dragover'].forEach(function (type) {
		drop.addEventListener(type, function (e) {
			e.preventDefault();
			drop.classList.add('is-over');
		});
	});
	['dragleave', 'drop'].forEach(function (type) {
		drop.addEventListener(type, function (e) {
			e.preventDefault();
			drop.classList.remove('is-over');
		});
	});
	drop.addEventListener('drop', function (e) {
		if (e.dataTransfer && e.dataTransfer.files.length) {
			startUpload(e.dataTransfer.files[0]);
		}
	});

	/* ------------------------------------------------------------------
	 * Backups list
	 * ---------------------------------------------------------------- */

	$$('.mwpm-restore').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var file = btn.closest('tr').getAttribute('data-file');
			if (busy || !confirmImport(file)) {
				return;
			}
			busy = true;
			openModal('Restoring backup');
			post('mwpm_import_start', { nonce: cfg.nonce, mode: 'backup', file: file })
				.then(function (job) {
					return runSteps(job, importDone).catch(function (err) { failed(err, resume(job, importDone)); });
				})
				.catch(function (err) { failed(err); });
		});
	});

	$$('.mwpm-delete').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var row = btn.closest('tr');
			var file = row.getAttribute('data-file');
			if (!window.confirm('Delete backup "' + file + '"? This cannot be undone.')) {
				return;
			}
			btn.disabled = true;
			post('mwpm_delete', { nonce: cfg.nonce, file: file })
				.then(function () {
					row.parentNode.removeChild(row);
					var count = $('.mwpm-count');
					if (count) {
						count.textContent = Math.max(0, parseInt(count.textContent, 10) - 1);
					}
				})
				.catch(function (err) {
					btn.disabled = false;
					window.alert(err.message || String(err));
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
})();
