/**
 * Live corporate events for the instrument page.
 *
 * Each event arrives with `presented`, the card App\Service\Event\EventPresenter built for it, so a live
 * event renders exactly as it will on the next page load. The browser used to re-derive the card from the
 * type string, and that copy fell behind the types the engines actually publish.
 */

import { formatPercent, sentenceCase, signedClass } from '../utils/formatters.js';

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}

/**
 * Mirrors templates/partials/_event_card.html.twig. `source` ({ ticker, name }) names the company or fund on the
 * newswire; `headline` sets the story in the heavier weight a headline takes there.
 */
export function buildCard(card, { source = null, headline = false } = {}) {
    const root = el('article', 'py-3 space-y-1.5');

    const header = el('div', 'flex items-start justify-between gap-3');
    const left = el('div', 'flex items-center flex-wrap gap-x-2 gap-y-1 min-w-0');
    left.appendChild(el('span', `badge ${card.badgeClass || 'badge-neutral'}`, sentenceCase(card.badge || card.type || 'Event')));

    if (source && source.ticker) {
        const link = el('a', 'text-xs text-on-surface-variant hover:text-primary transition-colors');
        link.href = `/stock/${encodeURIComponent(source.ticker)}`;
        link.appendChild(el('span', 'font-mono font-semibold text-on-surface', source.ticker));
        if (source.name) link.appendChild(document.createTextNode(` ${source.name}`));
        left.appendChild(link);
    }

    if (card.isEarnings && card.eps) {
        left.appendChild(el('span', 'text-xs font-semibold font-mono text-on-surface', `EPS ${card.eps}`));
    }
    if (card.isEarnings && card.surpriseType !== 'met' && card.surpriseAmount) {
        left.appendChild(el('span', `text-xs font-mono ${card.surpriseType === 'beat' ? 'text-secondary' : 'text-tertiary'}`, card.surpriseText));
    } else if (card.isEarnings && card.surpriseType === 'met') {
        left.appendChild(el('span', 'text-xs text-on-surface-variant', 'In line'));
    }
    if (card.isEarnings && card.eva) {
        left.appendChild(el('span', `text-xs font-mono ${card.evaPositive ? 'text-secondary' : 'text-tertiary'}`, card.eva));
    }
    header.appendChild(left);

    const right = el('div', 'shrink-0 flex items-baseline gap-2.5 text-xs font-mono tabular-nums');
    const change = card.changePercent !== undefined && card.changePercent !== null ? parseFloat(card.changePercent) : null;
    if (change !== null && change !== 0 && Number.isFinite(change)) {
        right.appendChild(el('span', signedClass(change), formatPercent(change, 2, true, true)));
    }
    right.appendChild(el('time', 'text-on-surface-variant whitespace-nowrap', card.dateline || card.recordedAt || ''));
    header.appendChild(right);
    root.appendChild(header);

    if (!card.isEarnings && card.headline) {
        root.appendChild(el('p', `text-sm text-on-surface leading-relaxed${headline ? ' font-semibold' : ''}`, card.headline));
    }

    if (Array.isArray(card.pills) && card.pills.length > 0) {
        const pills = el('ul', 'flex flex-wrap gap-x-4 gap-y-1 text-xs text-on-surface-variant');
        card.pills.forEach(p => pills.appendChild(el('li', '', p.text)));
        root.appendChild(pills);
    }

    return root;
}

export function renderEvents(events, currentTicker, feedId = 'events-feed') {
    const feed = document.getElementById(feedId);
    if (!feed || !Array.isArray(events)) return;

    events.forEach(evt => {
        if (evt.ticker !== currentTicker || !evt.presented) return;

        const noMsg = document.getElementById('no-events-msg');
        if (noMsg) noMsg.remove();

        feed.prepend(buildCard(evt.presented));
        if (feed.children.length > 15) {
            feed.removeChild(feed.lastChild);
        }
    });
}
