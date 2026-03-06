// public/js/home.js

document.addEventListener('DOMContentLoaded', () => {
    // Run this function every 2000 milliseconds (2 seconds)
    setInterval(() => {
        fetch('/api/market')
            .then(response => response.json())
            .then(data => {
                
                // 1. Update the ETF Price
                const etfElement = document.getElementById('etf-price');
                if (etfElement && data.etf) {
                    etfElement.innerText = '$' + data.etf.price;
                }

                // 2. Redraw the entire table body so the sorting updates live!
                const tbody = document.getElementById('market-table-body');
                if (!tbody) return; // Safety check
                
                tbody.innerHTML = ''; // Clear the old rows
                
                data.stocks.forEach(stock => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-700/70 transition cursor-pointer group';
                    tr.onclick = () => window.location.href = '/stock/' + stock.ticker;

                    // Insert the updated HTML for the row
                    tr.innerHTML = `
                        <td class="whitespace-nowrap py-4 pl-6 pr-3">
                            <div class="flex items-center">
                                <div>
                                    <div class="font-bold text-white text-base group-hover:text-indigo-300 transition">${stock.ticker}</div>
                                    <div class="text-gray-400 text-xs mt-0.5">${stock.name}</div>
                                </div>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-300 hidden sm:table-cell">
                            <span class="inline-flex items-center rounded-md bg-slate-400/10 px-2 py-1 text-xs font-medium text-slate-400 ring-1 ring-inset ring-slate-400/20">
                                ${stock.sector}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-4 text-sm text-right font-mono text-white font-medium">
                            $${stock.price}
                        </td>
                        <td class="whitespace-nowrap py-4 pl-3 pr-6 text-sm text-right font-mono text-indigo-200">
                            $${stock.marketCap}
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(error => console.error("Error fetching market data:", error));
    }, 1000); // 1000ms = 1 seconds
});