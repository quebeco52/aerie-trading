import { Controller } from '@hotwired/stimulus';

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
                                return `<div class="font-bold text-slate-200 mb-1 tracking-wider uppercase text-xs">${params.name}</div><div class="font-mono text-lg font-bold text-white">$${params.value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}B</div>`;
                            } else {
                                return `<div class="text-slate-400 font-medium mb-1">${params.data.source} &rarr; ${params.data.target}</div><div class="font-mono text-lg font-bold text-white">$${params.value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}B</div>`;
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
