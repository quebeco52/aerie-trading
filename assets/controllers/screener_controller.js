import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['searchInput', 'tbody', 'header', 'sectorBtn', 'row'];

    connect() {
        this.selectedSector = 'ALL';
        this.searchQuery = '';
        this.currentSort = { colIndex: null, direction: 'asc' };
    }

    filterSector(event) {
        const btn = event.currentTarget;
        const sector = btn.dataset.sector || 'ALL';

        this.sectorBtnTargets.forEach(b => {
            b.classList.remove('bg-primary', 'text-on-primary', 'shadow-md', 'shadow-primary/20');
            b.classList.add('bg-surface-container', 'text-on-surface-variant');
        });

        btn.classList.remove('bg-surface-container', 'text-on-surface-variant');
        btn.classList.add('bg-primary', 'text-on-primary', 'shadow-md', 'shadow-primary/20');

        this.selectedSector = sector;
        this.applyFilters();
    }

    onSearch() {
        if (this.hasSearchInputTarget) {
            this.searchQuery = this.searchInputTarget.value.trim().toLowerCase();
            this.applyFilters();
        }
    }

    applyFilters() {
        this.rowTargets.forEach(row => {
            const sec = row.dataset.sector || '';
            const ticker = (row.dataset.ticker || '').toLowerCase();
            const name = (row.dataset.name || '').toLowerCase();

            const matchesSector = (this.selectedSector === 'ALL' || sec === this.selectedSector);
            const matchesSearch = (this.searchQuery === '' || ticker.includes(this.searchQuery) || name.includes(this.searchQuery));

            if (matchesSector && matchesSearch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    sort(event) {
        const header = event.currentTarget;
        const index = this.headerTargets.indexOf(header);
        if (index === -1 || !this.hasTbodyTarget) return;

        const rows = Array.from(this.tbodyTarget.querySelectorAll('.screener-row'));
        if (rows.length === 0) return;

        const direction = (this.currentSort.colIndex === index && this.currentSort.direction === 'desc') ? 'asc' : 'desc';

        this.headerTargets.forEach(h => {
            const icon = h.querySelector('.sort-arrow');
            if (icon) icon.remove();
        });

        const arrow = document.createElement('span');
        arrow.className = 'sort-arrow ml-1 text-primary text-xs';
        arrow.innerText = direction === 'asc' ? '↑' : '↓';
        header.appendChild(arrow);

        rows.sort((a, b) => {
            const aVal = a.children[index]?.getAttribute('data-value') || '';
            const bVal = b.children[index]?.getAttribute('data-value') || '';
            const aNum = parseFloat(aVal);
            const bNum = parseFloat(bVal);

            if (!isNaN(aNum) && !isNaN(bNum)) {
                return direction === 'asc' ? aNum - bNum : bNum - aNum;
            }
            return direction === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        });

        this.tbodyTarget.append(...rows);
        this.currentSort = { colIndex: index, direction };
    }
}
