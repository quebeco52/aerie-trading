import { Controller } from '@hotwired/stimulus';

function formatSankeyValue(num) {
    if (num === null || num === undefined) return '$0.00';
    const isNeg = num < 0;
    const abs = Math.abs(num);
    let formatted;
    if (abs >= 1e12) formatted = (abs / 1e12).toFixed(2) + 'T';
    else if (abs >= 1e9) formatted = (abs / 1e9).toFixed(2) + 'B';
    else if (abs >= 1e6) formatted = (abs / 1e6).toFixed(2) + 'M';
    else if (abs >= 1e3) formatted = (abs / 1e3).toFixed(2) + 'K';
    else formatted = abs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return (isNeg ? '-$' : '$') + formatted;
}

export default class extends Controller {
    static values = {
        ticker: String,
        url: String
    }

    connect() {
        this.chart = null;
        this.resizeHandler = () => this.chart?.resize();
        window.addEventListener('resize', this.resizeHandler);
    }

    disconnect() {
        window.removeEventListener('resize', this.resizeHandler);
        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }
    }

    open() {
        const modal = this.element.closest('dialog') || document.getElementById('sankeyModal');
        if (modal && !modal.hasAttribute('open')) {
            modal.showModal();
        }
        this.loadChart();
    }

    async loadChart() {
        if (!this.chart) {
            // Need to specify a height for the container if it's empty
            if (this.element.clientHeight === 0) {
                this.element.style.height = '500px';
            }
            this.chart = window.echarts.init(this.element);
            
            // Show loading
            this.chart.showLoading();
            
            try {
                const response = await fetch(`${this.urlValue}?ticker=${this.tickerValue}`);
                const data = await response.json();
                
                this.chart.hideLoading();
                
                if (!data.nodes || data.nodes.length === 0) {
                    this.chart.dispose();
                    this.chart = null;
                    this.element.innerHTML = '<div class="flex items-center justify-center h-full text-center p-4 text-on-surface-variant font-bold tracking-widest uppercase text-sm">No earnings data available for this asset yet.</div>';
                    return;
                }

                const option = {
                    tooltip: {
                        trigger: 'item',
                        triggerOn: 'mousemove',
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        borderColor: 'rgba(51, 65, 85, 0.5)',
                        borderWidth: 1,
                        padding: [12, 16],
                        textStyle: {
                            color: '#f8fafc',
                            fontFamily: 'Inter, sans-serif',
                            fontSize: 13
                        },
                        formatter: function (params) {
                            if (params.dataType === 'node') {
                                let html = `<div class="font-bold text-slate-200 mb-1 tracking-wider uppercase text-xs">${params.name}</div>`;
                                html += `<div class="font-mono text-lg font-bold text-white mb-1">${formatSankeyValue(params.value)}</div>`;
                                
                                if (params.data && params.data.qoq_delta !== undefined && params.data.streamKey) {
                                    const delta = params.data.qoq_delta;
                                    const deltaSign = delta >= 0 ? '+' : '';
                                    const deltaColor = delta >= 0 ? '#4edea3' : '#ffb3ad';
                                    html += `<div class="text-[11px] font-semibold mb-2" style="color: ${deltaColor};">QoQ: ${deltaSign}${(delta * 100).toFixed(1)}%</div>`;
                                }

                                if (params.data && params.data.event) {
                                    html += `<div class="text-[11px] font-bold text-amber-400 bg-amber-950/40 px-2 py-1 rounded border border-amber-500/30 mb-2">⚡ ${params.data.event}</div>`;
                                }

                                if (params.data && Array.isArray(params.data.drivers) && params.data.drivers.length > 0) {
                                    html += `<div class="mt-2 pt-2 border-t border-slate-700 space-y-1">`;
                                    html += `<div class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Key Drivers</div>`;
                                    params.data.drivers.forEach(d => {
                                        const isPos = (d.impact || 0) >= 0;
                                        const sign = isPos ? '+' : '';
                                        const icon = d.type === 'macro' ? '🏛' : '📈';
                                        const metricStr = d.type === 'momentum' 
                                            ? `(Z=${d.z !== undefined ? d.z : '0'})` 
                                            : `${sign}${((d.impact || 0) * 100).toFixed(1)}%`;
                                        const colorClass = isPos ? 'text-emerald-400' : 'text-rose-400';
                                        html += `<div class="flex items-center justify-between text-[11px] gap-3">
                                            <span class="text-slate-300">${icon} ${d.label}</span>
                                            <span class="font-mono font-bold ${colorClass}">${metricStr}</span>
                                        </div>`;
                                    });
                                    html += `</div>`;
                                }

                                return html;
                            } else {
                                return `<div class="text-slate-400 font-medium mb-1">${params.data.source} &rarr; ${params.data.target}</div><div class="font-mono text-lg font-bold text-white">${formatSankeyValue(params.value)}</div>`;
                            }
                        }
                    },
                    series: [
                        {
                            type: 'sankey',
                            data: data.nodes,
                            links: data.links,
                            emphasis: {
                                focus: 'adjacency'
                            },
                            nodeAlign: 'justify',
                            nodeWidth: 24,
                            nodeGap: 20,
                            itemStyle: {
                                borderWidth: 0,
                                borderRadius: 4
                            },
                            lineStyle: {
                                color: 'gradient',
                                curveness: 0.65,
                                opacity: 0.45
                            },
                            label: {
                                color: '#e2e8f0',
                                fontFamily: 'Inter, sans-serif',
                                fontSize: 12,
                                fontWeight: 'bold',
                                padding: [0, 8]
                            }
                        }
                    ]
                };

                this.chart.setOption(option);
            } catch (error) {
                console.error("Failed to load Sankey chart data", error);
                this.chart.hideLoading();
                this.element.innerHTML = '<div class="text-center p-4 text-red-500">Error loading chart data.</div>';
            }
        } else {
            // Resize if it was hidden when initially created (e.g., in a modal)
            setTimeout(() => {
                this.chart.resize();
            }, 50);
        }
    }
}
