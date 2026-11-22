(function () {
	'use strict';

	const states = new WeakMap();

	function isPlaceholder(option) {
		return !option.value || /^-+\s*select\s*-+$/i.test(option.textContent.trim());
	}

	function getLabel(select) {
		const labels = select.labels ? Array.from(select.labels) : [];
		const text = labels.map(label => label.textContent.trim()).filter(Boolean).join(' ');
		return text || select.getAttribute('aria-label') || select.name || select.id || 'Choose an option';
	}

	function syncState(select, state) {
		const selectedOption = select.selectedOptions.length ? select.selectedOptions[0] : null;
		let firstEnabled = null;
		let selectedEnabled = null;

		state.buttons.forEach((button, index) => {
			const selected = state.options[index] === selectedOption;
			button.setAttribute('aria-pressed', String(selected));
			if (!button.disabled && !firstEnabled) firstEnabled = button;
			if (selected && !button.disabled) selectedEnabled = button;
		});

		const tabStop = selectedEnabled || firstEnabled;
		state.buttons.forEach(button => {
			button.tabIndex = button === tabStop ? 0 : -1;
		});

		let prompt = state.group.querySelector('.choice-badge-empty');
		if (!state.buttons.length || isPlaceholder(selectedOption || { value: '', textContent: '' })) {
			if (!prompt) {
				prompt = document.createElement('span');
				prompt.className = 'choice-badge-empty';
				state.group.appendChild(prompt);
			}
			prompt.textContent = state.buttons.length ? 'Choose an option' : 'No options available';
		} else if (prompt) {
			prompt.remove();
		}
	}

	function render(select, state) {
		state.group.replaceChildren();
		state.buttons = [];
		state.options = [];

		Array.from(select.options).filter(option => !isPlaceholder(option)).forEach(option => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'choice-badge';
			button.textContent = option.textContent.trim();
			button.disabled = select.disabled || option.disabled || Boolean(option.parentElement && option.parentElement.disabled);
			button.tabIndex = -1;

			button.addEventListener('click', () => {
				if (button.disabled) return;
				const valueChanged = select.value !== option.value;
				select.value = option.value;
				state.group.classList.remove('is-invalid');
				select.removeAttribute('aria-invalid');
				syncState(select, state);
				if (valueChanged) select.dispatchEvent(new Event('change', { bubbles: true }));
			});

			button.addEventListener('keydown', event => {
				const enabled = state.buttons.filter(candidate => !candidate.disabled);
				const currentIndex = enabled.indexOf(button);
				let nextIndex = currentIndex;
				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % enabled.length;
				else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + enabled.length) % enabled.length;
				else if (event.key === 'Home') nextIndex = 0;
				else if (event.key === 'End') nextIndex = enabled.length - 1;
				else return;
				event.preventDefault();
				const next = enabled[nextIndex];
				if (next) {
					next.focus();
					next.click();
				}
			});

			state.group.appendChild(button);
			state.buttons.push(button);
			state.options.push(option);
		});

		syncState(select, state);
	}

	function initialize(select) {
		if (states.has(select)) return;

		const group = document.createElement('div');
		group.className = 'choice-badge-group';
		group.setAttribute('role', 'group');
		group.setAttribute('aria-label', getLabel(select));

		const state = { group, buttons: [], options: [] };
		states.set(select, state);
		select.classList.add('choice-badge-select-native');
		select.setAttribute('aria-hidden', 'true');
		select.tabIndex = -1;
		select.insertAdjacentElement('afterend', group);

		select.addEventListener('change', () => {
			group.classList.remove('is-invalid');
			select.removeAttribute('aria-invalid');
			syncState(select, state);
		});
		select.addEventListener('invalid', () => {
			group.classList.add('is-invalid');
			select.setAttribute('aria-invalid', 'true');
			const focusTarget = state.buttons.find(button => !button.disabled);
			if (focusTarget) focusTarget.focus();
			else group.focus();
		});
		if (select.form) {
			select.form.addEventListener('reset', () => {
				window.setTimeout(() => syncState(select, state), 0);
			});
		}

		render(select, state);
	}

	function initializeWithin(root) {
		if (root.matches && root.matches('select.choice-badge-select')) initialize(root);
		if (root.querySelectorAll) root.querySelectorAll('select.choice-badge-select').forEach(initialize);
	}

	function findSelect(node) {
		if (node.nodeType !== Node.ELEMENT_NODE) return null;
		if (node.matches('select.choice-badge-select')) return node;
		if (node instanceof HTMLOptionElement || node instanceof HTMLOptGroupElement) {
			return node.closest('select.choice-badge-select');
		}
		return null;
	}

	function start() {
		initializeWithin(document);
		const observer = new MutationObserver(mutations => {
			const changed = new Set();
			mutations.forEach(mutation => {
				const select = findSelect(mutation.target);
				if (select && states.has(select)) changed.add(select);
				mutation.addedNodes.forEach(node => {
					if (node.nodeType === Node.ELEMENT_NODE) initializeWithin(node);
				});
			});
			changed.forEach(select => render(select, states.get(select)));
		});
		observer.observe(document.body, {
			attributes: true,
			attributeFilter: ['disabled', 'selected'],
			childList: true,
			subtree: true
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
})();
