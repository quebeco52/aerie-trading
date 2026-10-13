import { Controller } from '@hotwired/stimulus';
import { THEME_COLORS, withAlpha } from '../js/utils/colors.js';

/**
 * The "How the business works" modal: draws the lines between the cards BusinessMapBuilder lays out, and lights one
 * card's lines and neighbours on hover or focus. Each edge is [from, to, sign, weight]: sign colours it (helped,
 * hurt, or carrying revenue or cost), weight sizes it (an effect for a line out of the economy, a share within the
 * firm). Lines run between neighbouring columns; a line inside one column bows out to its right.
 */
const SIGN_COLORS = { 1: THEME_COLORS.positive, '-1': THEME_COLORS.negative, 0: withAlpha(THEME_COLORS.textMuted, 0.45) };

/** Line width in px: effects (small fractions) on a square-root scale, shares (above a tenth) on a linear one. */
function lineWidth(weight) {
    const w = Math.abs(Number(weight) || 0);
    return w < 0.1 ? 1.5 + 5 * Math.sqrt(w / 0.03) : 1.5 + 5 * Math.min(1, w);
}

/** Bow of a line that leaves and re-enters the same column, in px. */
const SAME_COLUMN_BOW = 34;

export default class extends Controller {
    static targets = ['map', 'lines'];
    static values = { edges: Array };

    connect() {
        this.openHandler = () => this.open();
        this.keydownHandler = (event) => {
            if (event.key === 'Escape' && !this.element.classList.contains('hidden')) this.close();
        };
        document.addEventListener('business-map:open', this.openHandler);
        document.addEventListener('keydown', this.keydownHandler);

        this.resizeObserver = new ResizeObserver(() => this.draw());
        this.resizeObserver.observe(this.mapTarget);
        this.mapTarget.addEventListener('pointerover', (event) => this.focusFrom(event));
        this.mapTarget.addEventListener('pointerleave', () => this.highlight(null));
        this.mapTarget.addEventListener('focusin', (event) => this.focusFrom(event));
        this.mapTarget.addEventListener('focusout', () => this.highlight(null));
    }

    disconnect() {
        document.removeEventListener('business-map:open', this.openHandler);
        document.removeEventListener('keydown', this.keydownHandler);
        this.resizeObserver?.disconnect();
    }

    open() {
        this.element.classList.remove('hidden');
        this.element.classList.add('flex');
        requestAnimationFrame(() => this.draw());
    }

    close() {
        this.element.classList.add('hidden');
        this.element.classList.remove('flex');
    }

    backdropClick(event) {
        if (event.target === this.element) this.close();
    }

    /** Hands over to the earnings-flow chart, which picks up at the revenue this map ends on. */
    handoff() {
        this.close();
        document.dispatchEvent(new CustomEvent('sankey:open'));
    }

    draw() {
        const svg = this.linesTarget;
        const box = this.mapTarget.getBoundingClientRect();
        if (box.width === 0 || getComputedStyle(svg).display === 'none') return;
        svg.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);

        const rect = {};
        this.mapTarget.querySelectorAll('[data-map-node]').forEach((el) => {
            const r = el.getBoundingClientRect();
            rect[el.dataset.mapNode] = { x0: r.left - box.left, x1: r.right - box.left, y: r.top - box.top, h: r.height };
        });

        // Spread each card's anchors down its edge, ordered by the far end, so lines into one card do not stack.
        const edges = this.edgesValue.filter(([from, to]) => rect[from] && rect[to]);
        const outs = {};
        const ins = {};
        edges.forEach(([from, to], i) => {
            (outs[from] ||= []).push(i);
            (ins[to] ||= []).push(i);
        });
        const anchor = edges.map(() => ({}));
        const place = (lists, end, other) => {
            for (const [id, list] of Object.entries(lists)) {
                const r = rect[id];
                list.sort((a, b) => rect[edges[a][other]].y - rect[edges[b][other]].y);
                list.forEach((edgeIndex, k) => {
                    anchor[edgeIndex][end] = r.y + r.h * (0.25 + 0.5 * (k + 0.5) / list.length);
                });
            }
        };
        place(outs, 'y1', 1);
        place(ins, 'y2', 0);

        svg.replaceChildren(...edges.map(([from, to, sign, weight], i) => {
            const s = rect[from];
            const t = rect[to];
            let d;
            if (Math.abs(s.x0 - t.x0) < 2) {
                const x = s.x1;
                d = `M${x} ${anchor[i].y1} C${x + SAME_COLUMN_BOW} ${anchor[i].y1}, ${x + SAME_COLUMN_BOW} ${anchor[i].y2}, ${x} ${anchor[i].y2}`;
            } else {
                const dx = (t.x0 - s.x1) / 2;
                d = `M${s.x1} ${anchor[i].y1} C${s.x1 + dx} ${anchor[i].y1}, ${t.x0 - dx} ${anchor[i].y2}, ${t.x0} ${anchor[i].y2}`;
            }
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', SIGN_COLORS[sign] ?? SIGN_COLORS[0]);
            path.setAttribute('stroke-width', lineWidth(weight).toFixed(2));
            path.setAttribute('stroke-linecap', 'round');
            path.dataset.from = from;
            path.dataset.to = to;
            path.style.transition = 'opacity 150ms';
            return path;
        }));
    }

    focusFrom(event) {
        const card = event.target.closest('[data-map-node]');
        if (card) this.highlight(card.dataset.mapNode);
    }

    /** Lights one card, its lines and the cards one step either side; null restores everything. */
    highlight(id) {
        const lit = new Set(id ? [id] : []);
        this.linesTarget.querySelectorAll('path').forEach((path) => {
            const on = id === null || path.dataset.from === id || path.dataset.to === id;
            path.style.opacity = on ? '1' : '0.08';
            if (on && id !== null) {
                lit.add(path.dataset.from);
                lit.add(path.dataset.to);
            }
        });
        this.mapTarget.querySelectorAll('[data-map-node]').forEach((el) => {
            el.style.opacity = id === null || lit.has(el.dataset.mapNode) ? '1' : '0.3';
        });
    }
}
