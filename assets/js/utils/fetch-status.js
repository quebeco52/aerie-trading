/** Loading, error and retry state for a panel that fills itself from an API call. */

const TONES = {
    loading: 'border-outline-variant/25 bg-surface-container-low text-on-surface-variant',
    error: 'border-tertiary/25 bg-tertiary/10 text-tertiary'
};
const ALL_TONES = Object.values(TONES).join(' ').split(' ');

function parts(elementId) {
    const root = document.getElementById(elementId);
    if (!root) return null;
    return {
        root,
        icon: root.querySelector('[data-status-icon]'),
        message: root.querySelector('[data-status-message]'),
        retry: root.querySelector('[data-status-retry]')
    };
}

function paint(elementId, { tone, icon, message, onRetry }) {
    const el = parts(elementId);
    if (!el) return;

    el.root.classList.remove('hidden', ...ALL_TONES);
    el.root.classList.add(...TONES[tone].split(' '));
    if (el.icon) el.icon.textContent = icon;
    if (el.message) el.message.textContent = message;

    if (el.retry) {
        el.retry.classList.toggle('hidden', !onRetry);
        el.retry.onclick = onRetry || null;
    }
}

export function showLoading(elementId, message = 'Loading…') {
    paint(elementId, { tone: 'loading', icon: 'hourglass_empty', message });
}

export function showError(elementId, message, onRetry) {
    paint(elementId, { tone: 'error', icon: 'error', message, onRetry });
}

export function hideStatus(elementId) {
    const el = parts(elementId);
    if (!el) return;
    el.root.classList.add('hidden');
    if (el.retry) el.retry.onclick = null;
}
