import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'dropdown'];

    connect() {
        this.debounceTimer = null;
        this.currentFocus = -1;
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
        try {
            const response = await fetch(`/search/autocomplete?q=${encodeURIComponent(query)}`);
            if (!response.ok) return;
            const data = await response.json();

            this.dropdownTarget.innerHTML = '';
            this.currentFocus = -1;

            if (Array.isArray(data) && data.length > 0) {
                data.forEach(item => {
                    const a = document.createElement('a');
                    a.href = `/stock/${encodeURIComponent(item.ticker)}`;
                    a.className = 'block px-4 py-2 text-sm text-on-surface hover:bg-surface-bright transition-colors cursor-pointer';
                    a.innerHTML = `<span class="font-bold text-primary">${item.ticker}</span> <span class="text-on-surface-variant text-xs ml-2">${item.name}</span>`;
                    this.dropdownTarget.appendChild(a);
                });
            } else {
                this.dropdownTarget.innerHTML = '<div class="px-4 py-2 text-sm text-on-surface-variant">No results found</div>';
            }
            this.dropdownTarget.classList.remove('hidden');
        } catch (e) {
            console.error('Failed to fetch search results', e);
        }
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
        items.forEach(item => item.classList.remove('bg-surface-bright'));

        if (this.currentFocus >= items.length) this.currentFocus = 0;
        if (this.currentFocus < 0) this.currentFocus = items.length - 1;

        items[this.currentFocus].classList.add('bg-surface-bright');
        items[this.currentFocus].scrollIntoView({ block: 'nearest' });
    }

    hide() {
        if (this.hasDropdownTarget) {
            this.dropdownTarget.classList.add('hidden');
        }
        this.currentFocus = -1;
    }
}
