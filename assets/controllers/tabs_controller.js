import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = {
        defaultTab: String,
        syncHash: { type: Boolean, default: true }
    };

    connect() {
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

    select(event) {
        const tabId = event.currentTarget.dataset.tab;
        if (tabId) {
            this.activate(tabId);
            if (this.syncHashValue && history.replaceState) {
                history.replaceState(null, null, `#${tabId}`);
            }
        }
    }

    hasTabId(tabId) {
        return this.tabTargets.some(btn => btn.dataset.tab === tabId);
    }

    activate(tabId) {
        this.tabTargets.forEach(btn => {
            const isActive = btn.dataset.tab === tabId;
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
            if (matches) {
                panel.classList.remove('hidden');
            } else {
                panel.classList.add('hidden');
            }
        });

        // Trigger chart resizes on next animation frame so newly visible canvas elements compute proper bounding rects
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
            this.dispatch('changed', { detail: { tabId } });
        }, 50);
    }
}
