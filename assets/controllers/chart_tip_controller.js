import { Controller } from '@hotwired/stimulus';

const SVG_NS = 'http://www.w3.org/2000/svg';
/** Marks whose box is longer than this, in chart units (a target line, a governor's dashed line), are not columns. */
const POINT_MAX_UNITS = 12;
/** Marks this close in chart units share a column: a meeting's dot and its dissent marks. */
const COLUMN_TOLERANCE_UNITS = 1.5;
/** Gap between the readout and the point it reads, in screen pixels. */
const OFFSET_PX = 10;

/**
 * Hover readouts for a server-drawn SVG chart, read column by column as a trading chart's are. Each mark carries its
 * reading in a <title>, which a browser shows only after the cursor rests on the exact shape. This takes the titles
 * over, snaps to the column of marks nearest the cursor across the chart (height does not matter), marks it with a
 * guide line, and pins one readout beside its top mark with every reading in it. The titles go back when the chart
 * leaves the page.
 */
export default class extends Controller {
    connect() {
        const marks = [...this.element.querySelectorAll('title')].map((title) => {
            const element = title.parentElement;
            const mark = { element, title, text: title.textContent.trim() };
            title.remove();
            return mark;
        }).filter((mark) => mark.element !== null && mark.element !== this.element && mark.text !== '');
        this.marks = marks;
        // Built on first hover: a chart drawn hidden (a closed panel) has no geometry to measure yet.
        this.columns = null;

        const viewBox = this.element.viewBox.baseVal;
        this.guide = document.createElementNS(SVG_NS, 'line');
        this.guide.setAttribute('y1', String(viewBox.y));
        this.guide.setAttribute('y2', String(viewBox.y + viewBox.height));
        this.guide.setAttribute('stroke-width', '0.6');
        this.guide.setAttribute('stroke-dasharray', '2 2');
        this.guide.setAttribute('pointer-events', 'none');
        this.guide.classList.add('stroke-on-surface-variant', 'hidden');
        this.element.appendChild(this.guide);

        this.tip = document.createElement('div');
        this.tip.setAttribute('role', 'tooltip');
        this.tip.className = 'pointer-events-none fixed z-50 max-w-xs p-2.5 bg-surface-container-high text-on-surface text-2xs leading-relaxed whitespace-pre-line rounded-lg border border-outline-variant/40 hidden';
        document.body.appendChild(this.tip);

        this.current = null;
        this.onMove = (event) => this.show(event);
        this.onLeave = () => this.hide();
        this.onScroll = () => this.hide();
        this.element.addEventListener('pointermove', this.onMove);
        this.element.addEventListener('pointerleave', this.onLeave);
        window.addEventListener('scroll', this.onScroll, { passive: true });
    }

    disconnect() {
        this.element.removeEventListener('pointermove', this.onMove);
        this.element.removeEventListener('pointerleave', this.onLeave);
        window.removeEventListener('scroll', this.onScroll);
        this.tip.remove();
        this.guide.remove();
        // Turbo caches the page as it stands: the titles go back so the cached copy reads as it was served.
        for (const mark of this.marks) {
            mark.element.prepend(mark.title);
        }
    }

    /**
     * The marks grouped into columns by their centre across the chart, in chart units, left to right. Long marks join
     * no column; a chart with no point marks reads its long ones as columns instead.
     */
    buildColumns(marks) {
        const measured = marks.map((mark) => {
            const box = mark.element.getBBox();
            return { ...mark, x: box.x + (box.width / 2), y: box.y, isPoint: Math.max(box.width, box.height) <= POINT_MAX_UNITS };
        });
        const points = measured.filter((mark) => mark.isPoint);
        const columns = [];
        for (const mark of (points.length > 0 ? points : measured).sort((a, b) => a.x - b.x)) {
            const last = columns[columns.length - 1];
            if (last !== undefined && Math.abs(mark.x - last.x) <= COLUMN_TOLERANCE_UNITS) {
                last.marks.push(mark);
            } else {
                columns.push({ x: mark.x, marks: [mark] });
            }
        }
        for (const column of columns) {
            column.marks.sort((a, b) => a.y - b.y);
            column.text = column.marks.map((mark) => mark.text).join('\n');
        }

        return columns;
    }

    show(event) {
        this.columns ??= this.buildColumns(this.marks);
        if (this.columns.length === 0) {
            return;
        }
        const x = this.toChartX(event.clientX);
        let column = this.columns[0];
        for (const candidate of this.columns) {
            if (Math.abs(candidate.x - x) < Math.abs(column.x - x)) {
                column = candidate;
            }
        }
        if (column === this.current) {
            return;
        }
        this.current = column;

        this.guide.setAttribute('x1', String(column.x));
        this.guide.setAttribute('x2', String(column.x));
        this.guide.classList.remove('hidden');

        this.tip.textContent = column.text;
        this.tip.classList.remove('hidden');
        const anchor = column.marks[0].element.getBoundingClientRect();
        const width = this.tip.offsetWidth;
        const height = this.tip.offsetHeight;
        const right = anchor.right + OFFSET_PX;
        const left = right + width > window.innerWidth ? anchor.left - OFFSET_PX - width : right;
        const above = anchor.top - OFFSET_PX - height;
        this.tip.style.left = `${Math.max(0, left)}px`;
        this.tip.style.top = `${above >= 0 ? above : anchor.bottom + OFFSET_PX}px`;
    }

    hide() {
        this.current = null;
        this.tip.classList.add('hidden');
        this.guide.classList.add('hidden');
    }

    /** A screen x as chart units, through the chart's own transform. */
    toChartX(clientX) {
        const point = this.element.createSVGPoint();
        point.x = clientX;
        point.y = 0;
        const matrix = this.element.getScreenCTM();

        return matrix === null ? clientX : point.matrixTransform(matrix.inverse()).x;
    }
}
