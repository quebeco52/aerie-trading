// Removed import
const previousPrices = {};

// Add the formatter at the top of the file
function formatLarge(num) {
    if (num >= 1000000000000) return (num / 1000000000000).toFixed(2) + 'T';
    if (num >= 1000000000) return (num / 1000000000).toFixed(2) + 'B';
    if (num >= 1000000) return (num / 1000000).toFixed(2) + 'M';
    return num.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function initHome() {
    const etfEl = document.getElementById('etf-price');
    if (!etfEl) return;
    if (etfEl.dataset.initialized) return;
    etfEl.dataset.initialized = 'true';
    
    // Midnight Atelier Colors for flashes
    const COLOR_SECONDARY = '#4edea3'; // Green (Up)
    const COLOR_TERTIARY = '#ffb3ad';  // Red (Down)
    const COLOR_DEFAULT = '#dae2fd';
    const COLOR_MUTED = '#c2c6d6';

    function onMarketUpdate(event) {
        const payload = event.detail;

        // Update Macro UI
        if (payload.macro) {
            const macroCycleEl = document.getElementById('macro-cycle');


            if (macroCycleEl) {
                const cycleText = payload.macro.output_gap > 0.01 ? 'Boom' : (payload.macro.output_gap < -0.01 ? 'Bust' : 'Neutral');
                if (macroCycleEl.innerText !== cycleText) {
                    macroCycleEl.innerText = cycleText;
                    macroCycleEl.style.color = COLOR_SECONDARY;
                    setTimeout(() => macroCycleEl.style.color = COLOR_DEFAULT, 500);
                }
            }


        }

        // Update the Market Index (ETF) Live
        const lbiStock = payload.stocks.find(s => s.ticker === 'LBI');
        if (lbiStock) {
            const etfElLive = document.getElementById('etf-price');
            if (etfElLive) etfElLive.innerText = '$' + parseFloat(lbiStock.price).toFixed(2);
        }

        // Loop through the live prices and update the DOM
        payload.stocks.forEach(stock => {
            const priceEl = document.getElementById(`price-${stock.ticker}`);
            const mcapEl = document.getElementById(`mcap-${stock.ticker}`);
            const rowEl = document.getElementById(`row-${stock.ticker}`);

            if (priceEl && mcapEl && rowEl) {
                if (stock.is_bankrupt) {
                    rowEl.setAttribute('data-bankrupt', 'true');
                    rowEl.setAttribute('data-mcap', '0');
                    priceEl.innerText = '$0.00';
                    priceEl.classList.add('text-tertiary', 'line-through');
                    mcapEl.innerText = '$0.00';
                    rowEl.classList.add('opacity-50', 'bg-red-950/10');
                    return;
                }

                // Get the old price to check if it went up or down
                const newPrice = parseFloat(stock.price);
                const oldPrice = previousPrices[stock.ticker] || newPrice;

                // Update Price Text
                priceEl.innerText = '$' + newPrice.toFixed(2);

                // Calculate & Update Market Cap (Using the new WebSocket payload if available, else fallback)
                const newMcap = stock.market_cap !== undefined ? stock.market_cap : (newPrice * (window.MARKET_SHARES[stock.ticker] || 0));
                
                // USE THE FORMATTER HERE
                mcapEl.innerText = '$' + formatLarge(newMcap);
                
                // Update the data attribute used for sorting
                rowEl.setAttribute('data-mcap', newMcap);

                // Flash the price Green or Red based on movement
                if (newPrice > oldPrice) {
                    priceEl.style.color = COLOR_SECONDARY;
                    mcapEl.style.color = COLOR_SECONDARY;
                } else if (newPrice < oldPrice) {
                    priceEl.style.color = COLOR_TERTIARY;
                    mcapEl.style.color = COLOR_TERTIARY;
                }
                
                previousPrices[stock.ticker] = newPrice;

                // Reset back to normal color after 500ms
                setTimeout(() => {
                    priceEl.style.color = COLOR_DEFAULT;
                    mcapEl.style.color = COLOR_MUTED; 
                }, 500);
            }
        });
    }

    document.addEventListener('market:update', onMarketUpdate);

    // Throttled Table Sorter
    const sortInterval = setInterval(() => {
        const tbody = document.getElementById('market-table-body');
        if (!tbody) return;

        // Get all rows as an array
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Sort them: active first by data-mcap descending, bankrupt at the bottom
        rows.sort((a, b) => {
            const aBankrupt = a.getAttribute('data-bankrupt') === 'true';
            const bBankrupt = b.getAttribute('data-bankrupt') === 'true';
            if (aBankrupt !== bBankrupt) {
                return aBankrupt ? 1 : -1;
            }
            const mcapA = parseFloat(a.getAttribute('data-mcap')) || 0;
            const mcapB = parseFloat(b.getAttribute('data-mcap')) || 0;
            return mcapB - mcapA; // Descending (Highest cap at the top)
        });

        rows.forEach(row => tbody.appendChild(row));
        
    }, 5000); 

    // Clean up when leaving the page to prevent ghost DOM errors
    document.addEventListener('turbo:before-render', () => {
        document.removeEventListener('market:update', onMarketUpdate);
        clearInterval(sortInterval);
    }, { once: true });
}

document.addEventListener('turbo:load', initHome);
initHome();