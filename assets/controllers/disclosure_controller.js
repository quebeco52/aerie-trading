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
        document.addEventListener('click', this.closeOnOutsideClick);
        document.addEventListener('keydown', this.closeOnEscape);
    }

    disconnect() {
        document.removeEventListener('click', this.closeOnOutsideClick);
        document.removeEventListener('keydown', this.closeOnEscape);
    }
}
