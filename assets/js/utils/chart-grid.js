/**
 * A grid of chart cards with a category bar, a text filter and per-card expand buttons — the shape the
 * economy page's macro grid and the stock page's financial grid share. One implementation, driven by a
 * config; visibility runs through the `hidden` class throughout, never an inline `style.display`.
 *
 * The grid element carries `data-chart-grid` and holds its cards in `[data-chart-section]` groups,
 * each with its own heading. A section with no card on screen is hidden with them. A card marked
 * `data-unavailable` has nothing to show for this company and stays hidden whatever the filter.
 */

/** Each grid's live filter state, and the function that re-applies it, keyed by grid id. */
const gridFilters = {};

/** Hides every section left without a visible card, and shows the empty note when nothing is left. */
function syncSections(grid) {
    grid.querySelectorAll('[data-chart-section]').forEach(section => {
        section.classList.toggle('hidden', !section.querySelector('.chart-card:not(.hidden)'));
    });
    const empty = grid.querySelector('[data-chart-empty]');
    if (empty) empty.classList.toggle('hidden', !!grid.querySelector('.chart-card:not(.hidden)'));
}

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
            const unavailable = card.hasAttribute('data-unavailable');
            card.classList.toggle('hidden', unavailable || !(matchesCategory && matchesSearch));
        });
        syncSections(grid);
        setTimeout(config.resize, 50);
    };

    gridFilters[config.gridId] = apply;

    // The category bar sits outside the grid element, so this is a document-wide lookup; the
    // button class is already specific to one grid.
    const catButtons = document.querySelectorAll(`.${config.buttonClass}`);
    catButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            state.category = btn.dataset.category || 'all';
            // Styling follows aria-pressed (`.seg-btn` in app.css).
            catButtons.forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
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
 * Re-applies a grid's filter after a card's `data-unavailable` changed. Returns false when the grid
 * has no filter wired, so the caller can set the card's visibility itself.
 */
export function refreshChartGrid(gridId) {
    const apply = gridFilters[gridId];
    if (!apply) return false;
    apply();
    return true;
}

/**
 * Wires every `.expand-chart-btn` on the page. An expanded card spans its section, grows taller and
 * hides every other card in the grid; collapsing hands the grid back to its filter, which knows which
 * cards belong on screen.
 *
 * @param {Function} onResize Called after the layout changes, so the charts inside can re-measure.
 */
export function setupExpandableCards(onResize) {
    document.querySelectorAll('.expand-chart-btn').forEach(btn => {
        btn.setAttribute('aria-expanded', 'false');
        btn.addEventListener('click', function () {
            const card = this.closest('.chart-card');
            const grid = card?.closest('[data-chart-grid]');
            if (!card || !grid) return;

            const icon = this.querySelector('.expand-icon');
            const canvasContainer = card.querySelector('.chart-canvas-container');
            const siblings = [...grid.querySelectorAll('.chart-card')].filter(c => c !== card);
            const isExpanded = this.getAttribute('aria-expanded') === 'true';

            if (isExpanded) {
                // A section's lead card (`data-wide`) spans the grid by layout, not because it was expanded.
                if (!card.hasAttribute('data-wide')) card.classList.remove('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.remove('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'open_in_full';
                this.setAttribute('aria-expanded', 'false');
                this.setAttribute('aria-label', 'Expand chart');
                this.title = 'Expand chart';

                siblings.forEach(c => { delete c.dataset.soloHidden; });
                const reapply = gridFilters[grid.id];
                if (reapply) {
                    reapply();
                } else {
                    siblings.forEach(c => c.classList.toggle('hidden', c.hasAttribute('data-unavailable')));
                    syncSections(grid);
                }
            } else {
                card.classList.add('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.add('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'close_fullscreen';
                this.setAttribute('aria-expanded', 'true');
                this.setAttribute('aria-label', 'Collapse chart');
                this.title = 'Collapse chart';

                siblings.forEach(c => {
                    c.dataset.soloHidden = 'true';
                    c.classList.add('hidden');
                });
                syncSections(grid);
            }

            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
                if (onResize) onResize();
            }, 60);
        });
    });
}
