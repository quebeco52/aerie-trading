/**
 * Colours for script-drawn UI: charts, the sankey and the district map. The theme ones are read off the
 * tokens in assets/styles/app.css, so a palette change there reaches every chart; the literals are only
 * the fallback for a script that runs before the stylesheet has applied.
 */
const rootStyle = typeof document !== 'undefined' ? getComputedStyle(document.documentElement) : null;

function token(name, fallback) {
    const value = rootStyle ? rootStyle.getPropertyValue(`--color-${name}`).trim() : '';
    return value || fallback;
}

/** `#rrggbb` at a given opacity, for area fills, grid lines and translucent bars drawn in a theme colour. */
export function withAlpha(hex, alpha) {
    const digits = hex.replace('#', '');
    const full = digits.length === 3 ? digits.split('').map(c => c + c).join('') : digits;
    const n = parseInt(full, 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}

const primary = token('primary', '#79a8ff');
const positive = token('secondary', '#3cc584');
const negative = token('tertiary', '#f0646b');
const outline = token('outline-variant', '#3b424b');

export const THEME_COLORS = {
    primary,
    secondary: positive,
    tertiary: negative,
    positive,
    negative,
    warning: token('warning', '#f0a33a'),
    grid: outline,
    surface: token('surface-container-low', '#15171b'),
    surfaceRaised: token('surface-container-high', '#21252b'),
    ground: token('surface', '#0e1013'),
    textPrimary: token('on-surface', '#e8eaed'),
    textMuted: token('on-surface-variant', '#a1a9b4'),
    border: withAlpha(outline, 0.3)
};

/**
 * Categorical series colours, in their fixed order (--color-series-* in app.css). A chart gives its series
 * the slots in legend order, never skipping one, so the neighbours that touch stay distinguishable under
 * colour-blindness; the order is what was validated, so it does not change.
 */
export const SERIES = {
    blue: token('series-blue', '#3987e5'),
    orange: token('series-orange', '#d95926'),
    aqua: token('series-aqua', '#199e70'),
    yellow: token('series-yellow', '#c98500'),
    magenta: token('series-magenta', '#d55181'),
    green: token('series-green', '#008300'),
    violet: token('series-violet', '#9085e9'),
    red: token('series-red', '#e66767'),
};
