import { Controller } from '@hotwired/stimulus';

/**
 * The first-week checklist on the portfolio page. Whether each step is done comes from the server; whether the
 * card is wanted at all is the player's choice, kept per browser.
 */
const DISMISSED_KEY = 'aerie.checklist.dismissed';

function dismissed() {
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

export default class extends Controller {
    connect() {
        this.element.hidden = dismissed();
    }

    dismiss() {
        try {
            window.localStorage.setItem(DISMISSED_KEY, '1');
        } catch {
            // Storage blocked: the card still closes for this page.
        }
        this.element.hidden = true;
    }
}
