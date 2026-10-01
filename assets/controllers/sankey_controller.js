import { Controller } from '@hotwired/stimulus';
import { formatLarge } from '../js/utils/formatters.js';
import { CHART_FONT_MONO } from '../js/utils/fonts.js';
import { readPageData } from '../js/utils/page-data.js';
import { THEME_COLORS, SERIES, withAlpha } from '../js/utils/colors.js';

/**
 * Node colour by role, which the API sends with each node: revenue and outside funding in blue, profit and the
 * cash it leaves in the up colour, costs in the down colour, non-cash charges grey, and what is paid out to
 * shareholders in violet. Every node is labelled, so the green and red pair never carries a meaning alone.
 */
const ROLE_COLORS = {
    income: SERIES.blue,
    profit: THEME_COLORS.positive,
    cost: THEME_COLORS.negative,
    noncash: THEME_COLORS.textMuted,
    payout: SERIES.violet,
};

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
    if (reading.unit === 'level') return `${reading.label} ${new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 }).format(value)}`;
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
                container.innerHTML = '<div class="flex items-center justify-center h-full text-center p-4 text-on-surface-variant text-sm">Loading chart…</div>';
                return;
            }

            this.chart = window.echarts.init(container);
            this.chart.showLoading({
                text: 'Loading…',
                color: THEME_COLORS.primary,
                textColor: THEME_COLORS.textPrimary,
                maskColor: withAlpha(THEME_COLORS.surface, 0.8)
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
                    container.innerHTML = '<div class="flex items-center justify-center h-full text-center p-4 text-on-surface-variant text-sm">The earnings flow appears with the first quarterly report.</div>';
                    return;
                }

                const option = {
                    tooltip: {
                        trigger: 'item',
                        triggerOn: 'mousemove',
                        backgroundColor: withAlpha(THEME_COLORS.surfaceRaised, 0.97),
                        borderColor: THEME_COLORS.grid,
                        borderWidth: 1,
                        padding: [12, 16],
                        textStyle: {
                            color: THEME_COLORS.textPrimary,
                            fontFamily: CHART_FONT_MONO,
                            fontSize: 13
                        },
                        formatter: function (params) {
                            if (params.dataType === 'node') {
                                let html = `<div class="font-semibold text-on-surface-variant mb-1 text-xs">${params.name}</div>`;
                                html += `<div class="font-mono text-lg font-semibold text-on-surface mb-1">${formatSankeyValue(params.value)}</div>`;
                                
                                if (params.data && params.data.streamKey) {
                                    // Null is "not meaningful" — a stream with no prior quarter has
                                    // no growth rate, and printing 0.0% there read as "flat".
                                    const delta = params.data.qoq_delta;
                                    const meaningful = delta !== null && delta !== undefined;
                                    const deltaColor = !meaningful ? THEME_COLORS.textMuted : (delta >= 0 ? THEME_COLORS.positive : THEME_COLORS.negative);
                                    const deltaStr = meaningful ? `${delta >= 0 ? '+' : ''}${(delta * 100).toFixed(1)}%` : 'n/m';
                                    html += `<div class="text-xs font-medium mb-2" style="color: ${deltaColor};">QoQ ${deltaStr}</div>`;
                                }

                                if (params.data && params.data.event) {
                                    html += `<div class="text-xs font-medium text-warning bg-warning/10 px-2 py-1 rounded border border-warning/30 mb-2">${params.data.event}</div>`;
                                }

                                if (params.data && Array.isArray(params.data.drivers) && params.data.drivers.length > 0) {
                                    html += `<div class="mt-2 pt-2 border-t border-outline-variant space-y-1">`;
                                    html += `<div class="text-xs font-semibold text-on-surface-variant mb-1">Drivers</div>`;
                                    // A measured driver carries `share`: how much higher (or lower) the stream
                                    // is than it would be with that input at its neutral reading, measured by
                                    // the model's own physics. Older reports carry only an unpriced `impact`,
                                    // so the share is printed only where it exists.
                                    params.data.drivers.forEach(d => {
                                        const isPos = (d.direction ?? ((d.impact || 0) >= 0 ? 1 : -1)) >= 0;
                                        const tag = DRIVER_TYPE_TAGS[d.type] || DRIVER_TYPE_TAGS.company;
                                        const colorClass = isPos ? 'text-secondary' : 'text-tertiary';
                                        const readings = Array.isArray(d.readings) ? d.readings : [];
                                        html += `<div class="flex items-center justify-between text-2xs gap-3">
                                            <span class="text-on-surface"><span class="text-2xs text-on-surface-variant">${tag}</span> ${d.label}</span>
                                            <span class="font-mono font-bold ${colorClass}">${typeof d.share === 'number' ? `${d.share >= 0 ? '+' : '−'}${Math.abs(d.share * 100).toFixed(1)}% ` : ''}${strengthMeter(d.strength, isPos)}</span>
                                        </div>`;
                                        if (readings.length > 0) {
                                            html += `<div class="text-2xs font-mono text-on-surface-variant pl-4">${readings.map(formatMacroReading).join('  ·  ')}</div>`;
                                        } else if (d.type === 'momentum' && d.z !== undefined) {
                                            const z = Number(d.z);
                                            html += `<div class="text-2xs font-mono text-on-surface-variant pl-4">Operating momentum ${z >= 0 ? '+' : '−'}${Math.abs(z).toFixed(1)}σ ${z >= 0 ? 'above' : 'below'} trend</div>`;
                                        }
                                    });
                                    html += `</div>`;
                                }

                                return html;
                            } else {
                                return `<div class="text-on-surface-variant font-medium mb-1">${params.data.source} &rarr; ${params.data.target}</div><div class="font-mono text-lg font-semibold text-on-surface">${formatSankeyValue(params.value)}</div>`;
                            }
                        }
                    },
                    series: [
                        {
                            type: 'sankey',
                            data: data.nodes.map(node => ({
                                ...node,
                                itemStyle: { color: ROLE_COLORS[node.role] ?? THEME_COLORS.textMuted },
                            })),
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
                                color: THEME_COLORS.textPrimary,
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
