import { Controller } from '@hotwired/stimulus';

/**
 * The navigation bell (templates/partials/_navbar.html.twig): the unread count, and a toast for each message pushed
 * to this account while a page is open (App\Service\Notification\PlayerNotifier, routed by bin/websocket-server.php).
 *
 * The socket closes while the tab is hidden, so the count is fetched again whenever the feed reconnects; anything
 * pushed meanwhile is already in the inbox.
 */
const TOAST_MS = 10000;

export default class extends Controller {
    static targets = ['count'];
    static values = { count: Number, url: String };

    connect() {
        this.render();

        this.onMessage = (event) => {
            this.countValue += 1;
            this.toast(event.detail || {});
        };
        this.onConnected = () => this.refresh();

        document.addEventListener('player:notification', this.onMessage);
        document.addEventListener('market:connected', this.onConnected);
    }

    disconnect() {
        document.removeEventListener('player:notification', this.onMessage);
        document.removeEventListener('market:connected', this.onConnected);
    }

    countValueChanged() {
        this.render();
    }

    refresh() {
        if (!this.urlValue) return;
        fetch(this.urlValue, { headers: { Accept: 'application/json' } })
            .then(response => (response.ok ? response.json() : null))
            .then(body => {
                if (body && Number.isInteger(body.count)) this.countValue = body.count;
            })
            .catch(() => {});
    }

    render() {
        if (!this.hasCountTarget) return;
        const count = this.countValue;
        this.countTarget.hidden = count <= 0;
        this.countTarget.textContent = count > 99 ? '99+' : String(count);
        this.element.setAttribute('aria-label', count > 0 ? `Notifications, ${count} unread` : 'Notifications');
    }

    toast(message) {
        const stack = document.getElementById('player-toasts');
        if (!stack || !message.title) return;

        const card = document.createElement('div');
        card.setAttribute('data-controller', 'flash');
        card.setAttribute('data-flash-auto-dismiss-value', String(TOAST_MS));
        card.setAttribute('role', 'status');
        card.className = 'p-3 rounded-lg border border-outline-variant/30 bg-surface-container-high shadow-lg shadow-black/30 flex items-start gap-3';

        const body = document.createElement('a');
        body.href = message.link || '/notifications';
        body.className = 'grow min-w-0 text-sm';

        const head = document.createElement('span');
        head.className = 'flex items-center gap-2 mb-1';
        const badge = document.createElement('span');
        badge.className = `badge ${message.tone || 'badge-neutral'}`;
        badge.textContent = message.label || 'Notice';
        head.appendChild(badge);
        if (message.dateline) {
            const time = document.createElement('span');
            time.className = 'text-xs font-mono tabular-nums text-on-surface-variant';
            time.textContent = message.dateline;
            head.appendChild(time);
        }
        body.appendChild(head);

        const title = document.createElement('span');
        title.className = 'block font-medium text-on-surface hover:text-primary transition-colors';
        title.textContent = message.title;
        body.appendChild(title);

        if (message.body) {
            const text = document.createElement('span');
            text.className = 'block text-xs text-on-surface-variant mt-0.5';
            text.textContent = message.body;
            body.appendChild(text);
        }

        const close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('data-action', 'flash#dismiss');
        close.setAttribute('aria-label', 'Dismiss');
        close.className = 'material-symbols-outlined text-base shrink-0 text-on-surface-variant hover:text-on-surface transition-colors cursor-pointer';
        close.textContent = 'close';

        card.appendChild(body);
        card.appendChild(close);
        stack.appendChild(card);
    }
}
