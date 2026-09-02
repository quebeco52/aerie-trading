/**
 * Centralized formatting utilities for financial and numerical display.
 */

/**
 * Formats a large number into compact units (T, B, M) or localized number.
 * @param {number|null|undefined} num 
 * @param {string} prefix Optional prefix e.g. '$'
 * @returns {string}
 */
export function formatLarge(num, prefix = '') {
    if (num === null || num === undefined || isNaN(num)) return prefix + '0.00';

    const isNegative = num < 0;
    const absNum = Math.abs(num);

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

    if (prefix) {
        return isNegative ? `-${prefix}${formatted}` : `${prefix}${formatted}`;
    }
    return isNegative ? `-${formatted}` : formatted;
}

/**
 * Formats a number as currency ($1,234.56).
 * @param {number|null|undefined} num 
 * @param {number} decimals 
 * @returns {string}
 */
export function formatCurrency(num, decimals = 2) {
    if (num === null || num === undefined || isNaN(num)) return '$0.00';
    const isNegative = num < 0;
    const absNum = Math.abs(num);
    const formatted = absNum.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
    return isNegative ? `-$${formatted}` : `$${formatted}`;
}

/**
 * Formats share counts into readable shorthand (e.g. 12.5M, 350K).
 * @param {number|null|undefined} num 
 * @returns {string}
 */
export function formatShares(num) {
    if (num === null || num === undefined || isNaN(num)) return '0';
    const absNum = Math.abs(num);
    const isNegative = num < 0;
    let formatted;
    if (absNum >= 1000000000) {
        formatted = (absNum / 1000000000).toFixed(2) + 'B';
    } else if (absNum >= 1000000) {
        formatted = (absNum / 1000000).toFixed(2) + 'M';
    } else if (absNum >= 1000) {
        formatted = (absNum / 1000).toFixed(1) + 'K';
    } else {
        formatted = Number(absNum).toLocaleString();
    }
    return isNegative ? `-${formatted}` : formatted;
}

/**
 * Formats a decimal ratio or percent into a percentage string (e.g. 0.052 -> 5.20% or 5.2 -> 5.20%).
 * @param {number|null|undefined} num 
 * @param {number} decimals 
 * @param {boolean} alreadyPercent If true, does not multiply by 100
 * @param {boolean} showSign If true, includes explicit '+' for positives
 * @returns {string}
 */
export function formatPercent(num, decimals = 2, alreadyPercent = false, showSign = false) {
    if (num === null || num === undefined || isNaN(num)) return '0.00%';
    const val = alreadyPercent ? num : num * 100;
    const sign = (showSign && val > 0) ? '+' : '';
    return `${sign}${val.toFixed(decimals)}%`;
}
