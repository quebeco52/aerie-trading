const previousPrices = {};
let previousPortfolioValue = null;

function initDashboard() {
    const totalValElInit = document.getElementById('portfolio-total-value');
    if (!totalValElInit) return;
    if (totalValElInit.dataset.initialized) return;
    totalValElInit.dataset.initialized = 'true';

    const COLOR_SECONDARY = '#4edea3'; // Positive / Green
    const COLOR_TERTIARY = '#ffb3ad';  // Negative / Red

    function onMarketUpdate(event) {
        const payload = event.detail;
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
                const portfolioValEl = document.getElementById('portfolio-total-value');

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
            }
        }
    }

    document.addEventListener('market:update', onMarketUpdate);

    // Clean up when leaving the page to prevent ghost DOM errors
    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:update', onMarketUpdate);
    }, { once: true });
}

document.addEventListener('turbo:load', initDashboard);
initDashboard();