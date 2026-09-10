/**
 * Centralized formatting utilities for financial and numerical display.
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
 * Formats a large number into compact units (T, B, M) or localized number.
 * @param {number|string|null|undefined} num
 * @param {string} prefix Optional prefix e.g. '$', applied inside the sign ('-$1.20B')
 * @returns {string}
 */
export function formatLarge(num, prefix = '') {
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

    return isNegative ? `-${prefix}${formatted}` : `${prefix}${formatted}`;
}

/**
 * Formats a number as currency ($1,234.56).
 * @param {number|string|null|undefined} num
 * @param {number} decimals
 * @returns {string}
 */
export function formatCurrency(num, decimals = 2) {
    const value = toFiniteNumber(num);
    if (value === null) return NOT_AVAILABLE;

    const isNegative = value < 0;
    const formatted = Math.abs(value).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
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
    const value = toFiniteNumber(num);
    if (value === null) return NOT_AVAILABLE;

    const val = alreadyPercent ? value : value * 100;
    const sign = (showSign && val > 0) ? '+' : '';
    return `${sign}${val.toFixed(decimals)}%`;
}
