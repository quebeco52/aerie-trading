import { Controller } from '@hotwired/stimulus';
import { formatLarge } from '../js/utils/formatters.js';
import { CHART_FONT_MONO } from '../js/utils/fonts.js';
import { readPageData } from '../js/utils/page-data.js';

const formatSankeyValue = (num) => formatLarge(num, '$');

/** Pips in a driver's strength meter — the bands EarningsReportSubscriber grades a driver into. */
const DRIVER_STRENGTH_PIPS = 3;

/**
 * What kind of driver a row is, as a text tag. Emoji were unreadable in this tooltip: it is set in
 * IBM Plex Mono, which has no colour-emoji fallback in this stack, so they rendered as tofu.
 */
const DRIVER_TYPE_TAGS = { macro: 'macro', momentum: 'ops', company: 'co' };

/**
 * A driver's direction and strength, drawn rather than typed — box-drawing characters have no
 * glyph in this tooltip's font either, so the pips are sized spans with inline styles.
 */
function strengthMeter(strength, isPositive) {
    const filled = Math.max(1, Math.min(DRIVER_STRENGTH_PIPS, strength || 1));
    const arrow = isPositive ? '▲' : '▼';

    let pips = '';
    for (let index = 0; index < DRIVER_STRENGTH_PIPS; index++) {
        pips += `<span style="display:inline-block;width:3px;height:8px;border-radius:1px;`
            + `background-color:currentColor;opacity:${index < filled ? 1 : 0.2};margin-left:2px;"></span>`;
    }

    return `<span style="font-size:8px;">${arrow}</span>${pips}`;
}

/**
 * Formats one macro driver reading in the unit its catalogue entry declares. Mirrors the units in
 * App\Data\MacroFieldCatalog — spreads in basis points, rates as percentages, indices as levels —
 * so the same variable reads identically here and on the district street.
 */
function formatMacroReading(reading) {
    const value = Number(reading.value);
    if (!Number.isFinite(value)) return `${reading.label} —`;
    if (reading.unit === 'bps') return `${reading.label} ${Math.round(value * 10000)} bps`;
    if (reading.unit === 'pct') return `${reading.label} ${(value * 100).toFixed(2)}%`;
    return `${reading.label} ${value.toFixed(1)}`;
}

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
                const ticker = this.tickerValue || readPageData('aerie-data').ticker || '';
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
                            fontFamily: CHART_FONT_MONO,
                            fontSize: 13
                        },
                        formatter: function (params) {
                            if (params.dataType === 'node') {
                                let html = `<div class="font-bold text-slate-200 mb-1 tracking-wider uppercase text-xs">${params.name}</div>`;
                                html += `<div class="font-mono text-lg font-bold text-white mb-1">${formatSankeyValue(params.value)}</div>`;
                                
                                if (params.data && params.data.streamKey) {
                                    // Null is "not meaningful" — a stream with no prior quarter has
                                    // no growth rate, and printing 0.0% there read as "flat".
                                    const delta = params.data.qoq_delta;
                                    const meaningful = delta !== null && delta !== undefined;
                                    const deltaColor = !meaningful ? '#94a3b8' : (delta >= 0 ? '#4edea3' : '#ffb3ad');
                                    const deltaStr = meaningful ? `${delta >= 0 ? '+' : ''}${(delta * 100).toFixed(1)}%` : 'n/m';
                                    html += `<div class="text-2xs font-semibold mb-2" style="color: ${deltaColor};">QoQ: ${deltaStr}</div>`;
                                }

                                if (params.data && params.data.event) {
                                    html += `<div class="text-2xs font-bold text-amber-400 bg-amber-950/40 px-2 py-1 rounded border border-amber-500/30 mb-2">⚡ ${params.data.event}</div>`;
                                }

                                if (params.data && Array.isArray(params.data.drivers) && params.data.drivers.length > 0) {
                                    html += `<div class="mt-2 pt-2 border-t border-slate-700 space-y-1">`;
                                    html += `<div class="text-3xs font-bold uppercase tracking-wider text-slate-400 mb-1">Key Drivers</div>`;
                                    // A driver's `impact` is an unpriced model coefficient, not a
                                    // share of revenue — it never reconciled against the QoQ figure
                                    // above, so it is shown as direction and strength, with the
                                    // observed macro readings App\Data\MacroFieldCatalog resolved.
                                    params.data.drivers.forEach(d => {
                                        const isPos = (d.direction ?? ((d.impact || 0) >= 0 ? 1 : -1)) >= 0;
                                        const tag = DRIVER_TYPE_TAGS[d.type] || DRIVER_TYPE_TAGS.company;
                                        const colorClass = isPos ? 'text-emerald-400' : 'text-rose-400';
                                        const readings = Array.isArray(d.readings) ? d.readings : [];
                                        html += `<div class="flex items-center justify-between text-2xs gap-3">
                                            <span class="text-slate-300"><span class="text-4xs uppercase tracking-wider text-slate-500">${tag}</span> ${d.label}</span>
                                            <span class="font-mono font-bold ${colorClass}">${strengthMeter(d.strength, isPos)}</span>
                                        </div>`;
                                        if (readings.length > 0) {
                                            html += `<div class="text-3xs font-mono text-slate-500 pl-4">${readings.map(formatMacroReading).join('  ·  ')}</div>`;
                                        } else if (d.type === 'momentum' && d.z !== undefined) {
                                            const z = Number(d.z);
                                            html += `<div class="text-3xs font-mono text-slate-500 pl-4">Operating momentum ${z >= 0 ? '+' : '−'}${Math.abs(z).toFixed(1)}σ ${z >= 0 ? 'above' : 'below'} trend</div>`;
                                        }
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
                                fontFamily: CHART_FONT_MONO,
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
