import { Controller } from '@hotwired/stimulus';

/**
 * Ticker autocomplete. The input is a combobox and the dropdown its listbox; arrow-key movement
 * goes out through `aria-activedescendant`, not just a background colour.
 */
export default class extends Controller {
    static targets = ['input', 'dropdown'];

    connect() {
        this.debounceTimer = null;
        this.currentFocus = -1;
        this.requestId = 0;
        this.outsideClickHandler = (e) => {
            if (!this.element.contains(e.target)) {
                this.hide();
            }
        };
        document.addEventListener('click', this.outsideClickHandler);
    }

    disconnect() {
        if (this.debounceTimer) clearTimeout(this.debounceTimer);
        document.removeEventListener('click', this.outsideClickHandler);
    }

    onInput() {
        if (this.debounceTimer) clearTimeout(this.debounceTimer);
        const query = this.inputTarget.value.trim();

        if (query.length < 1) {
            this.hide();
            return;
        }

        this.debounceTimer = setTimeout(() => {
            this.fetchResults(query);
        }, 250);
    }

    async fetchResults(query) {
        // Responses can land out of order; only the newest request may paint.
        const requestId = ++this.requestId;
        try {
            const response = await fetch(`/search/autocomplete?q=${encodeURIComponent(query)}`);
            if (!response.ok || requestId !== this.requestId) return;
            const data = await response.json();
            if (requestId !== this.requestId) return;

            this.dropdownTarget.replaceChildren();
            this.currentFocus = -1;
            this.inputTarget.removeAttribute('aria-activedescendant');

            if (Array.isArray(data) && data.length > 0) {
                data.forEach((item, index) => {
                    this.dropdownTarget.appendChild(this.buildOption(item, index));
                });
            } else {
                const empty = document.createElement('div');
                empty.className = 'px-4 py-2 text-sm text-on-surface-variant';
                empty.textContent = 'No results found';
                this.dropdownTarget.appendChild(empty);
            }
            this.show();
        } catch (e) {
            console.error('Failed to fetch search results', e);
        }
    }

    /** Builds one result row. Company names are set as text, never interpolated into markup. */
    buildOption(item, index) {
        const a = document.createElement('a');
        a.href = `/stock/${encodeURIComponent(item.ticker)}`;
        a.id = `search-option-${index}`;
        a.setAttribute('role', 'option');
        a.setAttribute('aria-selected', 'false');
        a.className = 'block px-4 py-2 text-sm text-on-surface hover:bg-surface-bright transition-colors cursor-pointer';

        const ticker = document.createElement('span');
        ticker.className = 'font-bold text-primary';
        ticker.textContent = item.ticker;

        const name = document.createElement('span');
        name.className = 'text-on-surface-variant text-xs ml-2';
        name.textContent = item.name;

        a.append(ticker, name);
        return a;
    }

    onKeyDown(e) {
        const items = this.dropdownTarget.querySelectorAll('a');
        if (!items || items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.currentFocus++;
            this.updateActive(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.currentFocus--;
            this.updateActive(items);
        } else if (e.key === 'Enter') {
            if (this.currentFocus > -1 && items[this.currentFocus]) {
                e.preventDefault();
                items[this.currentFocus].click();
            }
        } else if (e.key === 'Escape') {
            this.hide();
        }
    }

    updateActive(items) {
        if (!items || items.length === 0) return;
        items.forEach(item => {
            item.classList.remove('bg-surface-bright');
            item.setAttribute('aria-selected', 'false');
        });

        if (this.currentFocus >= items.length) this.currentFocus = 0;
        if (this.currentFocus < 0) this.currentFocus = items.length - 1;

        const active = items[this.currentFocus];
        active.classList.add('bg-surface-bright');
        active.setAttribute('aria-selected', 'true');
        this.inputTarget.setAttribute('aria-activedescendant', active.id);
        active.scrollIntoView({ block: 'nearest' });
    }

    show() {
        this.dropdownTarget.classList.remove('hidden');
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    hide() {
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add('hidden');
        }
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.currentFocus = -1;
    }
}
