import { setupChartDefaults } from '../utils/chart-config.js';
import { initPriceChart, updateLivePricePoint, resizePriceChart, destroyPriceChart } from '../stock/price-chart.js';
import { initEtfChart, updateEtfPie, resizeEtfChart, destroyEtfChart } from '../stock/etf-chart.js';
import { updatePriceUI, updateMacroIndicators, resetPriceHistoryState } from '../stock/stats-updater.js';
import { renderEvents } from '../stock/events-feed.js';
import { updateMacroCharts, resizeMacroCharts, destroyMacroCharts, setMacroTimeframe } from '../stock/macro-charts.js';
import { updateFundamentalCharts, resizeFundamentalCharts, destroyFundamentalCharts } from '../stock/fundamental-charts.js';

let rawReports = [];
let currentContext = {};
let marketUpdateHandler = null;
let tabChangeHandler = null;

function getAerieContext() {
    const d = window.AERIE_DATA || {};
    return {
        ticker: d.ticker || null,
        isEtf: !!d.isEtf,
        businessModel: d.businessModel || 'none',
        isFinancial: !!d.isFinancial,
        sharesOutstanding: d.sharesOutstanding || 1,
        currentPrice: d.currentPrice || 0,
        userQuantity: d.userQuantity || 0,
        eps: d.eps || 0,
        ticksPerYear: d.ticksPerYear || 54000,
        pieLabels: Array.isArray(d.pieLabels) ? d.pieLabels : [],
        pieData: Array.isArray(d.pieData) ? d.pieData : [],
        sharesMap: d.sharesMap || {}
    };
}

function initStockPage() {
    setupChartDefaults();
    currentContext = getAerieContext();
    const { ticker, isEtf, ticksPerYear, pieLabels, pieData } = currentContext;

    const container = document.getElementById('mainChartContainer');
    if (!container || !ticker) return;
    if (container.dataset.initialized) return;
    container.dataset.initialized = 'true';

    cleanupPageResources();

    // Initialize Price Chart (Lightweight Charts)
    initPriceChart(container, ticker, ticksPerYear);

    // Initialize ETF Doughnut if applicable
    if (isEtf) {
        initEtfChart('etfPieChart', pieLabels, pieData);
    }

    // Fetch reports
    let isAborted = false;
    if (!isEtf) {
        fetch(`/api/fundamentals?ticker=${encodeURIComponent(ticker)}`)
            .then(res => res.json())
            .then(data => {
                if (isAborted) return;
                rawReports = data;
                updateFundamentalCharts('12Y', rawReports, currentContext);
            })
            .catch(err => console.error('Failed to load fundamentals:', err));
    } else {
        fetch('/api/macro-reports')
            .then(res => res.json())
            .then(data => {
                if (isAborted) return;
                rawReports = data;
                updateMacroCharts(rawReports);
            })
            .catch(err => console.error('Failed to load macro reports:', err));
    }

    // Set up Real-Time Market Stream Listener
    marketUpdateHandler = (event) => {
        const payload = event.detail;
        if (!payload) return;

        if (isEtf) {
            updateMacroIndicators(payload);
            updateEtfPie(payload, currentContext.sharesMap);
        }

        const stockUpdate = payload.stocks ? payload.stocks.find(s => s.ticker === ticker) : null;
        if (stockUpdate) {
            const newPrice = parseFloat(stockUpdate.price);
            try {
                updatePriceUI(newPrice, stockUpdate, currentContext);
            } catch (err) {
                console.error('Error updating price UI:', err);
            }
            try {
                updateLivePricePoint(newPrice);
            } catch (err) {
                console.error('Error updating live chart:', err);
            }
        }

        if (payload.events && payload.events.length > 0) {
            renderEvents(payload.events, ticker);
        }

        if (payload.macro) {
            updateMacroIndicators(payload);
        }
    };
    document.addEventListener('market:update', marketUpdateHandler);

    // Handle Tab Changes for Canvas Resizes
    tabChangeHandler = () => {
        setTimeout(() => {
            resizePriceChart();
            resizeEtfChart();
            resizeMacroCharts();
            resizeFundamentalCharts();
        }, 50);
    };
    document.addEventListener('tabs:changed', tabChangeHandler);
    window.addEventListener('resize', tabChangeHandler);

    setupExpandableCards();
    setupMacroFilters();
    setupFinancialFilters();

    // Turbo cleanup
    document.addEventListener('turbo:before-render', () => {
        isAborted = true;
        cleanupPageResources();
    }, { once: true });
}

function cleanupPageResources() {
    resetPriceHistoryState();
    destroyPriceChart();
    destroyEtfChart();
    destroyMacroCharts();
    destroyFundamentalCharts();

    if (marketUpdateHandler) {
        document.removeEventListener('market:update', marketUpdateHandler);
        marketUpdateHandler = null;
    }
    if (tabChangeHandler) {
        document.removeEventListener('tabs:changed', tabChangeHandler);
        window.removeEventListener('resize', tabChangeHandler);
        tabChangeHandler = null;
    }
}

function setupExpandableCards() {
    document.querySelectorAll('.expand-chart-btn').forEach(btn => {
        btn.onclick = function () {
            const card = this.closest('.chart-card');
            const grid = card.closest('.grid');
            const icon = this.querySelector('.expand-icon');
            const canvasContainer = card.querySelector('.chart-canvas-container');
            const allCards = grid.querySelectorAll('.chart-card');

            const isExpanded = card.classList.contains('md:col-span-2') || card.classList.contains('lg:col-span-2');
            if (isExpanded) {
                card.classList.remove('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.remove('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'open_in_full';
                if (grid.id === 'macroChartsGrid' && typeof window.applyMacroFilters === 'function') {
                    window.applyMacroFilters();
                } else if (grid.id === 'financialChartsGrid' && typeof window.applyFinancialFilters === 'function') {
                    window.applyFinancialFilters();
                } else {
                    allCards.forEach(c => { if (c !== card) c.style.display = ''; });
                }
            } else {
                card.classList.add('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.add('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'close_fullscreen';
                allCards.forEach(c => { if (c !== card) c.style.display = 'none'; });
            }
            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
                resizeMacroCharts();
                resizeFundamentalCharts();
            }, 60);
        };
    });
}

function setupFinancialFilters() {
    let currentCategory = 'all';
    let currentSearch = '';

    window.applyFinancialFilters = function () {
        const query = currentSearch.toLowerCase().trim();
        const cards = document.querySelectorAll('#financialChartsGrid .chart-card');
        cards.forEach(card => {
            const cat = card.dataset.financialCategory || '';
            const text = (card.innerText || '').toLowerCase();
            const matchesCat = (currentCategory === 'all' || cat === currentCategory);
            const matchesSearch = (!query || text.includes(query));
            if (matchesCat && matchesSearch) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
        setTimeout(() => resizeFundamentalCharts(), 50);
    };

    const catButtons = document.querySelectorAll('.financial-cat-btn');
    catButtons.forEach(btn => {
        btn.onclick = () => {
            currentCategory = btn.dataset.category || 'all';
            catButtons.forEach(b => {
                if (b === btn) {
                    b.className = 'financial-cat-btn px-3 py-1.5 text-xs font-bold rounded-lg transition-all whitespace-nowrap bg-primary text-[#001a42] shadow-md shadow-primary/20 cursor-pointer';
                } else {
                    b.className = 'financial-cat-btn px-3 py-1.5 text-xs font-bold rounded-lg transition-all whitespace-nowrap bg-surface-container text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface cursor-pointer';
                }
            });
            window.applyFinancialFilters();
        };
    });

    const searchInput = document.getElementById('financialIndicatorSearch');
    if (searchInput) {
        searchInput.oninput = (e) => {
            currentSearch = e.target.value;
            window.applyFinancialFilters();
        };
    }
}

function setupMacroFilters() {
    let currentCategory = 'all';
    let currentSearch = '';

    window.applyMacroFilters = function () {
        const query = currentSearch.toLowerCase().trim();
        const cards = document.querySelectorAll('#macroChartsGrid .chart-card');
        cards.forEach(card => {
            const cat = card.dataset.macroCategory || '';
            const text = (card.innerText || '').toLowerCase();
            const matchesCat = (currentCategory === 'all' || cat === currentCategory);
            const matchesSearch = (!query || text.includes(query));
            if (matchesCat && matchesSearch) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
        setTimeout(() => resizeMacroCharts(), 50);
    };

    const catButtons = document.querySelectorAll('.macro-cat-btn');
    catButtons.forEach(btn => {
        btn.onclick = () => {
            currentCategory = btn.dataset.category || 'all';
            catButtons.forEach(b => {
                if (b === btn) {
                    b.className = 'macro-cat-btn px-3 py-1.5 text-xs font-bold rounded-lg transition-all whitespace-nowrap bg-primary text-[#001a42] shadow-md shadow-primary/20 cursor-pointer';
                } else {
                    b.className = 'macro-cat-btn px-3 py-1.5 text-xs font-bold rounded-lg transition-all whitespace-nowrap bg-surface-container text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface cursor-pointer';
                }
            });
            window.applyMacroFilters();
        };
    });

    const searchInput = document.getElementById('macroIndicatorSearch');
    if (searchInput) {
        searchInput.oninput = (e) => {
            currentSearch = e.target.value;
            window.applyMacroFilters();
        };
    }

    const rangeButtons = document.querySelectorAll('.macro-range-btn');
    rangeButtons.forEach(btn => {
        btn.onclick = () => {
            const tf = btn.dataset.macroTimeframe;
            if (tf) setMacroTimeframe(tf);
        };
    });
}

// Global hooks
window.updateCharts = function(timeframe) {
    updateFundamentalCharts(timeframe, rawReports, currentContext);
};
window.setMacroTimeframe = setMacroTimeframe;

document.addEventListener('turbo:load', initStockPage);
initStockPage();
