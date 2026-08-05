(function () {
	'use strict';

	function parseIds(value) {
		return Array.from(new Set(String(value || '').split(/[\s,]+/).filter(Boolean)));
	}

	if (typeof module === 'object' && module.exports) {
		module.exports = { parseIds: parseIds };
	}

	if (typeof document === 'undefined') {
		return;
	}

	function initialize(source) {
		var dataElement = source.nextElementSibling;
		var description = dataElement && dataElement.nextElementSibling;
		var metadata = [];

		try {
			metadata = JSON.parse(dataElement && dataElement.textContent ? dataElement.textContent : '[]');
		} catch (error) {
			metadata = [];
		}

		var details = new Map(metadata.map(function (item) { return [String(item.id), item]; }));
		var ids = parseIds(source.value);
		var editor = document.createElement('div');
		var tools = document.createElement('div');
		var count = document.createElement('span');
		var actions = document.createElement('span');
		var copy = document.createElement('button');
		var clear = document.createElement('button');
		var tags = document.createElement('div');
		var input = document.createElement('input');
		var inputId = source.id + '_tag_input';
		var tagsId = source.id + '_tags';

		editor.className = 'rfs-property-tag-editor';
		tools.className = 'rfs-property-tag-tools';
		count.className = 'rfs-property-tag-count';
		count.setAttribute('aria-live', 'polite');
		actions.className = 'rfs-property-tag-actions';
		copy.type = 'button';
		copy.className = 'rfs-property-tags-action rfs-property-tags-copy';
		copy.textContent = 'Copy all';
		copy.setAttribute('aria-live', 'polite');
		clear.type = 'button';
		clear.className = 'rfs-property-tags-action rfs-property-tags-clear';
		clear.textContent = 'Clear all';
		clear.setAttribute('aria-controls', tagsId);
		actions.append(copy, clear);
		tools.append(count, actions);
		tags.className = 'rfs-property-tags';
		tags.id = tagsId;
		tags.setAttribute('role', 'list');
		tags.setAttribute('aria-label', 'Configured identifiers');
		input.type = 'text';
		input.id = inputId;
		input.className = 'rfs-property-tag-input';
		input.placeholder = 'Paste or type IDs, then press Enter';
		input.autocomplete = 'off';
		input.setAttribute('aria-describedby', source.dataset.descriptionId || '');
		if (description && description.classList.contains('description')) {
			editor.append(description);
		}
		editor.append(input, tools, tags);

		Array.from(document.getElementsByTagName('label')).forEach(function (label) {
			if (label.htmlFor === source.id) {
				label.htmlFor = inputId;
			}
		});

		source.hidden = true;
		source.insertAdjacentElement('afterend', editor);

		function sync() {
			source.value = ids.join(', ');
		}

		function showCopyStatus(text) {
			copy.textContent = text;
			window.setTimeout(function () { copy.textContent = 'Copy all'; }, 1500);
		}

		function fallbackCopy(value) {
			var active = document.activeElement;
			var scrollX = window.scrollX;
			var scrollY = window.scrollY;
			var temporary = document.createElement('textarea');
			var copied = false;

			temporary.value = value;
			temporary.style.position = 'fixed';
			temporary.style.opacity = '0';
			document.body.append(temporary);
			temporary.select();
			try {
				copied = document.execCommand('copy');
			} catch (error) {
				copied = false;
			} finally {
				temporary.remove();
				if (active) {
					active.focus({ preventScroll: true });
				}
				window.scrollTo(scrollX, scrollY);
			}
			showCopyStatus(copied ? 'Copied' : 'Copy failed');
		}

		function copyAll() {
			var value = ids.join(', ');
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(value).then(function () { showCopyStatus('Copied'); }, function () { fallbackCopy(value); });
			} else {
				fallbackCopy(value);
			}
		}

		function render() {
			tags.replaceChildren();
			tools.hidden = !ids.length;
			count.textContent = ids.length + (ids.length === 1 ? ' property' : ' properties');

			ids.forEach(function (id) {
				var item = details.get(id) || { name: '', status: 'sync-gray', status_label: 'Not synced' };
				var name = item.name && item.name.toLowerCase() !== id.toLowerCase() ? item.name : '';
				var tag = document.createElement('span');
				var remove = document.createElement('button');

				tag.className = 'rfs-property-tag ' + item.status;
				tag.title = (name ? name + ' — ' : '') + id + ' · ' + item.status_label;
				tag.setAttribute('role', 'listitem');

				if (name) {
					var nameElement = document.createElement('span');
					nameElement.className = 'rfs-property-tag-name';
					nameElement.textContent = name;
					tag.append(nameElement);
				}

				var idElement = document.createElement('span');
				idElement.className = 'rfs-property-tag-id';
				idElement.textContent = id;
				tag.append(idElement);

				var statusElement = document.createElement('span');
				statusElement.className = 'screen-reader-text';
				statusElement.textContent = item.status_label;
				tag.append(statusElement);

				remove.type = 'button';
				remove.className = 'rfs-property-tag-remove';
				remove.dataset.id = id;
				remove.setAttribute('aria-label', 'Remove ' + (name ? name + ' (' + id + ')' : id));
				remove.textContent = '×';
				tag.append(remove);
				tags.append(tag);
			});
		}

		function add(value) {
			parseIds(value).forEach(function (id) {
				if (!ids.includes(id)) {
					ids.push(id);
				}
			});
			input.value = '';
			sync();
			render();
			tags.scrollTop = tags.scrollHeight;
		}

		input.addEventListener('keydown', function (event) {
			if ((event.key === 'Enter' || event.key === ',' || event.key === 'Tab') && input.value.trim()) {
				event.preventDefault();
				add(input.value);
			} else if (event.key === 'Backspace' && !input.value && ids.length) {
				ids.pop();
				sync();
				render();
			}
		});

		input.addEventListener('input', function () {
			if (/[,\n]/.test(input.value)) {
				add(input.value);
			}
		});

		input.addEventListener('paste', function (event) {
			var value = event.clipboardData.getData('text');
			if (parseIds(value).length > 1) {
				event.preventDefault();
				add(value);
			}
		});

		input.addEventListener('blur', function () {
			if (input.value.trim()) {
				add(input.value);
			}
		});

		editor.addEventListener('click', function (event) {
			if (event.target.closest('.rfs-property-tags-copy')) {
				copyAll();
				return;
			}

			if (event.target.closest('.rfs-property-tags-clear')) {
				var pageScroll = window.scrollY;
				ids = [];
				sync();
				input.focus({ preventScroll: true });
				render();
				window.scrollTo(0, pageScroll);
				return;
			}

			var remove = event.target.closest('.rfs-property-tag-remove');
			if (remove) {
				var index = ids.indexOf(remove.dataset.id);
				if (index === -1) {
					return;
				}
				var scrollTop = tags.scrollTop;
				ids.splice(index, 1);
				sync();
				render();
				tags.scrollTop = scrollTop;

				if (event.detail === 0) {
					var buttons = tags.querySelectorAll('.rfs-property-tag-remove');
					(buttons[Math.min(index, buttons.length - 1)] || input).focus({ preventScroll: true });
				}
			} else if (event.target === editor || event.target === tags) {
				input.focus();
			}
		});

		sync();
		render();
	}

	document.querySelectorAll('.rfs-property-tag-source').forEach(initialize);
}());
