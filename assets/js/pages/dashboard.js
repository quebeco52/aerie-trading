const previousPrices = {};
let previousPortfolioValue = null;
let portfolioChart = null;
let areaSeries = null;
let currentRange = '1m';

function initDashboard() {
    const totalValEl = document.getElementById('portfolio-total-value');
    if (!totalValEl) return;
    if (totalValEl.dataset.initialized) return;
    totalValEl.dataset.initialized = 'true';

    const COLOR_SECONDARY = '#4edea3'; // Positive / Green
    const COLOR_TERTIARY = '#ffb3ad';  // Negative / Red
    const COLOR_PRIMARY = '#adc6ff';

    // --- TAB SWITCHING LOGIC ---
    function setupTabs() {
        const tabButtons = document.querySelectorAll('.portfolio-tab-btn');
        const tabPanels = document.querySelectorAll('.portfolio-tab-panel');

        function activateTab(tabId) {
            tabButtons.forEach(btn => {
                const isActive = btn.dataset.tab === tabId;
                if (isActive) {
                    btn.classList.remove('text-on-surface-variant', 'border-transparent');
                    btn.classList.add('text-primary', 'border-primary');
                } else {
                    btn.classList.remove('text-primary', 'border-primary');
                    btn.classList.add('text-on-surface-variant', 'border-transparent');
                }
            });

            tabPanels.forEach(panel => {
                if (panel.id === `tab-content-${tabId}`) {
                    panel.classList.remove('hidden');
                } else {
                    panel.classList.add('hidden');
                }
            });
        }

        tabButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const tabId = btn.dataset.tab;
                activateTab(tabId);
                if (history.replaceState) {
                    history.replaceState(null, null, `#${tabId}`);
                }
            });
        });

        // Load tab from URL hash if present
        const hash = window.location.hash.replace('#', '');
        if (hash && document.getElementById(`tab-content-${hash}`)) {
            activateTab(hash);
        }
    }

    // --- PERFORMANCE NAV CHART (Lightweight Charts) ---
    function initPortfolioChart() {
        const chartContainer = document.getElementById('portfolioChartContainer');
        if (!chartContainer || typeof LightweightCharts === 'undefined') return;

        // Clear previous chart instance if any
        chartContainer.innerHTML = '';

        portfolioChart = LightweightCharts.createChart(chartContainer, {
            layout: {
                background: { color: 'transparent' },
                textColor: '#c2c6d6',
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
                lineColor: '#adc6ff',
                lineWidth: 2,
                priceFormat: {
                    type: 'price',
                    precision: 2,
                    minMove: 0.01,
                },
            });
        } else if (typeof portfolioChart.addAreaSeries === 'function') {
            areaSeries = portfolioChart.addAreaSeries({
                topColor: 'rgba(173, 198, 255, 0.4)',
                bottomColor: 'rgba(173, 198, 255, 0.01)',
                lineColor: '#adc6ff',
                lineWidth: 2,
                priceFormat: {
                    type: 'price',
                    precision: 2,
                    minMove: 0.01,
                },
            });
        }

        // Resize chart observer
        const resizeObserver = new ResizeObserver(entries => {
            if (entries.length === 0 || !entries[0].contentRect) return;
            const newRect = entries[0].contentRect;
            if (newRect.width > 0 && newRect.height > 0) {
                portfolioChart.applyOptions({ width: newRect.width, height: newRect.height });
            }
        });
        resizeObserver.observe(chartContainer);

        loadPortfolioData(currentRange);

        // Timeframe buttons
        const rangeButtons = document.querySelectorAll('.portfolio-range-btn');
        rangeButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                rangeButtons.forEach(b => {
                    b.classList.remove('bg-primary', 'text-[#001a42]', 'shadow-md', 'shadow-primary/20');
                    b.classList.add('bg-surface-container', 'text-on-surface-variant');
                });
                btn.classList.remove('bg-surface-container', 'text-on-surface-variant');
                btn.classList.add('bg-primary', 'text-[#001a42]', 'shadow-md', 'shadow-primary/20');

                currentRange = btn.dataset.range;
                loadPortfolioData(currentRange);
            });
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

            if (areaSeries && data && data.length > 0) {
                // Format points for Lightweight Charts (time: timestamp in seconds)
                const chartPoints = [];
                let lastTime = 0;

                data.forEach(d => {
                    let t = Math.floor(new Date(d.recorded_at).getTime() / 1000);
                    // Ensure strictly ascending timestamps
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
                portfolioChart.timeScale().fitContent();
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

    // --- REAL-TIME WEBSOCKET MARKET UPDATES ---
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

                // Update Price Element
                const priceEl = document.getElementById(`price-${stock.ticker}`);
                if (priceEl) {
                    priceEl.innerText = '$' + newPrice.toFixed(2);
                    if (newPrice > oldPrice) {
                        priceEl.style.color = COLOR_SECONDARY;
                    } else if (newPrice < oldPrice) {
                        priceEl.style.color = COLOR_TERTIARY;
                    }
                    setTimeout(() => priceEl.style.color = '', 500);
                }

                // Update Value Element
                const valueEl = document.getElementById(`value-${stock.ticker}`);
                if (valueEl) {
                    valueEl.innerText = '$' + holdingValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                // Update PnL Element
                const pnlEl = document.getElementById(`pnl-${stock.ticker}`);
                if (pnlEl) {
                    const sign = unrealizedPnL >= 0 ? '+' : '';
                    pnlEl.className = `px-6 py-4 font-mono text-right font-bold ${unrealizedPnL >= 0 ? 'text-secondary' : 'text-tertiary'}`;
                    pnlEl.innerHTML = `
                        <div>${sign}$${unrealizedPnL.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</div>
                        <div class="text-[10px] font-normal opacity-80">${sign}${unrealizedPnLPct.toFixed(2)}%</div>
                    `;
                }

                previousPrices[stock.ticker] = newPrice;
            }
        });

        // Recalculate Portfolio Total Value & Total P&L
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
                investedEl.innerText = '$' + totalInvested.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            const portfolioValEl = document.getElementById('portfolio-total-value');
            if (portfolioValEl) {
                const oldVal = previousPortfolioValue || totalPortfolioValue;
                portfolioValEl.innerText = '$' + totalPortfolioValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                if (totalPortfolioValue > oldVal) {
                    portfolioValEl.style.color = COLOR_SECONDARY;
                } else if (totalPortfolioValue < oldVal) {
                    portfolioValEl.style.color = COLOR_TERTIARY;
                }
                previousPortfolioValue = totalPortfolioValue;
                setTimeout(() => portfolioValEl.style.color = '', 500);
            }

            // Update PnL Badge in Header
            const pnlValEl = document.getElementById('portfolio-pnl-val');
            const pnlPctEl = document.getElementById('portfolio-pnl-pct');
            const pnlBadge = document.getElementById('portfolio-pnl-badge');

            if (pnlValEl && pnlPctEl && pnlBadge) {
                const sign = totalPnL >= 0 ? '+' : '';
                pnlValEl.innerText = `${sign}$${totalPnL.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                pnlPctEl.innerText = `(${sign}${totalPnLPct.toFixed(2)}%)`;

                if (totalPnL >= 0) {
                    pnlBadge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold font-mono bg-secondary/10 text-secondary border border-secondary/20';
                } else {
                    pnlBadge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold font-mono bg-tertiary/10 text-tertiary border border-tertiary/20';
                }
            }
        }
    }

    setupTabs();
    initPortfolioChart();

    document.addEventListener('market:update', onMarketUpdate);

    // Clean up when navigating via Turbo
    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:update', onMarketUpdate);
        if (portfolioChart) {
            portfolioChart.remove();
            portfolioChart = null;
        }
    }, { once: true });
}

document.addEventListener('turbo:load', initDashboard);
initDashboard();