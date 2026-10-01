import { THEME_COLORS, withAlpha } from './colors.js';
import { CHART_FONT_MONO } from './fonts.js';

// Identity, not a boolean: Turbo re-executes the library's <script> on navigation and the
// module holding a "configured" flag is not reloaded, so the fresh global would go unconfigured.
let configuredChart = null;

/** Applies the app's Chart.js defaults, once per Chart global. */
export function setupChartDefaults() {
    if (typeof Chart === 'undefined' || configuredChart === Chart) return;

    Chart.defaults.color = THEME_COLORS.textMuted;
    Chart.defaults.scale.grid.color = withAlpha(THEME_COLORS.grid, 0.5);
    Chart.defaults.font.family = CHART_FONT_MONO;
    // A line's legend marker is a solid dot in its colour, whether or not the line also fills an area.
    const baseLegendLabels = Chart.defaults.plugins.legend.labels.generateLabels;
    Chart.defaults.plugins.legend.labels.generateLabels = (chart) => baseLegendLabels(chart).map(item => {
        const dataset = chart.data.datasets[item.datasetIndex];
        if ((dataset?.type ?? chart.config.type) === 'line' && typeof item.strokeStyle === 'string') {
            item.fillStyle = item.strokeStyle;
        }
        return item;
    });
    Chart.defaults.animation = false;
    Chart.defaults.animations = false;
    if (Chart.defaults.transitions && Chart.defaults.transitions.active) {
        Chart.defaults.transitions.active.animation.duration = 0;
    }

    const centerTextPlugin = {
        id: 'centerText',
        beforeDraw(chart) {
            if (chart.config.type !== 'doughnut') return;
            const text = chart.config.options?.plugins?.centerText?.text;
            if (!text) return;
            const { ctx, chartArea } = chart;
            if (!chartArea) return;
            ctx.save();
            ctx.font = `bold 20px ${CHART_FONT_MONO}`;
            ctx.fillStyle = THEME_COLORS.textPrimary;
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            const centerX = (chartArea.left + chartArea.right) / 2;
            const centerY = (chartArea.top + chartArea.bottom) / 2;
            ctx.fillText(text, centerX, centerY);
            ctx.restore();
        }
    };

    try {
        Chart.register(centerTextPlugin);
    } catch (e) {
        // Plugin might already be registered
    }

    configuredChart = Chart;
}

/**
 * Safely destroys a Chart.js instance.
 * @param {Chart|null|undefined} chartInstance 
 * @returns {null}
 */
export function destroyChartInstance(chartInstance) {
    if (chartInstance) {
        try {
            chartInstance.destroy();
        } catch (e) {
            // Ignored
        }
    }
    return null;
}

/**
 * Standard Chart.js tooltip configuration
 */
export const standardTooltipConfig = {
    backgroundColor: withAlpha(THEME_COLORS.surfaceRaised, 0.96),
    titleColor: THEME_COLORS.textPrimary,
    bodyColor: THEME_COLORS.textMuted,
    borderColor: THEME_COLORS.grid,
    borderWidth: 1,
    padding: 10,
    titleFont: { family: CHART_FONT_MONO, size: 11, weight: 'bold' },
    bodyFont: { family: CHART_FONT_MONO, size: 11 }
};
