import { Controller } from '@hotwired/stimulus';

/** Gap between the readout and the mark it reads, in screen pixels. */
const OFFSET_PX = 8;

/**
 * Hover readouts for a server-drawn SVG chart, one mark at a time. Each mark carries its reading in a <title>, which a
 * browser shows only after a second's rest; this takes the titles over and shows the reading the moment the cursor
 * reaches the mark, beside it, and sets `data-lit` on the mark so the template can light it up. The titles go back
 * when the chart leaves the page.
 */
export default class extends Controller {
    connect() {
        this.marks = [...this.element.querySelectorAll('title')].map((title) => {
            const element = title.parentElement;
            const mark = { element, title, text: title.textContent.trim() };
            title.remove();
            return mark;
        }).filter((mark) => mark.element !== null && mark.element !== this.element && mark.text !== '');
        this.texts = new Map(this.marks.map((mark) => [mark.element, mark.text]));

        this.tip = document.createElement('div');
        this.tip.setAttribute('role', 'tooltip');
        this.tip.className = 'pointer-events-none fixed z-50 max-w-xs px-2.5 py-1.5 bg-surface-container-high text-on-surface text-2xs leading-relaxed rounded-lg border border-outline-variant/40 hidden';
        document.body.appendChild(this.tip);

        this.current = null;
        this.onOver = (event) => this.show(event.target);
        this.onLeave = () => this.hide();
        this.onScroll = () => this.hide();
        this.element.addEventListener('pointerover', this.onOver);
        this.element.addEventListener('pointerleave', this.onLeave);
        window.addEventListener('scroll', this.onScroll, { passive: true });
    }

    disconnect() {
        this.element.removeEventListener('pointerover', this.onOver);
        this.element.removeEventListener('pointerleave', this.onLeave);
        window.removeEventListener('scroll', this.onScroll);
        this.hide();
        this.tip.remove();
        // Turbo caches the page as it stands: the titles go back so the cached copy reads as it was served.
        for (const mark of this.marks) {
            mark.element.prepend(mark.title);
        }
    }

    show(element) {
        const text = this.texts.get(element);
        if (text === undefined) {
            this.hide();
            return;
        }
        if (element === this.current) {
            return;
        }
        this.hide();
        this.current = element;
        element.setAttribute('data-lit', '');

        this.tip.textContent = text;
        this.tip.classList.remove('hidden');
        const anchor = element.getBoundingClientRect();
        const width = this.tip.offsetWidth;
        const height = this.tip.offsetHeight;
        const right = anchor.right + OFFSET_PX;
        const left = right + width > window.innerWidth ? anchor.left - OFFSET_PX - width : right;
        const above = anchor.top - OFFSET_PX - height;
        this.tip.style.left = `${Math.max(0, left)}px`;
        this.tip.style.top = `${above >= 0 ? above : anchor.bottom + OFFSET_PX}px`;
    }

    hide() {
        this.current?.removeAttribute('data-lit');
        this.current = null;
        this.tip.classList.add('hidden');
    }
}
