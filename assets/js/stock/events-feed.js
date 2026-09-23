/**
 * Live corporate events for the instrument page.
 *
 * Each event arrives with `presented`, the card App\Service\Event\EventPresenter built for it, so a live
 * event renders exactly as it will on the next page load. The browser used to re-derive the card from the
 * type string, and that copy fell behind the types the engines actually publish.
 */

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
}

function icon(name, className) {
    return el('span', `material-symbols-outlined ${className}`, name);
}

/** Mirrors the card markup in templates/stock/index.html.twig (#events-feed). */
function buildCard(card) {
    const root = el('div', `p-4 rounded bg-surface-container-lowest/80 border border-outline-variant/15 border-l-4 ${card.borderClass || 'border-l-primary'} hover:border-outline-variant/40 transition-all duration-200 space-y-2.5`);

    const header = el('div', 'flex items-start justify-between gap-3');
    const left = el('div', 'flex items-center flex-wrap gap-2 min-w-0');
    const iconWrap = el('div', `w-6 h-6 rounded-lg flex items-center justify-center shrink-0 ${card.iconClass || ''}`);
    iconWrap.appendChild(icon(card.icon || 'campaign', 'text-sm'));
    left.appendChild(iconWrap);
    left.appendChild(el('span', `inline-flex items-center rounded-md px-2 py-0.5 text-3xs font-bold font-mono uppercase tracking-wider border ${card.badgeClass || ''}`, card.badge || card.type || 'EVENT'));

    if (card.isEarnings && card.eps) {
        left.appendChild(el('span', 'text-xs font-bold font-mono text-on-surface tracking-tight', `EPS ${card.eps}`));
    }
    if (card.isEarnings && card.surpriseType !== 'met' && card.surpriseAmount) {
        left.appendChild(el('span', `text-2xs font-bold font-mono ${card.surpriseType === 'beat' ? 'text-secondary' : 'text-tertiary'}`, `(${card.surpriseText})`));
    } else if (card.isEarnings && card.surpriseType === 'met') {
        left.appendChild(el('span', 'text-2xs font-bold font-mono text-on-surface-variant/80', '(In-Line)'));
    }
    if (card.isEarnings && card.eva) {
        left.appendChild(el('span', `inline-flex items-center rounded px-1.5 py-0.5 text-3xs font-bold font-mono ${card.evaPositive ? 'bg-secondary/10 text-secondary' : 'bg-tertiary/10 text-tertiary'}`, card.eva));
    }
    header.appendChild(left);

    const right = el('div', 'text-right shrink-0 flex items-center gap-2');
    const change = card.changePercent !== undefined && card.changePercent !== null ? parseFloat(card.changePercent) : null;
    if (change !== null && change !== 0 && Number.isFinite(change)) {
        right.appendChild(el('span', `inline-flex items-center rounded px-1.5 py-0.5 text-3xs font-mono font-bold ${change > 0 ? 'bg-secondary/10 text-secondary' : 'bg-tertiary/10 text-tertiary'}`, `${change > 0 ? '+' : ''}${change.toFixed(2)}%`));
    }
    right.appendChild(el('span', 'text-3xs text-on-surface-variant font-mono whitespace-nowrap', card.recordedAt || ''));
    header.appendChild(right);
    root.appendChild(header);

    if (!card.isEarnings && card.headline) {
        root.appendChild(el('p', 'text-xs text-on-surface font-medium leading-relaxed pl-8', card.headline));
    }

    if (Array.isArray(card.pills) && card.pills.length > 0) {
        const pills = el('div', 'flex flex-wrap gap-2 pt-1 pl-8');
        card.pills.forEach(p => {
            const pill = el('div', `inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono ${p.pillClass || ''}`);
            pill.appendChild(icon(p.icon || 'feed', 'text-sm shrink-0'));
            pill.appendChild(el('span', 'leading-none', p.text));
            pills.appendChild(pill);
        });
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
