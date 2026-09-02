import { THEME_COLORS } from '../utils/colors.js';
import { formatCurrency } from '../utils/formatters.js';

const previousPrices = {};
let previousPortfolioValue = null;
let portfolioChart = null;
let areaSeries = null;
let currentRange = '1m';
let chartResizeObserver = null;

function initDashboard() {
    const totalValEl = document.getElementById('portfolio-total-value');
    if (!totalValEl) return;
    if (totalValEl.dataset.initialized) return;
    totalValEl.dataset.initialized = 'true';

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
                fontFamily: '"Courier Prime", monospace',
            },
            grid: {
                vertLines: { color: 'rgba(66, 71, 84, 0.2)' },
                horzLines: { color: 'rgba(66, 71, 84, 0.2)' },
            },
            crosshair: {
                mode: LightweightCharts.CrosshairMode.Normal,
                vertLine: { color: 'rgba(173, 198, 255, 0.4)', labelBackgroundColor: '#171f33' },
                horzLine: { color: 'rgba(173, 198, 255, 0.4)', labelBackgroundColor: '#171f33' },
            },
            rightPriceScale: {
                borderColor: 'rgba(66, 71, 84, 0.3)',
                scaleMargins: { top: 0.1, bottom: 0.1 },
            },
            timeScale: {
                borderColor: 'rgba(66, 71, 84, 0.3)',
                timeVisible: true,
                secondsVisible: false,
            },
            handleScroll: true,
            handleScale: true,
        });

        if (typeof portfolioChart.addSeries === 'function' && LightweightCharts.AreaSeries) {
            areaSeries = portfolioChart.addSeries(LightweightCharts.AreaSeries, {
                topColor: 'rgba(173, 198, 255, 0.4)',
                bottomColor: 'rgba(173, 198, 255, 0.01)',
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
                rangeButtons.forEach(b => {
                    b.classList.remove('bg-primary', 'text-[#001a42]', 'shadow-md', 'shadow-primary/20');
                    b.classList.add('bg-surface-container', 'text-on-surface-variant');
                });
                btn.classList.remove('bg-surface-container', 'text-on-surface-variant');
                btn.classList.add('bg-primary', 'text-[#001a42]', 'shadow-md', 'shadow-primary/20');

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

    function onMarketUpdate(event) {
        const payload = event.detail;
        if (!payload || !payload.stocks) return;

        let hasHoldingsUpdates = false;

        payload.stocks.forEach(stock => {
            if (window.LIVE_PRICES) {
                window.LIVE_PRICES[stock.ticker] = parseFloat(stock.price);
            }

            const quantity = window.PORTFOLIO_HOLDINGS ? window.PORTFOLIO_HOLDINGS[stock.ticker] : null;

            if (quantity && quantity > 0) {
                hasHoldingsUpdates = true;
                const newPrice = parseFloat(stock.price);
                const oldPrice = previousPrices[stock.ticker] || newPrice;
                const holdingValue = newPrice * quantity;
                const avgCost = window.HOLDING_AVG_COSTS ? (window.HOLDING_AVG_COSTS[stock.ticker] || newPrice) : newPrice;
                const totalCost = avgCost * quantity;
                const unrealizedPnL = holdingValue - totalCost;
                const unrealizedPnLPct = totalCost > 0 ? (unrealizedPnL / totalCost) * 100 : 0.0;

                const priceEl = document.getElementById(`price-${stock.ticker}`);
                if (priceEl) {
                    priceEl.innerText = '$' + newPrice.toFixed(2);
                    if (newPrice > oldPrice) {
                        priceEl.style.color = THEME_COLORS.secondary;
                    } else if (newPrice < oldPrice) {
                        priceEl.style.color = THEME_COLORS.tertiary;
                    }
                    setTimeout(() => { if (priceEl) priceEl.style.color = ''; }, 500);
                }

                const valueEl = document.getElementById(`value-${stock.ticker}`);
                if (valueEl) {
                    valueEl.innerText = formatCurrency(holdingValue);
                }

                const pnlEl = document.getElementById(`pnl-${stock.ticker}`);
                if (pnlEl) {
                    const sign = unrealizedPnL >= 0 ? '+' : '';
                    pnlEl.className = `px-6 py-4 font-mono text-right font-bold ${unrealizedPnL >= 0 ? 'text-secondary' : 'text-tertiary'}`;
                    pnlEl.innerHTML = `
                        <div>${sign}${formatCurrency(unrealizedPnL)}</div>
                        <div class="text-[10px] font-normal opacity-80">${sign}${unrealizedPnLPct.toFixed(2)}%</div>
                    `;
                }

                previousPrices[stock.ticker] = newPrice;
            }
        });

        if (hasHoldingsUpdates && window.PORTFOLIO_HOLDINGS) {
            let totalInvested = 0;
            let totalInvestedCost = 0;

            for (const [ticker, quantity] of Object.entries(window.PORTFOLIO_HOLDINGS)) {
                const currentPrice = window.LIVE_PRICES ? (window.LIVE_PRICES[ticker] || 0) : 0;
                const avgCost = window.HOLDING_AVG_COSTS ? (window.HOLDING_AVG_COSTS[ticker] || currentPrice) : currentPrice;

                totalInvested += (currentPrice * quantity);
                totalInvestedCost += (avgCost * quantity);
            }

            const totalPortfolioValue = totalInvested + (window.USER_CASH || 0);
            const totalPnL = totalInvested - totalInvestedCost;
            const totalPnLPct = totalInvestedCost > 0 ? (totalPnL / totalInvestedCost) * 100 : 0.0;

            const investedEl = document.getElementById('total-invested');
            if (investedEl) {
                investedEl.innerText = formatCurrency(totalInvested);
            }

            const portfolioValEl = document.getElementById('portfolio-total-value');
            if (portfolioValEl) {
                const oldVal = previousPortfolioValue || totalPortfolioValue;
                portfolioValEl.innerText = formatCurrency(totalPortfolioValue);

                if (totalPortfolioValue > oldVal) {
                    portfolioValEl.style.color = THEME_COLORS.secondary;
                } else if (totalPortfolioValue < oldVal) {
                    portfolioValEl.style.color = THEME_COLORS.tertiary;
                }
                previousPortfolioValue = totalPortfolioValue;
                setTimeout(() => { if (portfolioValEl) portfolioValEl.style.color = ''; }, 500);
            }

            const pnlValEl = document.getElementById('portfolio-pnl-val');
            const pnlPctEl = document.getElementById('portfolio-pnl-pct');
            const pnlBadge = document.getElementById('portfolio-pnl-badge');

            if (pnlValEl && pnlPctEl && pnlBadge) {
                const sign = totalPnL >= 0 ? '+' : '';
                pnlValEl.innerText = `${sign}${formatCurrency(totalPnL)}`;
                pnlPctEl.innerText = `(${sign}${totalPnLPct.toFixed(2)}%)`;

                if (totalPnL >= 0) {
                    pnlBadge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold font-mono bg-secondary/10 text-secondary border border-secondary/20';
                } else {
                    pnlBadge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold font-mono bg-tertiary/10 text-tertiary border border-tertiary/20';
                }
            }
        }
    }

    initPortfolioChart();
    document.addEventListener('market:update', onMarketUpdate);

    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:update', onMarketUpdate);
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

document.addEventListener('turbo:load', initDashboard);
initDashboard();