import { Controller } from '@hotwired/stimulus';
import { formatPercent, sentenceCase, signedClass } from '../js/utils/formatters.js';

/**
 * The headline strip under the navigation (templates/partials/_headline_strip.html.twig).
 *
 * The strip is `data-turbo-permanent`, so it survives page visits: the latest headline is fetched once per full
 * load, and after that each wire event the news desk marks as a headline (App\Service\Event\NewsDesk) replaces it.
 * A dismissed headline stays hidden until a newer one arrives; the choice is kept per browser.
 */
const DISMISSED_KEY = 'aerie.headline.dismissed';

function storedDismissal() {
    try {
        return window.localStorage.getItem(DISMISSED_KEY);
    } catch {
        return null;
    }
}

function storeDismissal(key) {
    try {
        window.localStorage.setItem(DISMISSED_KEY, key);
    } catch {
        // Storage blocked: the strip still closes for this page.
    }
}

export default class extends Controller {
    static targets = ['badge', 'source', 'text', 'change', 'time'];
    static values = { url: String };

    connect() {
        this.onFrame = (event) => {
            const events = event.detail?.events;
            if (!Array.isArray(events)) return;
            const latest = events.filter(evt => evt.headline === true && evt.presented).pop();
            if (latest) this.show(latest);
        };
        document.addEventListener('market:frame', this.onFrame);

        if (!this.loaded) {
            this.loaded = true;
            fetch(this.urlValue, { headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : null))
                .then(story => {
                    if (story && story.presented && !this.current) this.show(story);
                })
                .catch(() => {});
        }
    }

    disconnect() {
        document.removeEventListener('market:frame', this.onFrame);
    }

    dismiss() {
        if (this.current) storeDismissal(this.current);
        this.element.hidden = true;
    }

    show(story) {
        const card = story.presented;
        const key = `${card.recordedAt}|${story.ticker || ''}|${card.headline || card.badge}`;
        this.current = key;

        this.badgeTarget.className = `badge ${card.badgeClass || 'badge-neutral'}`;
        this.badgeTarget.textContent = sentenceCase(card.badge || 'News');

        this.sourceTarget.textContent = story.ticker || '';
        this.sourceTarget.hidden = !story.ticker;

        this.textTarget.textContent = card.headline || '';
        this.textTarget.href = story.ticker ? `/stock/${encodeURIComponent(story.ticker)}` : '/news';

        const change = card.changePercent !== undefined && card.changePercent !== null ? parseFloat(card.changePercent) : null;
        const hasChange = change !== null && change !== 0 && Number.isFinite(change);
        this.changeTarget.hidden = !hasChange;
        this.changeTarget.className = `font-mono tabular-nums ${hasChange ? signedClass(change) : ''}`;
        this.changeTarget.textContent = hasChange ? formatPercent(change, 2, true, true) : '';

        this.timeTarget.textContent = card.dateline || card.recordedAt || '';

        this.element.hidden = storedDismissal() === key;
    }
}
