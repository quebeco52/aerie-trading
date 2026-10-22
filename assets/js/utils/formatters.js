/**
 * The live twins of the figure filters in src/Twig/Extension/UiExtension.php: a figure painted on the server and
 * repainted here prints the same, or it reformats itself under the reader. .agents/FRONTEND.md lists the pairs.
 */

/**
 * Rendered in place of a figure that is missing, non-numeric or infinite. An absent market cap
 * and a zero one are different facts, so neither is formatted as '$0.00'. Currency symbols go
 * through the `prefix` argument rather than concatenation, which would yield '$—'.
 */
export const NOT_AVAILABLE = '—';

/**
 * Coerces a display input to a finite number, or null when it cannot stand as a figure.
 * @param {number|string|null|undefined} value
 * @returns {number|null}
 */
function toFiniteNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
}

/**
 * value × scale to `decimals` places, thousands grouped, with its unit: PHP's number_format() as UiExtension prints
 * it. A value that rounds to zero carries no sign; with showSign a rise carries "+".
 * @param {number|string|null|undefined} num
 * @param {number} scale
 * @param {number} decimals
 * @param {boolean} showSign
 * @param {string} unit
 * @returns {string}
 */
function formatScaled(num, scale, decimals, showSign, unit) {
    const value = toFiniteNumber(num);
    if (value === null) return NOT_AVAILABLE;

    // toFixed keeps the sign of a value that rounds to zero ("-0.00"); PHP's number_format does not.
    const fixed = (value * scale).toFixed(decimals).replace(/^-(?=0(\.0+)?$)/, '');
    const [whole, fraction] = fixed.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const sign = (showSign && Number(fixed) > 0) ? '+' : '';
    return `${sign}${grouped}${fraction === undefined ? '' : `.${fraction}`}${unit}`;
}

/**
 * Formats a large number into compact units (T, B, M) or localized number.
 * @param {number|string|null|undefined} num
 * @param {string} prefix Optional prefix e.g. '$', applied inside the sign ('-$1.20B')
 * @param {boolean} showSign If true, a rise carries '+'
 * @returns {string}
 */
export function formatLarge(num, prefix = '', showSign = false) {
    const value = toFiniteNumber(num);
    if (value === null) return NOT_AVAILABLE;

    const isNegative = value < 0;
    const absNum = Math.abs(value);

    let formatted;
    if (absNum >= 1000000000000) {
        formatted = (absNum / 1000000000000).toFixed(2) + 'T';
    } else if (absNum >= 1000000000) {
        formatted = (absNum / 1000000000).toFixed(2) + 'B';
    } else if (absNum >= 1000000) {
        formatted = (absNum / 1000000).toFixed(2) + 'M';
    } else {
        formatted = absNum.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    const sign = isNegative ? '-' : (showSign && value > 0 ? '+' : '');
    return `${sign}${prefix}${formatted}`;
}

const currencyFormatters = new Map();

/**
 * Formats a number as currency ($1,234.56).
 * @param {number|string|null|undefined} num
 * @param {number} decimals
 * @returns {string}
 */
export function formatCurrency(num, decimals = 2) {
    const value = toFiniteNumber(num);
    if (value === null) return NOT_AVAILABLE;

    let formatter = currencyFormatters.get(decimals);
    if (!formatter) {
        formatter = new Intl.NumberFormat(undefined, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
        currencyFormatters.set(decimals, formatter);
    }

    // A loss that rounds to nothing prints "$0.00", as the `money` Twig filter does, not "-$0.00".
    const isNegative = Number(value.toFixed(decimals)) < 0;
    const formatted = formatter.format(Math.abs(value));
    return isNegative ? `-$${formatted}` : `$${formatted}`;
}

/**
 * Formats share counts into readable shorthand (e.g. 12.5M, 350K).
 * @param {number|string|null|undefined} num
 * @returns {string}
 */
export function formatShares(num) {
    const value = toFiniteNumber(num);
    if (value === null) return NOT_AVAILABLE;

    const absNum = Math.abs(value);
    const isNegative = value < 0;
    let formatted;
    if (absNum >= 1000000000) {
        formatted = (absNum / 1000000000).toFixed(2) + 'B';
    } else if (absNum >= 1000000) {
        formatted = (absNum / 1000000).toFixed(2) + 'M';
    } else if (absNum >= 1000) {
        formatted = (absNum / 1000).toFixed(1) + 'K';
    } else {
        formatted = absNum.toLocaleString();
    }
    return isNegative ? `-${formatted}` : formatted;
}

/**
 * Formats a decimal ratio or percent into a percentage string (e.g. 0.052 -> 5.20% or 5.2 -> 5.20%).
 * @param {number|string|null|undefined} num
 * @param {number} decimals
 * @param {boolean} alreadyPercent If true, does not multiply by 100
 * @param {boolean} showSign If true, includes explicit '+' for positives
 * @returns {string}
 */
export function formatPercent(num, decimals = 2, alreadyPercent = false, showSign = false) {
    return formatScaled(num, alreadyPercent ? 1 : 100, decimals, showSign, '%');
}

/**
 * A change in a rate or share as percentage points (0.0123 → "1.23pp"); the `pp` Twig filter.
 * @param {number|string|null|undefined} num
 * @param {number} decimals
 * @param {boolean} showSign
 * @returns {string}
 */
export function formatPoints(num, decimals = 2, showSign = false) {
    return formatScaled(num, 100, decimals, showSign, 'pp');
}

/**
 * A spread, or a move in a rate, in basis points (0.0123 → "123 bps"); the `bps` Twig filter.
 * @param {number|string|null|undefined} num
 * @param {number} decimals
 * @param {boolean} showSign
 * @returns {string}
 */
export function formatBps(num, decimals = 0, showSign = false) {
    return formatScaled(num, 10000, decimals, showSign, ' bps');
}

/**
 * A ratio quoted as a multiple (1.234 → "1.23x"); the `multiple` Twig filter.
 * @param {number|string|null|undefined} num
 * @param {number} decimals
 * @returns {string}
 */
export function formatMultiple(num, decimals = 2) {
    return formatScaled(num, 1, decimals, false, 'x');
}

/** Decimals each FigureFormat prints when the page asks for none (FigureFormat::decimals()). */
export const FIGURE_DECIMALS = {
    percent: 2, signed_percent: 2, signed_points: 2, bps: 0, large_money: 2,
    multiple: 2, quarters: 1, years: 1, index: 3, signed_index: 3,
};

/**
 * A figure in the form its builder asked for, by FigureFormat value; the `figure` Twig filter.
 * @param {number|string|null|undefined} num
 * @param {string} format
 * @param {number|null} decimals Overrides the format's own
 * @returns {string}
 */
export function formatFigure(num, format, decimals = null) {
    const places = decimals ?? FIGURE_DECIMALS[format] ?? 2;
    switch (format) {
        case 'percent': return formatPercent(num, places);
        case 'signed_percent': return formatPercent(num, places, false, true);
        case 'signed_points': return formatPoints(num, places, true);
        case 'bps': return formatBps(num, places);
        case 'large_money': return formatLarge(num, '$');
        case 'multiple': return formatMultiple(num, places);
        case 'quarters': return formatScaled(num, 1, places, false, ' qtrs');
        case 'years': return formatScaled(num, 1, places, false, ' yrs');
        case 'signed_index': return formatScaled(num, 1, places, true, '');
        default: return formatScaled(num, 1, places, false, '');
    }
}

/** Whether a FigureFormat value is a change, which takes "+" and the up/down colour (FigureFormat::isSigned()). */
export function isSignedFigure(format) {
    return String(format).startsWith('signed');
}

/**
 * "EARNINGS BEAT" → "Earnings beat": event badges arrive in capitals and every page prints them in sentence case,
 * as the stock page's `|lower|capitalize` does.
 * @param {string} text
 * @returns {string}
 */
export function sentenceCase(text) {
    const lower = String(text).toLowerCase();
    return lower.charAt(0).toUpperCase() + lower.slice(1);
}

/** Every class signedClass() can return, for clearing a cell before it is recoloured. */
export const SIGNED_CLASSES = ['text-secondary', 'text-tertiary', 'text-on-surface-variant', 'text-on-surface-faint'];

/**
 * The text colour for a signed figure: up, down, unchanged (neutral) or unknown (faint). The live twin of the
 * `signed_class` Twig filter (UiExtension::signedClass), so a cell keeps its colour when the feed repaints it.
 * @param {number|string|null|undefined} num
 * @returns {string}
 */
export function signedClass(num) {
    const value = toFiniteNumber(num);
    if (value === null) return 'text-on-surface-faint';
    if (value > 0) return 'text-secondary';
    if (value < 0) return 'text-tertiary';
    return 'text-on-surface-variant';
}
