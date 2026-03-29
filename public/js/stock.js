let previousPrice = null;

const CURRENT_TICKER = window.AERIE_DATA.ticker;
const IS_ETF = window.AERIE_DATA.isEtf;
const SHARES_OUTSTANDING = window.AERIE_DATA.sharesOutstanding;
const USER_QUANTITY = window.AERIE_DATA.userQuantity;
const EPS = window.AERIE_DATA.eps;

const pieLabels = window.AERIE_DATA.pieLabels;
const pieData = window.AERIE_DATA.pieData;
const sharesMap = window.AERIE_DATA.sharesMap;

const TICKS_PER_YEAR = window.AERIE_DATA.ticksPerYear || 14400;
const TICKS_PER_MONTH = Math.ceil(TICKS_PER_YEAR / 12);
const TICKS_PER_WEEK = Math.ceil(TICKS_PER_YEAR / 52);

const COLOR_PRIMARY = '#adc6ff';
const COLOR_SECONDARY = '#4edea3'; // Positive
const COLOR_TERTIary = '#ffb3ad';  // Negative
const COLOR_GRID = '#2d3449';

let etfComponents = [];
let fallbackIndex = 0;

if (IS_ETF) {
    for (let i = 0; i < pieLabels.length; i++) {
        const currentTicker = pieLabels[i];

        let sliceColor = BRAND_COLORS[currentTicker];
        if (!sliceColor) {
            sliceColor = FALLBACK_PALETTE[fallbackIndex % FALLBACK_PALETTE.length];
            fallbackIndex++;
        }

        etfComponents.push({
            ticker: currentTicker,
            value: pieData[i],
            color: sliceColor
        });
    }
    // Initial Sort: Largest to smallest
    etfComponents.sort((a, b) => b.value - a.value);
}

document.addEventListener('DOMContentLoaded', () => {

    if (!window.WS_TICKET || window.WS_TICKET === "") {
        console.log("Guest mode: Live WebSocket updates disabled.");
        return; 
    }

    // Automatically use WSS (Secure) if on HTTPS, and detect the current domain
    const protocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
    const host = window.location.host;

    // Connect to the Caddy reverse proxy endpoint
    const marketSocket = new WebSocket(`${protocol}${host}/ws/?ticket=${window.WS_TICKET}`);

    // Init Main Chart
    const ctx = document.getElementById('mainChart').getContext('2d');
    
    // Create a smooth gradient
    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(78, 222, 163, 0.2)'); // Secondary at 20%
    gradient.addColorStop(1, 'rgba(78, 222, 163, 0)');

    const mainChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Price',
                data: [],
                borderColor: COLOR_SECONDARY,
                backgroundColor: gradient,
                borderWidth: 2,
                tension: 0.4,
                pointRadius: 0,
                fill: true,
                normalized: true,
                spanGaps: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: { legend: { display: false } },
            scales: {
                x: { display: false },
                y: {
                    display: true,
                    position: 'right',
                    grid: {
                        color: COLOR_GRID,
                        borderDash: [5, 5]
                    },
                    ticks: {
                        color: '#c2c6d6',
                        font: {
                            family: '"Courier Prime", monospace'
                        }
                    }
                }
            }
        }
    });

    // Init ETF Pie Chart
    let etfPieChart = null;
    if (IS_ETF) {
        const pieCtx = document.getElementById('etfPieChart').getContext('2d');
        etfPieChart = new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: etfComponents.map(c => c.ticker), // Sorted labels
                datasets: [{
                    data: etfComponents.map(c => c.value), // Sorted data
                    backgroundColor: etfComponents.map(c => c.color), // Locked colors
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%', // Thinner, more modern ring
                onHover: (event, chartElement) => {
                    event.native.target.style.cursor = chartElement.length ? 'pointer' : 'default';
                },
                onClick: (evt, elements) => {
                    if (elements.length > 0) {
                        const index = elements[0].index;
                        const ticker = etfPieChart.data.labels[index];
                        if (ticker) {
                            window.location.href = '/stock/' + ticker;
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 8,
                            usePointStyle: true,
                            color: '#c2c6d6',
                            font: {
                                family: '"Courier Prime", monospace',
                                size: 10
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(19, 27, 46, 0.9)',
                        titleColor: '#dae2fd',
                        bodyColor: '#c2c6d6',
                        borderColor: '#424754',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function (context) {
                                let value = context.raw;
                                let total = context.chart._metasets[context.datasetIndex].total;
                                let percentage = ((value / total) * 100).toFixed(1) + "%";
                                return ' ' + context.label + ': ' + percentage;
                            }
                        }
                    }
                }
            }
        });
    }

    // Dynamic Range Logic
    let currentRange = '1y';
    let tickCounter = 0;

    // How many live ticks to wait before locking a permanent point into the chart
    const rangeSteps = {
        '1w': 1, // Draw every tick
        '1m': 1, // Draw every tick
        '3m': Math.max(1, Math.floor((TICKS_PER_MONTH * 3) / 1200)),
        '6m': Math.max(1, Math.floor((TICKS_PER_MONTH * 6) / 2400)),
        '1y': Math.max(1, Math.floor(TICKS_PER_YEAR / 4800)),
        '3y': Math.max(1, Math.floor((TICKS_PER_YEAR * 3) / 5000)),
        '5y': Math.max(1, Math.floor((TICKS_PER_YEAR * 5) / 5000)),
        '10y': Math.max(1, Math.floor((TICKS_PER_YEAR * 10) / 5000)),
        'max': Math.max(1, Math.floor((TICKS_PER_YEAR * 20) / 5000))
    };

    // How many points the chart is allowed to hold before deleting the oldest one
    const rangeLimits = {
        '1w': TICKS_PER_WEEK,
        '1m': TICKS_PER_MONTH,
        '3m': 1200, 
        '6m': 2400,
        '1y': 4800,
        '3y': 5000,
        '5y': 5000,
        '10y': 5000,
        'max': 5000
    };
    
    let currentLimit = rangeLimits['1y'];

    function loadHistory(range) {
        currentRange = range;
        currentLimit = rangeLimits[range];
        tickCounter = 0;

        // Show the spinner
        const spinner = document.getElementById('chart-spinner');
        if (spinner) spinner.classList.remove('hidden');

        // Update button active states
        document.querySelectorAll('.range-btn').forEach(btn => {
            if (btn.dataset.range === range) {
                btn.className = 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-primary text-[#001a42] shadow-lg shadow-primary/20 transition-colors';
            } else {
                btn.className = 'range-btn px-4 py-1.5 text-xs font-bold rounded-md bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors';
            }
        });

        // Fetch the data
        fetch('/api/history?ticker=' + CURRENT_TICKER + '&range=' + range)
            .then(res => res.json())
            .then(data => {
                if (data && data.length > 0) {
                    mainChart.data.labels = data.map(d => d.recorded_at.split(' ')[1]);
                    mainChart.data.datasets[0].data = data.map(d => parseFloat(d.price));
                    mainChart.update();
                }
            })
            .catch(err => {
                console.error("Failed to load history:", err);
            })
            .finally(() => {
                // Hide the spinner when finished
                if (spinner) spinner.classList.add('hidden');
            });
    }

    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', (e) => loadHistory(e.target.dataset.range));
    });

    // Load default history
    loadHistory('1y');

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            loadHistory(currentRange);
        }
    });

    // Live WebSocket Feed

    marketSocket.onmessage = function (event) {
        const payload = JSON.parse(event.data);

        if (IS_ETF && payload.market_vol) {
            const vixEl = document.getElementById('district-vix');
            if (vixEl) {
                const currentVix = (payload.market_vol * 100).toFixed(2);
                vixEl.innerText = currentVix + '%';
                
                // If volatility spikes above 30%, flash the text red to warn the user!
                if (payload.market_vol > 0.30) {
                    vixEl.style.color = COLOR_TERTIary; // Matches your red variable
                } else {
                    vixEl.style.color = '#dae2fd'; // Default text-on-surface color
                }
            }
        }
        
        const stockUpdate = payload.stocks.find(s => s.ticker === CURRENT_TICKER);

        if (stockUpdate) {
            const newPrice = parseFloat(stockUpdate.price);
            const el = document.getElementById('big-price');
            const oldPrice = previousPrice || newPrice;

            el.innerText = '$' + newPrice.toFixed(2);
            if (newPrice > oldPrice) {
                el.style.color = COLOR_SECONDARY; // Green
            } else if (newPrice < oldPrice) {
                el.style.color = COLOR_TERTIary; // Red
            }
            previousPrice = newPrice;
            setTimeout(() => el.style.color = '#dae2fd', 500);

            if (USER_QUANTITY > 0) {
                document.getElementById('user-holding-value').innerText = '$' + (newPrice * USER_QUANTITY).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            }

            if (!IS_ETF) {
                const newMarketCap = newPrice * SHARES_OUTSTANDING;
                const newPeRatio = EPS > 0 ? (newPrice / EPS) : 0;
                document.getElementById('stat-mkt-cap').innerText = '$' + (newMarketCap / 1000000000).toFixed(2) + 'B';
                document.getElementById('stat-pe').innerText = newPeRatio.toFixed(2);

                if (stockUpdate.current_volatility !== undefined) {
                    document.getElementById('stat-volatility').innerText = stockUpdate.current_volatility.toFixed(2) + '%';
                }

                if (payload.sectors && payload.sectors[stockUpdate.sector]) {
                    document.getElementById('live-target-pe').innerText = parseFloat(payload.sectors[stockUpdate.sector]).toFixed(2);
                }
            } else if (IS_ETF && etfPieChart) {

                // Update our structured objects with new live values
                let updated = false;
                payload.stocks.forEach(stock => {
                    let comp = etfComponents.find(c => c.ticker === stock.ticker);
                    if (comp) {
                        comp.value = parseFloat(stock.price) * sharesMap[stock.ticker];
                        updated = true;
                    }
                });

                if (updated) {
                    // Re-sort the slices dynamically without losing their locked color
                    etfComponents.sort((a, b) => b.value - a.value);

                    // Re-apply the sorted arrays to the chart
                    etfPieChart.data.labels = etfComponents.map(c => c.ticker);
                    etfPieChart.data.datasets[0].data = etfComponents.map(c => c.value);
                    etfPieChart.data.datasets[0].backgroundColor = etfComponents.map(c => c.color);

                    etfPieChart.update('none');
                }
            }

            if (document.visibilityState === 'visible') {
                tickCounter++;
                const now = new Date();
                const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0') + ':' + now.getSeconds().toString().padStart(2, '0');

                if (tickCounter >= rangeSteps[currentRange]) {
                    mainChart.data.labels.push(timeStr);
                    mainChart.data.datasets[0].data.push(newPrice);
                    tickCounter = 0;

                    if (mainChart.data.labels.length > currentLimit) {
                        mainChart.data.labels.shift();
                        mainChart.data.datasets[0].data.shift();
                    }
                } else {
                    mainChart.data.labels[mainChart.data.labels.length - 1] = timeStr;
                    mainChart.data.datasets[0].data[mainChart.data.datasets[0].data.length - 1] = newPrice;
                }

                mainChart.update('none');
            }
        }

        if (payload.events && payload.events.length > 0) {
            payload.events.forEach(evt => {

                // Only show the event if it belongs to the stock we are currently looking at
                if (evt.ticker === CURRENT_TICKER) {
                    const noMsg = document.getElementById('no-events-msg');
                    const list = document.getElementById('events-list');

                    if (noMsg) noMsg.classList.add('hidden');

                    const isPositive = parseFloat(evt.change_percent) >= 0;
                    
                    // Match the material symbols from the Twig template
                    let icon = evt.type === 'SHOCK' ? 'bolt' : 'campaign';
                    if (evt.type === 'SPLIT' || evt.type === 'REVSPLIT') icon = 'content_cut';

                    // 1. Get the raw description text
                    let rawDesc = evt.description || (evt.type === 'SHOCK' ? 'Sudden market shock detected.' : 'Earnings report released.');
                    
                    // 2. Escape HTML characters to prevent Cross-Site Scripting (XSS)
                    let safeDesc = String(rawDesc).replace(/[&<>"']/g, match => {
                        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[match];
                    });

                    // 3. Safely convert newlines to <br> tags AFTER escaping
                    let desc = safeDesc.replace(/\n/g, '<br>');

                    // Event Colors
                    const iconBg = isPositive ? 'bg-secondary/10 text-secondary' : 'bg-tertiary/10 text-tertiary';
                    const pctColor = isPositive ? 'text-secondary' : 'text-tertiary';
                    const sign = isPositive ? '+' : '';
                    const pct = parseFloat(evt.change_percent).toFixed(2);

                    const now = new Date();
                    const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');

                    const li = document.createElement('li');
                    li.className = 'py-3';
                    li.innerHTML = `
                    <div class="flex items-start justify-between">
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 ${iconBg}">
                                <span class="material-symbols-outlined text-sm">${icon}</span>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-on-surface">${evt.type}</p>
                                <p class="text-[11px] text-on-surface-variant mt-1 leading-relaxed max-w-[200px]">
                                    ${desc}
                                </p>
                            </div>
                        </div>
                        <div class="text-right flex-shrink-0 ml-2"> 
                            <span class="inline-flex items-center rounded bg-transparent px-1 py-0.5 text-xs font-bold ${pctColor}">
                                ${sign}${pct}%
                            </span>
                            <p class="text-[10px] text-on-surface-variant mt-1">${timeStr}</p>
                        </div>
                    </div>
                    `;

                    if (list) {
                        list.prepend(li);
                        if (list.children.length > 10) {
                            list.removeChild(list.lastChild);
                        }
                    }
                }
            });
        }
    };
});