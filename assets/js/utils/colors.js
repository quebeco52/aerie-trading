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

export const BRAND_COLORS = {
    'LAKE': '#045729',  // Green
    'SWAN': '#770707',  // Red
    'HUMM': '#ec4899',  // Pink
    'OWLS': '#482814',  // Brown
    'KING': '#ea580c',  // Orange
    'RIVR': '#3b82f6',  // Blue
    'SAFE': '#f59e0b',  // Orange
    'DOVE': '#8b5cf6',  // Purple
    'SHRK': '#11223D',  // Dark Slate
    'BIRD': '#84cc16',  // Lime Green
    'DOWN': '#78716c',  // Stone Gray
    'WATCH': '#ef4444', // Red
    'WING': '#64748b',  // Steel Gray
    'PENG': '#06b6d4',  // Cyan
    'SHOR': '#14b8a6',  // Teal
    'RIVE': '#0369a1',  // Lake Blue
    'LBI':  '#4338ca',  // Deep Indigo
    'GRIP': '#1e3a8a',  // Navy Blue
    'LOON': '#4f46e5',  // Indigo
    'PHIL': '#7c2d12',  // Rust Brown
    'TRIV': '#ca8a04',  // Gold
    'IBHI': '#991b1b',  // Dark Red
    'TICK': '#22c55e',  // Terminal Green
    'SINK': '#0f172a',  // Oil Black
    'CASC': '#10b981',  // Green
    'GULL': '#C7B066',  // Yellow
    'WADE': '#2563eb',  // Deep Water Blue
    'VULT': '#4d7c0f',  // Moss Green
    'CRAN': '#0891b2',  // Clinical Blue
    'TALN': '#be123c',  // Predatory Red
    'CANV': '#d97706',  // Cardboard Brown
    'SGRB': '#f43f5e',  // Candy Rose
    'STRK': '#38bdf8',  // Sky Blue
    'BREW': '#9a3412',  // Copper
    'CLAW': '#111827',  // Suit Black
    'ROOK': '#dc2626',  // Blood Red
    'PLZA': '#94a3b8',   // Concrete Gray
    'LYRE': '#a855f7',  // Synthetic Purple
    'STAR': '#059669',  // Institutional Emerald
    'WEAV': '#facc15',  // Digital Gold
    'PERE': '#0284c7',  // Pristine Cerulean
    'CROP': '#15803d',  // Earth Green
    'BRKW': '#00674F',  // Emerald green
    'ELDE': '#fb923c',  // soft sunset orange
    'SWFT': '#FFBC0D',  // soft yellow/orange
    'LARK': '#0d9488',  // Clean Teal
    'CBIL': '#c2410c',  // Precision Orange
};

export const FALLBACK_PALETTE = [
    '#6366f1', '#14b8a6', '#f43f5e', '#84cc16', 
    '#06b6d4', '#0ea5e9', '#d946ef', '#64748b'
];