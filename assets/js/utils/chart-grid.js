/**
 * A grid of chart cards with a category bar, a text filter and per-card expand buttons — the shape the
 * economy page's macro grid and the stock page's financial grid share. One implementation, driven by a
 * config; visibility runs through the `hidden` class throughout, never an inline `style.display`.
 */

/** Category-button styling, kept in one place so the two states cannot drift apart. */
const CAT_BTN_BASE = 'px-3 py-1.5 text-xs font-bold rounded-lg transition-all whitespace-nowrap cursor-pointer';
const CAT_BTN_ACTIVE = 'bg-primary text-on-primary shadow-md shadow-primary/20';
const CAT_BTN_IDLE = 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface';

/** Each grid's live filter state, and the function that re-applies it, keyed by grid id. */
const gridFilters = {};

/**
 * @param {{gridId: string, categoryAttribute: string, buttonClass: string, searchInputId: string, resize: Function}} config
 */
export function setupChartGridFilters(config) {
    const grid = document.getElementById(config.gridId);
    if (!grid) return;

    const state = { category: 'all', search: '' };

    const apply = () => {
        const query = state.search.toLowerCase().trim();
        grid.querySelectorAll('.chart-card').forEach(card => {
            // An expanded card hides its siblings outright and owns the grid until collapsed.
            if (card.dataset.soloHidden === 'true') return;

            const category = card.dataset[config.categoryAttribute] || '';
            const matchesCategory = state.category === 'all' || category === state.category;
            const matchesSearch = !query || (card.textContent || '').toLowerCase().includes(query);
            card.classList.toggle('hidden', !(matchesCategory && matchesSearch));
        });
        setTimeout(config.resize, 50);
    };

    gridFilters[config.gridId] = apply;

    // The category bar sits outside the grid element, so this is a document-wide lookup; the
    // button class is already specific to one grid.
    const catButtons = document.querySelectorAll(`.${config.buttonClass}`);
    catButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            state.category = btn.dataset.category || 'all';
            catButtons.forEach(b => {
                const isActive = b === btn;
                b.className = `${config.buttonClass} ${CAT_BTN_BASE} ${isActive ? CAT_BTN_ACTIVE : CAT_BTN_IDLE}`;
                b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            apply();
        });
    });

    const searchInput = document.getElementById(config.searchInputId);
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            state.search = e.target.value;
            apply();
        });
    }
}

/**
 * Wires every `.expand-chart-btn` on the page. An expanded card spans the grid and hides its siblings;
 * collapsing hands the grid back to its filter, which knows which siblings belong on screen.
 *
 * @param {Function} onResize Called after the layout changes, so the charts inside can re-measure.
 */
export function setupExpandableCards(onResize) {
    document.querySelectorAll('.expand-chart-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const card = this.closest('.chart-card');
            const grid = card?.closest('.grid');
            if (!card || !grid) return;

            const icon = this.querySelector('.expand-icon');
            const canvasContainer = card.querySelector('.chart-canvas-container');
            const siblings = [...grid.querySelectorAll('.chart-card')].filter(c => c !== card);
            const isExpanded = card.classList.contains('md:col-span-2') || card.classList.contains('lg:col-span-2');

            if (isExpanded) {
                card.classList.remove('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.remove('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'open_in_full';
                this.setAttribute('aria-expanded', 'false');

                siblings.forEach(c => { delete c.dataset.soloHidden; });
                const reapply = gridFilters[grid.id];
                if (reapply) {
                    reapply();
                } else {
                    siblings.forEach(c => c.classList.remove('hidden'));
                }
            } else {
                card.classList.add('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.add('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'close_fullscreen';
                this.setAttribute('aria-expanded', 'true');

                siblings.forEach(c => {
                    c.dataset.soloHidden = 'true';
                    c.classList.add('hidden');
                });
            }

            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
                if (onResize) onResize();
            }, 60);
        });
    });
}
