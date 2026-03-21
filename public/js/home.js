const previousPrices = {};

document.addEventListener('DOMContentLoaded', () => {
    
    // Connect to the WebSocket
    const marketSocket = new WebSocket('ws://127.0.0.1:8080');

    // Midnight Atelier Colors for flashes
    const COLOR_SECONDARY = '#4edea3'; // Green (Up)
    const COLOR_TERTIARY = '#ffb3ad';  // Red (Down)
    const COLOR_DEFAULT = '#dae2fd';
    const COLOR_MUTED = '#c2c6d6';

    marketSocket.onmessage = function (event) {
        const payload = JSON.parse(event.data);

        // Update the Market Index (ETF) Live
        const lbiStock = payload.stocks.find(s => s.ticker === 'LBI');
        if (lbiStock) {
            const etfEl = document.getElementById('etf-price');
            if (etfEl) etfEl.innerText = '$' + parseFloat(lbiStock.price).toFixed(2);
        }

        // Loop through the live prices and update the DOM
        payload.stocks.forEach(stock => {
            const priceEl = document.getElementById(`price-${stock.ticker}`);
            const mcapEl = document.getElementById(`mcap-${stock.ticker}`);
            const rowEl = document.getElementById(`row-${stock.ticker}`);

            if (priceEl && mcapEl && rowEl) {
                // Get the old price to check if it went up or down
                const newPrice = parseFloat(stock.price);
                const oldPrice = previousPrices[stock.ticker] || newPrice;

                // Update Price Text
                priceEl.innerText = '$' + newPrice.toFixed(2);

                // Calculate & Update Market Cap (Shares * Live Price)
                const shares = window.MARKET_SHARES[stock.ticker] || 0;
                const newMcap = newPrice * shares;
                mcapEl.innerText = '$' + (newMcap / 1000000000).toFixed(2) + 'B';
                
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
    };

    // Throttled Table Sorter
    setInterval(() => {
        const tbody = document.getElementById('market-table-body');
        if (!tbody) return;

        // Get all rows as an array
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Sort them by the data-mcap attribute we are updating live
        rows.sort((a, b) => {
            const mcapA = parseFloat(a.getAttribute('data-mcap'));
            const mcapB = parseFloat(b.getAttribute('data-mcap'));
            return mcapB - mcapA; // Descending (Highest cap at the top)
        });

        rows.forEach(row => tbody.appendChild(row));
        
    }, 5000); 

});