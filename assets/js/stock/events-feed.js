import { formatShares } from '../utils/formatters.js';

export function parseSubActionPills(text) {
    if (!text || !text.trim()) return [];
    const lines = text.split(/(?:\r\n|\r|\n)+|\s*[•*·]\s*/g);
    const pills = [];
    lines.forEach(rawLine => {
        const line = rawLine.replace(/^[ \t\n\r\0\x0B•*·-]+|[ \t\n\r\0\x0B•*·-]+$/g, '').trim();
        if (!line) return;

        const divMatch = line.match(/Paid\s+([+-]?\$?[\d,.]+)\/share\s+div(?:\s*\(([^)]+)\))?/i);
        if (divMatch) {
            const divPerShare = divMatch[1];
            const rawDetails = divMatch[2] || '';
            const yMatch = rawDetails.match(/([\d,.]+)%\s*yield/i);
            let divText = `Paid ${divPerShare}/sh`;
            if (yMatch) {
                divText = `Paid ${divPerShare}/sh · ${yMatch[1]}% yield`;
            } else if (rawDetails) {
                divText = `Paid ${divPerShare}/sh (${rawDetails.trim()})`;
            }
            pills.push({
                icon: 'payments',
                text: divText,
                pillClass: 'bg-emerald-500/10 text-emerald-300 border-emerald-500/25'
            });
            return;
        }

        const bbMatch = line.match(/Bought\s+back\s+([\d,]+)\s+shares/i);
        if (bbMatch) {
            const count = parseFloat(bbMatch[1].replace(/,/g, ''));
            pills.push({
                icon: 'published_with_changes',
                text: `Repurchased ${formatShares(count)} shares`,
                pillClass: 'bg-cyan-500/10 text-cyan-300 border-cyan-500/25'
            });
            return;
        }

        const debtMatch = line.match(/Issued\s+([+-]?\$?[\d,.]+[BMTk]?)\s+in\s+bonds(?:\s+for\s+([^.]+))?/i);
        if (debtMatch) {
            const amount = debtMatch[1];
            const purpose = debtMatch[2] ? ` (${debtMatch[2].trim()})` : '';
            pills.push({
                icon: 'receipt_long',
                text: `Issued ${amount} bonds${purpose}`,
                pillClass: 'bg-amber-500/10 text-amber-300 border-amber-500/25'
            });
            return;
        }

        pills.push({
            icon: 'feed',
            text: line.replace(/\.$/, ''),
            pillClass: 'bg-primary/10 text-primary border-primary/20'
        });
    });
    return pills;
}

export function renderEvents(events, currentTicker, feedId = 'events-feed') {
    const feed = document.getElementById(feedId);
    if (!feed || !Array.isArray(events)) return;

    events.forEach(evt => {
        if (evt.ticker !== currentTicker) return;

        const noMsg = document.getElementById('no-events-msg');
        if (noMsg) noMsg.remove();

        const type = (evt.type || 'EVENT').toUpperCase();
        const rawDesc = evt.description || (type === 'SHOCK' ? 'Sudden market shock detected.' : 'Earnings report released.');
        const changePercent = evt.change_percent !== undefined && evt.change_percent !== null ? parseFloat(evt.change_percent) : null;
        const now = new Date();
        const timeStr = now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0') + ' ' +
            String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0');

        let cardData = {
            badge: type,
            badgeClass: 'bg-surface-container-high text-on-surface-variant border-outline-variant/30',
            borderClass: 'border-l-primary',
            icon: 'campaign',
            iconClass: 'bg-primary/10 text-primary',
            isEarnings: false,
            headline: rawDesc,
            eps: null,
            surpriseType: null,
            surpriseAmount: null,
            surpriseText: 'In-Line',
            eva: null,
            evaPositive: true,
            pills: []
        };

        if (type === 'EARNINGS') {
            cardData.isEarnings = true;
            const pattern = /^Q-Earnings:\s*([+-]?\$?[\d,.]+(?:\s*[BMTk])?)\s*\((Beat expectations by|Missed expectations by|Met expectations(?: exactly)?)\s*([+-]?\$?[\d,.]+(?:\s*[BMTk])?)?\s*\|\s*([+-]?\$?[\d,.]+[BMTk]?\s*EVA)\)\.?/i;
            const match = rawDesc.match(pattern);
            let remaining = rawDesc;
            if (match) {
                cardData.eps = match[1].trim();
                const verbiage = match[2].toLowerCase();
                if (verbiage.includes('beat')) {
                    cardData.surpriseType = 'beat';
                } else if (verbiage.includes('missed')) {
                    cardData.surpriseType = 'miss';
                } else {
                    cardData.surpriseType = 'met';
                }
                const rawSurprise = match[3] ? match[3].trim() : null;
                const surpriseVal = rawSurprise ? parseFloat(rawSurprise.replace(/[^\d.]/g, '')) : 0.0;
                if (surpriseVal < 0.005) {
                    cardData.surpriseType = 'met';
                    cardData.surpriseAmount = null;
                    cardData.surpriseText = 'In-Line';
                } else {
                    cardData.surpriseAmount = rawSurprise;
                    cardData.surpriseText = cardData.surpriseType === 'beat' ? `Beat by +${rawSurprise}` : `Missed by ${rawSurprise}`;
                }

                cardData.eva = match[4].trim();
                cardData.evaPositive = !cardData.eva.startsWith('-');
                cardData.headline = `Q-Earnings: ${cardData.eps}`;
                remaining = rawDesc.substring(match[0].length).trim();
            }

            cardData.pills = parseSubActionPills(remaining);

            if (cardData.surpriseType === 'beat') {
                cardData.badge = 'EARNINGS BEAT';
                cardData.badgeClass = 'bg-secondary/10 text-secondary border-secondary/30';
                cardData.borderClass = 'border-l-secondary';
                cardData.icon = 'trending_up';
                cardData.iconClass = 'bg-secondary/15 text-secondary';
            } else if (cardData.surpriseType === 'miss') {
                cardData.badge = 'EARNINGS MISS';
                cardData.badgeClass = 'bg-tertiary/10 text-tertiary border-tertiary/30';
                cardData.borderClass = 'border-l-tertiary';
                cardData.icon = 'trending_down';
                cardData.iconClass = 'bg-tertiary/15 text-tertiary';
            } else {
                cardData.badge = 'EARNINGS IN-LINE';
                cardData.badgeClass = 'bg-primary/10 text-primary border-primary/20';
                cardData.borderClass = 'border-l-primary';
                cardData.icon = 'equalizer';
                cardData.iconClass = 'bg-primary/15 text-primary';
            }
        } else if (type === 'SHOCK') {
            const isPos = changePercent !== null ? changePercent >= 0 : false;
            cardData.badge = 'MARKET SHOCK';
            cardData.badgeClass = isPos ? 'bg-amber-500/10 text-amber-300 border-amber-500/30' : 'bg-tertiary/10 text-tertiary border-tertiary/30';
            cardData.borderClass = isPos ? 'border-l-amber-400' : 'border-l-tertiary';
            cardData.icon = 'bolt';
            cardData.iconClass = isPos ? 'bg-amber-500/15 text-amber-300' : 'bg-tertiary/15 text-tertiary';
        } else if (['SPLIT', 'REVERSE_SPLIT', 'REVSPLIT'].includes(type)) {
            const isRev = type !== 'SPLIT';
            cardData.badge = isRev ? 'REVERSE STOCK SPLIT' : 'STOCK SPLIT';
            cardData.badgeClass = 'bg-purple-500/10 text-purple-300 border-purple-500/30';
            cardData.borderClass = 'border-l-purple-400';
            cardData.icon = 'call_split';
            cardData.iconClass = 'bg-purple-500/15 text-purple-300';
        } else if (['RATING_UPGRADE', 'RATING_DOWNGRADE', 'DEBT'].includes(type)) {
            const isUp = type === 'RATING_UPGRADE' || rawDesc.toLowerCase().includes('upgrade');
            cardData.badge = isUp ? 'RATING UPGRADE' : (type === 'RATING_DOWNGRADE' ? 'RATING DOWNGRADE' : 'CREDIT RATING');
            cardData.badgeClass = isUp ? 'bg-secondary/10 text-secondary border-secondary/30' : 'bg-tertiary/10 text-tertiary border-tertiary/30';
            cardData.borderClass = isUp ? 'border-l-secondary' : 'border-l-tertiary';
            cardData.icon = isUp ? 'credit_score' : 'warning';
            cardData.iconClass = isUp ? 'bg-secondary/15 text-secondary' : 'bg-tertiary/15 text-tertiary';
        } else if (['ACQUISITION', 'MERGER', 'DIVESTITURE'].includes(type)) {
            cardData.badge = type;
            cardData.badgeClass = 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30';
            cardData.borderClass = 'border-l-cyan-400';
            cardData.icon = 'domain_add';
            cardData.iconClass = 'bg-cyan-500/15 text-cyan-300';
        } else if (type === 'INDEX') {
            cardData.badge = 'INDEX RECONSTITUTION';
            cardData.badgeClass = 'bg-primary/15 text-primary border-primary/40';
            cardData.borderClass = 'border-l-primary';
            cardData.icon = 'checklist';
            cardData.iconClass = 'bg-primary/20 text-primary';
        } else if (type === 'BANKRUPTCY') {
            cardData.badge = 'BANKRUPTCY';
            cardData.badgeClass = 'bg-red-500/15 text-red-400 border-red-500/40';
            cardData.borderClass = 'border-l-red-500';
            cardData.icon = 'gavel';
            cardData.iconClass = 'bg-red-500/20 text-red-400';
        }

        const card = document.createElement('div');
        card.className = `p-4 rounded-xl bg-surface-container-lowest/80 border border-outline-variant/15 border-l-4 ${cardData.borderClass} hover:border-outline-variant/40 transition-all duration-200 space-y-2.5`;

        let headerMetrics = '';
        if (cardData.isEarnings && cardData.eps) {
            headerMetrics += `<span class="text-xs font-bold font-mono text-on-surface tracking-tight">EPS ${cardData.eps}</span>`;
        }
        if (cardData.isEarnings && cardData.surpriseType !== 'met' && cardData.surpriseAmount) {
            const colorClass = cardData.surpriseType === 'beat' ? 'text-secondary' : 'text-tertiary';
            headerMetrics += `<span class="text-2xs font-bold font-mono ${colorClass}">(${cardData.surpriseText})</span>`;
        } else if (cardData.isEarnings && cardData.surpriseType === 'met') {
            headerMetrics += `<span class="text-2xs font-bold font-mono text-on-surface-variant/80">(In-Line)</span>`;
        }
        if (cardData.isEarnings && cardData.eva) {
            const evaClass = cardData.evaPositive ? 'bg-secondary/10 text-secondary' : 'bg-tertiary/10 text-tertiary';
            headerMetrics += `<span class="inline-flex items-center rounded px-1.5 py-0.5 text-3xs font-bold font-mono ${evaClass}">${cardData.eva}</span>`;
        }

        let changeBadge = '';
        if (changePercent !== null && changePercent !== 0) {
            const changeClass = changePercent > 0 ? 'bg-secondary/10 text-secondary' : 'bg-tertiary/10 text-tertiary';
            changeBadge = `<span class="inline-flex items-center rounded px-1.5 py-0.5 text-3xs font-mono font-bold ${changeClass}">${changePercent > 0 ? '+' : ''}${changePercent.toFixed(2)}%</span>`;
        }

        let pillsHtml = '';
        if (cardData.pills && cardData.pills.length > 0) {
            pillsHtml = `<div class="flex flex-wrap gap-2 pt-1 pl-8">` +
                cardData.pills.map(p => `
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-mono ${p.pillClass}">
                        <span class="material-symbols-outlined text-sm shrink-0">${p.icon}</span>
                        <span class="leading-none">${p.text}</span>
                    </div>
                `).join('') +
            `</div>`;
        }

        let bodyHtml = '';
        if (!cardData.isEarnings && cardData.headline) {
            bodyHtml = `<p class="text-xs text-on-surface font-medium leading-relaxed pl-8">${cardData.headline}</p>`;
        }

        card.innerHTML = `
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center flex-wrap gap-2 min-w-0">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center shrink-0 ${cardData.iconClass}">
                        <span class="material-symbols-outlined text-sm">${cardData.icon}</span>
                    </div>
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-3xs font-bold font-mono uppercase tracking-wider border ${cardData.badgeClass}">
                        ${cardData.badge}
                    </span>
                    ${headerMetrics}
                </div>
                <div class="text-right shrink-0 flex items-center gap-2">
                    ${changeBadge}
                    <span class="text-3xs text-on-surface-variant font-mono whitespace-nowrap">${timeStr}</span>
                </div>
            </div>
            ${bodyHtml}
            ${pillsHtml}
        `;

        feed.prepend(card);
        if (feed.children.length > 15) {
            feed.removeChild(feed.lastChild);
        }
    });
}
