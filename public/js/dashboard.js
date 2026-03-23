const previousPrices = {};
let previousPortfolioValue = null;

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Portfolio Chart
    const canvas = document.getElementById('portfolioChart');
    let portfolioChart = null;

    const COLOR_SECONDARY = '#4edea3'; // Positive / Green
    const COLOR_TERTIARY = '#ffb3ad';  // Negative / Red
    const COLOR_GRID = '#2d3449';

    if (canvas && window.PORTFOLIO_HISTORY && window.PORTFOLIO_HISTORY.length > 0) {
        const ctx = canvas.getContext('2d');

        // Create a smooth gradient matching the style of the stock page
        const gradient = ctx.createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, 'rgba(78, 222, 163, 0.2)');
        gradient.addColorStop(1, 'rgba(78, 222, 163, 0)');

        portfolioChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: window.PORTFOLIO_HISTORY.map(h => h.time),
                datasets: [{
                    label: 'Portfolio Value',
                    data: window.PORTFOLIO_HISTORY.map(h => h.value),
                    borderColor: COLOR_SECONDARY,
                    backgroundColor: gradient,
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 0,
                    fill: true,
                    spanGaps: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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
                            font: { family: '"Courier Prime", monospace' },
                            callback: value => '$' + value.toLocaleString()
                        }
                    }
                }
            }
        });
    }

    // Live Market Data via WebSockets
    const marketSocket = new WebSocket('ws://127.0.0.1:8080');

    let tickCounter = 0;

    marketSocket.onmessage = function (event) {
        const payload = JSON.parse(event.data);
        let hasHoldingsUpdates = false;

        if (payload.stocks && window.PORTFOLIO_HOLDINGS) {
            payload.stocks.forEach(stock => {
                // Keep a running map of the latest price of every asset
                if (window.LIVE_PRICES) {
                    window.LIVE_PRICES[stock.ticker] = parseFloat(stock.price);
                }

                const quantity = window.PORTFOLIO_HOLDINGS[stock.ticker];

                if (quantity) {
                    hasHoldingsUpdates = true;
                    const newPrice = parseFloat(stock.price);
                    const oldPrice = previousPrices[stock.ticker] || newPrice;
                    const holdingValue = newPrice * quantity;

                    // Update DOM elements if they exist
                    const priceEl = document.getElementById(`price-${stock.ticker}`);
                    const valueEl = document.getElementById(`value-${stock.ticker}`);

                    if (priceEl && valueEl) {

                        priceEl.innerText = '$' + newPrice.toFixed(2);
                        valueEl.innerText = '$' + holdingValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                        // Flash colors based on price movement
                        if (newPrice > oldPrice) {
                            priceEl.style.color = COLOR_SECONDARY;
                        } else if (newPrice < oldPrice) {
                            priceEl.style.color = COLOR_TERTIARY;
                        }
                        previousPrices[stock.ticker] = newPrice;

                        setTimeout(() => priceEl.style.color = '', 500);
                    }
                }
            });

            // If any of the user's holdings updated, recalculate the total portfolio value
            if (hasHoldingsUpdates) {
                // 1. Recalculate Totals
                let totalInvested = 0;
                for (const [ticker, quantity] of Object.entries(window.PORTFOLIO_HOLDINGS)) {
                    const currentPrice = window.LIVE_PRICES[ticker] || 0;
                    totalInvested += (currentPrice * quantity);
                }

                const totalPortfolioValue = totalInvested + window.USER_CASH;

                // Update Total Invested & Portfolio Value DOM
                const investedEl = document.getElementById('total-invested');
                const portfolioValEl = document.getElementById('portfolio-value');

                if (investedEl) {
                    investedEl.innerText = '$' + totalInvested.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                if (portfolioValEl) {
                    const oldPortVal = previousPortfolioValue || totalPortfolioValue;
                    portfolioValEl.innerText = '$' + totalPortfolioValue.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                    // Flash the main hero text color dynamically
                    if (totalPortfolioValue > oldPortVal) {
                        portfolioValEl.style.color = COLOR_SECONDARY;
                    } else if (totalPortfolioValue < oldPortVal) {
                        portfolioValEl.style.color = COLOR_TERTIARY;
                    }
                    previousPortfolioValue = totalPortfolioValue;
                    setTimeout(() => portfolioValEl.style.color = '', 500);
                }

                // Update the Portfolio Chart Line dynamically
                if (portfolioChart && document.visibilityState === 'visible') {
                    // Instead of adding new points every 3 ticks, we just update the 
                    // VERY LAST historical point to reflect the live, to-the-second value.
                    const lastIndex = portfolioChart.data.datasets[0].data.length - 1;
                    
                    if (lastIndex >= 0) {
                        portfolioChart.data.datasets[0].data[lastIndex] = totalPortfolioValue;
                        portfolioChart.update('none'); // Update without full animation redraw
                    }
                }
            }
        }
    };
});