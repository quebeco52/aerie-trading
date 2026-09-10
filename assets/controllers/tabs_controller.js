import { Controller } from '@hotwired/stimulus';

/**
 * Tabbed panels. Roles, ids and `aria-controls` pairs are wired here rather than in markup —
 * a dozen of them maintained by hand across two templates drift. Roving tabindex, so the strip
 * is one tab stop with arrow keys moving inside it.
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = {
        defaultTab: String,
        syncHash: { type: Boolean, default: true }
    };

    connect() {
        this.applyAriaWiring();

        this.keydownHandler = (event) => this.onKeyDown(event);
        this.tabTargets.forEach(tab => tab.addEventListener('keydown', this.keydownHandler));

        const hash = window.location.hash.replace('#', '');
        if (hash && this.hasTabId(hash)) {
            this.activate(hash);
        } else if (this.defaultTabValue && this.hasTabId(this.defaultTabValue)) {
            this.activate(this.defaultTabValue);
        } else if (this.tabTargets.length > 0) {
            const firstTab = this.tabTargets[0].dataset.tab;
            if (firstTab) this.activate(firstTab);
        }
    }

    disconnect() {
        if (this.keydownHandler) {
            this.tabTargets.forEach(tab => tab.removeEventListener('keydown', this.keydownHandler));
        }
    }

    /** Names each tab and panel and points them at each other. */
    applyAriaWiring() {
        const tablist = this.tabTargets[0]?.parentElement;
        if (tablist) tablist.setAttribute('role', 'tablist');

        this.tabTargets.forEach(tab => {
            const tabId = tab.dataset.tab;
            const panel = this.panelFor(tabId);
            tab.setAttribute('role', 'tab');
            tab.id = tab.id || `tab-${tabId}`;
            if (panel) {
                panel.setAttribute('role', 'tabpanel');
                panel.setAttribute('aria-labelledby', tab.id);
                // Reachable, so the panel body can be read straight after choosing its tab.
                panel.setAttribute('tabindex', '0');
                if (panel.id) tab.setAttribute('aria-controls', panel.id);
            }
        });
    }

    panelFor(tabId) {
        return this.panelTargets.find(panel =>
            panel.dataset.tabPanel === tabId || panel.id.endsWith(`-${tabId}`)
        );
    }

    select(event) {
        const tabId = event.currentTarget.dataset.tab;
        if (tabId) {
            this.activate(tabId);
            if (this.syncHashValue && history.replaceState) {
                history.replaceState(null, '', `#${tabId}`);
            }
        }
    }

    onKeyDown(event) {
        const keys = { ArrowRight: 1, ArrowLeft: -1 };
        const tabs = this.tabTargets;
        const index = tabs.indexOf(event.currentTarget);
        if (index === -1) return;

        let next = null;
        if (event.key in keys) {
            next = tabs[(index + keys[event.key] + tabs.length) % tabs.length];
        } else if (event.key === 'Home') {
            next = tabs[0];
        } else if (event.key === 'End') {
            next = tabs[tabs.length - 1];
        }
        if (!next) return;

        event.preventDefault();
        this.activate(next.dataset.tab);
        next.focus();
    }

    hasTabId(tabId) {
        return this.tabTargets.some(btn => btn.dataset.tab === tabId);
    }

    activate(tabId) {
        this.tabTargets.forEach(btn => {
            const isActive = btn.dataset.tab === tabId;
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            btn.setAttribute('tabindex', isActive ? '0' : '-1');
            if (isActive) {
                btn.classList.remove('text-on-surface-variant', 'border-transparent');
                btn.classList.add('text-primary', 'border-primary');
            } else {
                btn.classList.remove('text-primary', 'border-primary');
                btn.classList.add('text-on-surface-variant', 'border-transparent');
            }
        });

        this.panelTargets.forEach(panel => {
            const matches = panel.dataset.tabPanel === tabId || panel.id.endsWith(`-${tabId}`);
            panel.classList.toggle('hidden', !matches);
        });

        // Trigger chart resizes on next animation frame so newly visible canvas elements compute proper bounding rects
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
            this.dispatch('changed', { detail: { tabId } });
        }, 50);
    }
}
