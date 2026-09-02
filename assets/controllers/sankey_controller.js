import { Controller } from '@hotwired/stimulus';
import { formatLarge } from '../js/utils/formatters.js';

const formatSankeyValue = (num) => formatLarge(num, '$');

export default class extends Controller {
    static targets = ['container'];
    static values = {
        ticker: String,
        url: { type: String, default: '/api/earnings-flow' }
    };

    connect() {
        this.chart = null;
        this.resizeHandler = () => this.chart?.resize();
        window.addEventListener('resize', this.resizeHandler);

        this.openHandler = () => this.open();
        this.closeHandler = () => this.close();
        this.keydownHandler = (e) => {
            if (e.key === 'Escape' && !this.element.classList.contains('hidden')) {
                this.close();
            }
        };

        document.addEventListener('sankey:open', this.openHandler);
        document.addEventListener('sankey:close', this.closeHandler);
        document.addEventListener('keydown', this.keydownHandler);
    }

    disconnect() {
        window.removeEventListener('resize', this.resizeHandler);
        document.removeEventListener('sankey:open', this.openHandler);
        document.removeEventListener('sankey:close', this.closeHandler);
        document.removeEventListener('keydown', this.keydownHandler);
        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }
    }

    open() {
        this.element.classList.remove('hidden');
        this.element.classList.add('flex');
        this.loadChart();
    }

    close() {
        this.element.classList.add('hidden');
        this.element.classList.remove('flex');
    }

    backdropClick(event) {
        if (event.target === this.element) {
            this.close();
        }
    }

    async loadChart() {
        const container = this.hasContainerTarget ? this.containerTarget : this.element;
        if (!container) return;

        if (!this.chart) {
            if (typeof window.echarts === 'undefined') {
                console.error("ECharts library is not loaded.");
                container.innerHTML = '<div class="flex items-center justify-center h-full text-center p-4 text-on-surface-variant text-sm font-serif">Chart library loading...</div>';
                return;
            }

            this.chart = window.echarts.init(container);
            this.chart.showLoading({
                text: 'Loading Earnings Flow...',
                color: '#adc6ff',
                textColor: '#dae2fd',
                maskColor: 'rgba(11, 19, 38, 0.8)'
            });
            
            try {
                const url = this.urlValue || '/api/earnings-flow';
                const ticker = this.tickerValue || window.AERIE_DATA?.ticker || '';
                const response = await fetch(`${url}?ticker=${encodeURIComponent(ticker)}`);
                if (!response.ok) throw new Error(`HTTP error ${response.status}`);
                const data = await response.json();
                
                this.chart.hideLoading();
                
                if (!data.nodes || data.nodes.length === 0) {
                    this.chart.dispose();
                    this.chart = null;
                    container.innerHTML = '<div class="flex items-center justify-center h-full text-center p-4 text-on-surface-variant font-bold tracking-widest uppercase text-sm font-mono">No earnings flow data available for this asset yet.</div>';
                    return;
                }

                const option = {
                    tooltip: {
                        trigger: 'item',
                        triggerOn: 'mousemove',
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        borderColor: 'rgba(51, 65, 85, 0.5)',
                        borderWidth: 1,
                        padding: [12, 16],
                        textStyle: {
                            color: '#f8fafc',
                            fontFamily: 'Courier Prime, monospace, sans-serif',
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
                                fontFamily: 'Courier Prime, monospace, sans-serif',
                                fontSize: 12,
                                fontWeight: 'bold',
                                padding: [0, 8]
                            }
                        }
                    ]
                };

                this.chart.setOption(option);
                setTimeout(() => {
                    this.chart?.resize();
                }, 50);
            } catch (error) {
                console.error("Failed to load Sankey chart data", error);
                this.chart.hideLoading();
                container.innerHTML = '<div class="flex items-center justify-center h-full text-center p-4 text-tertiary font-bold text-sm">Error loading earnings flow data.</div>';
            }
        } else {
            setTimeout(() => {
                this.chart?.resize();
            }, 50);
        }
    }
}
