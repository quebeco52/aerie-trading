import { Controller } from '@hotwired/stimulus';

/** A flash message: dismissable, since the stack sits over the stock page's price header. */
export default class extends Controller {
    static values = {
        /** Success notices retire themselves; warnings and errors wait to be read and dismissed. */
        autoDismiss: { type: Number, default: 0 }
    };

    connect() {
        if (this.autoDismissValue > 0) {
            this.timer = setTimeout(() => this.dismiss(), this.autoDismissValue);
        }
    }

    disconnect() {
        if (this.timer) clearTimeout(this.timer);
    }

    dismiss() {
        if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
        }
        this.element.remove();
    }
}
