import { THEME_COLORS } from './colors.js';

let chartConfigured = false;

/**
 * Initializes global Chart.js settings once.
 */
export function setupChartDefaults() {
    if (typeof Chart === 'undefined' || chartConfigured) return;

    Chart.defaults.color = THEME_COLORS.textMuted;
    Chart.defaults.scale.grid.color = 'rgba(45, 52, 73, 0.4)';
    Chart.defaults.font.family = '"Courier Prime", monospace';
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
            ctx.font = 'bold 20px "Courier Prime", monospace';
            ctx.fillStyle = '#e2e8f0';
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

    chartConfigured = true;
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
    backgroundColor: 'rgba(19, 27, 46, 0.95)',
    titleColor: '#dae2fd',
    bodyColor: '#c2c6d6',
    borderColor: '#424754',
    borderWidth: 1,
    padding: 10,
    titleFont: { family: '"Courier Prime", monospace', size: 11, weight: 'bold' },
    bodyFont: { family: '"Courier Prime", monospace', size: 11 }
};
