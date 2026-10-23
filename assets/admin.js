(function () {
	'use strict';
	var l10n = window.swsL10n || { cancel: 'Cancel', copied: 'Copied' };
	var dlg = null;

	function build() {
		dlg = document.createElement('dialog');
		dlg.className = 'sws-dialog';
		dlg.innerHTML =
			'<h2 class="sws-d-title"></h2><p class="sws-d-text"></p><ul class="sws-d-list"></ul>' +
			'<div class="sws-d-actions"><button type="button" class="button sws-d-cancel"></button>' +
			'<button type="button" class="button button-primary sws-d-ok"></button></div>';
		document.body.appendChild(dlg);
		dlg.querySelector('.sws-d-cancel').textContent = l10n.cancel;
		dlg.querySelector('.sws-d-cancel').addEventListener('click', function () { dlg.close(); });
	}

	function confirmLink(a) {
		var title = a.getAttribute('data-sws-title') || '';
		var text = a.getAttribute('data-sws-text') || '';
		if (typeof HTMLDialogElement === 'undefined') {
			if (window.confirm(title + '\n\n' + text)) { window.location.href = a.href; }
			return;
		}
		if (!dlg) { build(); }
		dlg.querySelector('.sws-d-title').textContent = title;
		dlg.querySelector('.sws-d-text').textContent = text;
		var list = dlg.querySelector('.sws-d-list');
		list.textContent = '';
		(a.getAttribute('data-sws-points') || '').split('|').forEach(function (p) {
			if (!p) { return; }
			var li = document.createElement('li');
			li.textContent = p;
			list.appendChild(li);
		});
		var ok = dlg.querySelector('.sws-d-ok');
		ok.textContent = a.getAttribute('data-sws-ok') || 'OK';
		ok.classList.toggle('sws-d-danger', !!a.getAttribute('data-sws-danger'));
		ok.onclick = function () { window.location.href = a.href; };
		dlg.showModal();
	}

	function copy(text, el) {
		var done = function () {
			var old = el.textContent;
			el.textContent = l10n.copied;
			setTimeout(function () { el.textContent = old; }, 1500);
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
			return;
		}
		var t = document.createElement('textarea');
		t.value = text;
		document.body.appendChild(t);
		t.select();
		document.execCommand('copy');
		document.body.removeChild(t);
		done();
	}

	document.addEventListener('click', function (e) {
		var c = e.target.closest('[data-sws-confirm]');
		if (c) { e.preventDefault(); confirmLink(c); return; }
		var t = e.target.closest('[data-sws-copy-text]');
		if (t) { e.preventDefault(); copy(t.getAttribute('data-sws-copy-text'), t); return; }
		var s = e.target.closest('[data-sws-copy]');
		if (s) {
			e.preventDefault();
			var input = document.querySelector(s.getAttribute('data-sws-copy'));
			if (input) { copy(input.value, s); }
		}
	});
})();
