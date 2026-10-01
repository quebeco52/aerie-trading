import { Controller } from '@hotwired/stimulus';

/**
 * Dismissal for a native <details> used as a popover menu. <details> handles the toggle and
 * keyboard activation itself, but never closes when attention moves elsewhere.
 */
export default class extends Controller {
    connect() {
        this.closeOnOutsideClick = (event) => {
            if (this.element.open && !this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        this.closeOnEscape = (event) => {
            if (event.key === 'Escape' && this.element.open) {
                this.element.open = false;
                this.element.querySelector('summary')?.focus();
            }
        };
        // Turbo snapshots the page on the way out; a menu left open would come back open on Back.
        this.closeBeforeCache = () => {
            this.element.open = false;
        };
        document.addEventListener('click', this.closeOnOutsideClick);
        document.addEventListener('keydown', this.closeOnEscape);
        document.addEventListener('turbo:before-cache', this.closeBeforeCache);
    }

    disconnect() {
        document.removeEventListener('click', this.closeOnOutsideClick);
        document.removeEventListener('keydown', this.closeOnEscape);
        document.removeEventListener('turbo:before-cache', this.closeBeforeCache);
    }
}
