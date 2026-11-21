(function () {
	'use strict';

	const selectStates = new WeakMap();

	function badgeClass(select, value) {
		const normalized = String(value || '').trim().toLowerCase();
		const known = ['immediate', 'high', 'normal', 'low', 'approved', 'pending', 'rejected', 'draft'];
		const key = select.name === 'urgency' ? normalized : (known.includes(normalized) ? normalized : 'default');
		return 'bo-option-badge bo-option-badge--' + key;
	}

	function optionIsDisabled(option) {
		return option.disabled || (option.parentElement && option.parentElement.disabled);
	}

	function isPlaceholderOption(option) {
		return Boolean(option && option.value === '' && /^-+\s*select\s*-+$/i.test(option.textContent.trim()));
	}

	function selectedOption(select) {
		return select.selectedOptions.length ? select.selectedOptions[0] : null;
	}

	function syncSelection(select, state) {
		const currentOption = selectedOption(select);
		let firstEnabledButton = null;
		let selectedEnabledButton = null;

		state.buttons.forEach(function (button, index) {
			const selected = state.options[index] === currentOption;
			button.setAttribute('aria-checked', String(selected));
			if (!button.disabled && !firstEnabledButton) {
				firstEnabledButton = button;
			}
			if (selected && !button.disabled) {
				selectedEnabledButton = button;
			}
		});

		const tabStop = selectedEnabledButton || firstEnabledButton;
		state.buttons.forEach(function (button) {
			button.tabIndex = button === tabStop ? 0 : -1;
		});
	}

	function syncPrompt(select, state) {
		const currentOption = selectedOption(select);
		let prompt = state.group.querySelector('.bo-select-prompt');

		if (!state.buttons.length || isPlaceholderOption(currentOption)) {
			if (!prompt) {
				prompt = document.createElement('span');
				prompt.className = 'bo-select-prompt';
				state.group.appendChild(prompt);
			}
			prompt.textContent = state.buttons.length ? 'Choose an option' : 'No options available yet';
		} else if (prompt) {
			prompt.remove();
		}
	}

	function renderOptions(select, state) {
		state.group.replaceChildren();
		state.buttons = [];
		state.options = [];

		Array.from(select.options).filter(function (option) {
			return !isPlaceholderOption(option);
		}).forEach(function (option) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'bo-option-badge bo-select-pill ' + badgeClass(select, option.value);
			button.textContent = option.textContent.trim();
			button.disabled = select.disabled || optionIsDisabled(option);
			button.setAttribute('role', 'radio');
			button.setAttribute('aria-checked', 'false');
			button.tabIndex = -1;

			button.addEventListener('click', function () {
				if (button.disabled) {
					return;
				}

				const valueChanged = select.value !== option.value;
				select.value = option.value;
				state.group.classList.remove('is-invalid');
				select.removeAttribute('aria-invalid');
				syncPrompt(select, state);
				syncSelection(select, state);

				if (valueChanged) {
					select.dispatchEvent(new Event('change', { bubbles: true }));
				}
			});

			button.addEventListener('keydown', function (event) {
				const enabledButtons = state.buttons.filter(function (candidate) {
					return !candidate.disabled;
				});
				const currentIndex = enabledButtons.indexOf(button);
				let nextIndex = currentIndex;

				if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
					nextIndex = (currentIndex + 1) % enabledButtons.length;
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
					nextIndex = (currentIndex - 1 + enabledButtons.length) % enabledButtons.length;
				} else if (event.key === 'Home') {
					nextIndex = 0;
				} else if (event.key === 'End') {
					nextIndex = enabledButtons.length - 1;
				} else {
					return;
				}

				event.preventDefault();
				const nextButton = enabledButtons[nextIndex];
				if (nextButton) {
					nextButton.focus();
					nextButton.click();
				}
			});

			state.group.appendChild(button);
			state.buttons.push(button);
			state.options.push(option);
		});

		syncPrompt(select, state);
		syncSelection(select, state);
	}

	function labelFor(select) {
		const labels = select.labels ? Array.from(select.labels) : [];
		const text = labels.map(function (label) {
			return label.textContent.trim();
		}).filter(Boolean).join(' ');

		const denomination = select.closest('.input-group')?.querySelector('.input-group-text')?.textContent.trim();
		const fallback = (select.name || select.id || 'Choose an option')
			.replace(/^bo-req-/, 'Request ')
			.replace(/^bo-pay-/, 'Payment ')
			.replace(/[_-]+/g, ' ');
		return text || select.getAttribute('aria-label') || (denomination ? denomination + ' denomination' : fallback);
	}

	function initializeSelect(select) {
		if (!select || !select.classList.contains('bo-select-badge') || selectStates.has(select)) {
			return;
		}

		const group = document.createElement('div');
		group.className = 'bo-select-pill-group' + (select.classList.contains('form-select-sm') ? ' bo-select-pill-group--sm' : '');
		group.setAttribute('role', 'radiogroup');
		group.setAttribute('aria-label', labelFor(select));
		group.tabIndex = -1;

		const state = { group: group, buttons: [], options: [] };
		selectStates.set(select, state);
		select.classList.add('bo-select-native');
		select.setAttribute('aria-hidden', 'true');
		select.tabIndex = -1;
		select.insertAdjacentElement('afterend', group);

		select.addEventListener('change', function () {
			group.classList.remove('is-invalid');
			select.removeAttribute('aria-invalid');
			syncPrompt(select, state);
			syncSelection(select, state);
		});

		select.addEventListener('invalid', function () {
			group.classList.add('is-invalid');
			select.setAttribute('aria-invalid', 'true');
			const focusTarget = state.buttons.find(function (button) {
				return !button.disabled;
			});
			if (focusTarget) {
				focusTarget.focus();
			} else {
				group.focus();
			}
		});

		if (select.form) {
			select.form.addEventListener('reset', function () {
				window.setTimeout(function () {
					syncPrompt(select, state);
					syncSelection(select, state);
				}, 0);
			});
		}

		renderOptions(select, state);
	}

	function initializeWithin(root) {
		if (root.matches && root.matches('select.bo-select-badge')) {
			initializeSelect(root);
		}
		if (root.querySelectorAll) {
			root.querySelectorAll('select.bo-select-badge').forEach(initializeSelect);
		}
	}

	function selectForMutation(target) {
		if (target.nodeType !== Node.ELEMENT_NODE) {
			return null;
		}
		if (target.matches('select.bo-select-badge')) {
			return target;
		}
		if (target instanceof HTMLOptionElement || target instanceof HTMLOptGroupElement) {
			return target.closest('select.bo-select-badge');
		}
		return null;
	}

	function start() {
		initializeWithin(document);

		const observer = new MutationObserver(function (mutations) {
			const changedSelects = new Set();
			mutations.forEach(function (mutation) {
				const select = selectForMutation(mutation.target);
				if (select && selectStates.has(select)) {
					changedSelects.add(select);
				}

				mutation.addedNodes.forEach(function (node) {
					if (node.nodeType === Node.ELEMENT_NODE) {
						initializeWithin(node);
					}
				});
			});
			changedSelects.forEach(function (select) {
				renderOptions(select, selectStates.get(select));
			});
		});
		observer.observe(document.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: ['disabled', 'selected']
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, { once: true });
	} else {
		start();
	}
})();
