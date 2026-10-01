import { THEME_COLORS, withAlpha } from '../utils/colors.js';
import { formatCurrency } from '../utils/formatters.js';
import { CHART_FONT_MONO } from '../utils/fonts.js';
import { readPageData } from '../utils/page-data.js';
import { flashTick } from '../utils/tick-flash.js';
import { setText } from '../utils/set-text.js';
import { onPageLoad } from '../utils/page-init.js';

const previousPrices = {};
let previousPortfolioValue = null;

/**
 * Server-rendered portfolio state: `holdings` maps ticker to { quantity, avgCost, price }, and
 * `cash` is the settled balance. Re-read on every page init, since Turbo keeps this module
 * loaded across navigations while the payload underneath it changes.
 */
let holdings = {};
let cash = 0;

/** Latest quote per ticker — seeded from the server's holdings, then advanced by the stream. */
let livePrices = {};
let portfolioChart = null;
let areaSeries = null;
let currentRange = '1m';
let chartResizeObserver = null;

function initDashboard() {
    const totalValEl = document.getElementById('portfolio-total-value');
    if (!totalValEl) return;
    if (totalValEl.dataset.initialized) return;
    totalValEl.dataset.initialized = 'true';

    const pageData = readPageData('portfolio-data');
    // An empty holdings map serialises as a JSON array, so normalise before use.
    holdings = (pageData.holdings && !Array.isArray(pageData.holdings)) ? pageData.holdings : {};
    cash = Number(pageData.cash) || 0;
    livePrices = {};
    for (const [ticker, holding] of Object.entries(holdings)) {
        livePrices[ticker] = Number(holding.price) || 0;
    }

    function initPortfolioChart() {
        const chartContainer = document.getElementById('portfolioChartContainer');
        if (!chartContainer || typeof LightweightCharts === 'undefined') return;

        if (portfolioChart) {
            try { portfolioChart.remove(); } catch (e) {}
            portfolioChart = null;
        }
        if (chartResizeObserver) {
            try { chartResizeObserver.disconnect(); } catch (e) {}
            chartResizeObserver = null;
        }

        chartContainer.innerHTML = '';

        portfolioChart = LightweightCharts.createChart(chartContainer, {
            layout: {
                background: { color: 'transparent' },
                textColor: THEME_COLORS.textMuted,
                fontFamily: CHART_FONT_MONO,
            },
            grid: {
                vertLines: { color: withAlpha(THEME_COLORS.grid, 0.35) },
                horzLines: { color: withAlpha(THEME_COLORS.grid, 0.35) },
            },
            crosshair: {
                mode: LightweightCharts.CrosshairMode.Normal,
                vertLine: { color: withAlpha(THEME_COLORS.primary, 0.4), labelBackgroundColor: THEME_COLORS.surfaceRaised },
                horzLine: { color: withAlpha(THEME_COLORS.primary, 0.4), labelBackgroundColor: THEME_COLORS.surfaceRaised },
            },
            rightPriceScale: {
                borderColor: THEME_COLORS.border,
                scaleMargins: { top: 0.1, bottom: 0.1 },
            },
            timeScale: {
                borderColor: THEME_COLORS.border,
                timeVisible: true,
                secondsVisible: false,
            },
            handleScroll: true,
            handleScale: true,
        });

        if (typeof portfolioChart.addSeries === 'function' && LightweightCharts.AreaSeries) {
            areaSeries = portfolioChart.addSeries(LightweightCharts.AreaSeries, {
                topColor: withAlpha(THEME_COLORS.primary, 0.3),
                bottomColor: withAlpha(THEME_COLORS.primary, 0),
                lineColor: THEME_COLORS.primary,
                lineWidth: 2,
                priceFormat: {
                    type: 'price',
                    precision: 2,
                    minMove: 0.01,
                },
            });
        }

        chartResizeObserver = new ResizeObserver(entries => {
            if (entries.length === 0 || !entries[0].contentRect) return;
            const newRect = entries[0].contentRect;
            if (newRect.width > 0 && newRect.height > 0 && portfolioChart) {
                portfolioChart.applyOptions({ width: newRect.width, height: newRect.height });
            }
        });
        chartResizeObserver.observe(chartContainer);

        loadPortfolioData(currentRange);

        const rangeButtons = document.querySelectorAll('.portfolio-range-btn');
        rangeButtons.forEach(btn => {
            btn.onclick = () => {
                rangeButtons.forEach(b => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));

                currentRange = btn.dataset.range;
                loadPortfolioData(currentRange);
            };
        });
    }

    async function loadPortfolioData(range) {
        const spinner = document.getElementById('portfolio-chart-spinner');
        if (spinner) {
            spinner.classList.remove('hidden');
            spinner.classList.add('flex');
        }

        try {
            const res = await fetch(`/api/portfolio/history?range=${encodeURIComponent(range)}`);
            if (!res.ok) throw new Error('Failed to fetch portfolio history');
            const data = await res.json();

            if (areaSeries && Array.isArray(data) && data.length > 0) {
                const chartPoints = [];
                let lastTime = 0;

                data.forEach(d => {
                    let t = Math.floor(new Date(d.recorded_at).getTime() / 1000);
                    if (t <= lastTime) {
                        t = lastTime + 1;
                    }
                    lastTime = t;

                    chartPoints.push({
                        time: t,
                        value: parseFloat(d.price),
                    });
                });

                areaSeries.setData(chartPoints);
                if (portfolioChart) portfolioChart.timeScale().fitContent();
            }
        } catch (e) {
            console.error('Error loading portfolio chart data:', e);
        } finally {
            if (spinner) {
                spinner.classList.add('hidden');
                spinner.classList.remove('flex');
            }
        }
    }

    /** One coalesced frame from market-stream.js (`market:frame`) — see the home page for the contract. */
    function onMarketFrame(event) {
        const payload = event.detail;
        if (!payload || !payload.stocks) return;

        let hasHoldingsUpdates = false;

        payload.stocks.forEach(stock => {
            livePrices[stock.ticker] = parseFloat(stock.price);

            const quantity = holdings[stock.ticker]?.quantity ?? null;

            if (quantity && quantity > 0) {
                hasHoldingsUpdates = true;
                const newPrice = parseFloat(stock.price);
                const oldPrice = previousPrices[stock.ticker] || newPrice;
                const holdingValue = newPrice * quantity;
                const avgCost = Number(holdings[stock.ticker]?.avgCost) || newPrice;
                const totalCost = avgCost * quantity;
                const unrealizedPnL = holdingValue - totalCost;
                const unrealizedPnLPct = totalCost > 0 ? (unrealizedPnL / totalCost) * 100 : 0.0;

                const priceEl = document.getElementById(`price-${stock.ticker}`);
                if (priceEl) {
                    setText(priceEl, '$' + newPrice.toFixed(2));
                    flashTick(priceEl, newPrice - oldPrice);
                }

                const valueEl = document.getElementById(`value-${stock.ticker}`);
                setText(valueEl, formatCurrency(holdingValue));

                // The cell is server-rendered as a value line and a percent line (plus an
                // optional borrow line for a short); the two figures are written into those
                // lines in place. Rebuilding the cell's markup here used to drop the borrow line.
                const pnlEl = document.getElementById(`pnl-${stock.ticker}`);
                if (pnlEl) {
                    const sign = unrealizedPnL >= 0 ? '+' : '';
                    const tone = unrealizedPnL >= 0 ? 'text-secondary' : 'text-tertiary';
                    if (!pnlEl.classList.contains(tone)) {
                        pnlEl.classList.remove('text-secondary', 'text-tertiary');
                        pnlEl.classList.add(tone);
                    }
                    const lines = pnlEl.children;
                    setText(lines[0], `${sign}${formatCurrency(unrealizedPnL)}`);
                    setText(lines[1], `(${sign}${unrealizedPnLPct.toFixed(2)}%)`);
                }

                previousPrices[stock.ticker] = newPrice;
            }
        });

        if (hasHoldingsUpdates) {
            let totalInvested = 0;
            let totalInvestedCost = 0;

            for (const [ticker, holding] of Object.entries(holdings)) {
                const quantity = holding.quantity;
                const currentPrice = livePrices[ticker] || 0;
                const avgCost = Number(holding.avgCost) || currentPrice;

                totalInvested += (currentPrice * quantity);
                totalInvestedCost += (avgCost * quantity);
            }

            const totalPortfolioValue = totalInvested + cash;
            const totalPnL = totalInvested - totalInvestedCost;
            const totalPnLPct = totalInvestedCost > 0 ? (totalPnL / totalInvestedCost) * 100 : 0.0;

            setText(document.getElementById('total-invested'), formatCurrency(totalInvested));

            const portfolioValEl = document.getElementById('portfolio-total-value');
            if (portfolioValEl) {
                const oldVal = previousPortfolioValue || totalPortfolioValue;
                setText(portfolioValEl, formatCurrency(totalPortfolioValue));

                flashTick(portfolioValEl, totalPortfolioValue - oldVal);
                previousPortfolioValue = totalPortfolioValue;
            }

            const pnlValEl = document.getElementById('portfolio-pnl-val');
            const pnlPctEl = document.getElementById('portfolio-pnl-pct');
            const pnlBadge = document.getElementById('portfolio-pnl-badge');

            if (pnlValEl && pnlPctEl && pnlBadge) {
                const sign = totalPnL >= 0 ? '+' : '';
                setText(pnlValEl, `${sign}${formatCurrency(totalPnL)}`);
                setText(pnlPctEl, `(${sign}${totalPnLPct.toFixed(2)}%)`);

                pnlBadge.classList.toggle('text-secondary', totalPnL >= 0);
                pnlBadge.classList.toggle('text-tertiary', totalPnL < 0);
            }
        }
    }

    initPortfolioChart();
    document.addEventListener('market:frame', onMarketFrame);

    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:frame', onMarketFrame);
        if (chartResizeObserver) {
            chartResizeObserver.disconnect();
            chartResizeObserver = null;
        }
        if (portfolioChart) {
            portfolioChart.remove();
            portfolioChart = null;
        }
    }, { once: true });
}


/* `onPageLoad`, not a bare `turbo:load` listener: on a Turbo navigation this module is
   fetched asynchronously and can evaluate after that page's `turbo:load` has already
   fired. See utils/page-init.js. */
onPageLoad(initDashboard);
