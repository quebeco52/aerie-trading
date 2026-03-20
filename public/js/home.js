document.addEventListener('DOMContentLoaded', () => {
    
    setInterval(() => {
        fetch('/api/market')
            .then(response => response.json())
            .then(data => {
                
                // Update the ETF Price
                const etfElement = document.getElementById('etf-price');
                if (etfElement && data.etf) {
                    etfElement.innerText = '$' + data.etf.price;
                }

                // Redraw the entire table body so the sorting updates live
                const tbody = document.getElementById('market-table-body');
                if (!tbody) return; 
                
                tbody.innerHTML = ''; 
                
                data.stocks.forEach(stock => {
                    const tr = document.createElement('tr');
                    
                    tr.className = 'hover:bg-surface-container-high/40 transition-colors cursor-pointer group';
                    tr.onclick = () => window.location.href = '/stock/' + stock.ticker;


                    tr.innerHTML = `
                        <td class="px-8 py-5">
                            <div class="flex flex-col">
                                <div class="font-bold text-on-surface text-base group-hover:text-primary transition-colors">${stock.name}</div>
                                <div class="text-on-surface-variant text-[10px] uppercase tracking-widest mt-0.5">${stock.ticker}</div>
                            </div>
                        </td>
                        <td class="px-6 py-5 hidden sm:table-cell text-on-surface-variant">
                            ${stock.sector}
                        </td>
                        <td class="px-6 py-5 text-right font-bold text-on-surface">
                            $${stock.price}
                        </td>
                        <td class="px-8 py-5 text-right font-medium text-on-surface-variant">
                            $${stock.marketCap}
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(error => console.error("Error fetching market data:", error));
    }, 5000); // 5 Seconds
});