/**
 * The income statement, laid out from the report columns the engine files (EarningsEngine, EarningsReportSubscriber).
 *
 * Every line is a stored figure except two that close the statement by its own identities: the charges between
 * operating costs and EBITDA (write-downs, credit provisions and severance, which the engine books after the cash cost
 * base), and the items between tax and net income that no column states (accrual timing). Each column therefore
 * adds down to the net income filed. No DOM here, so the arithmetic and the markup can be checked on their own.
 */

import { formatLarge, formatCurrency } from '../utils/formatters.js';

/** Quarters shown side by side, then summed into the trailing year. */
export const STATEMENT_QUARTERS = 4;

/** A closing line smaller than this share of revenue in every column is rounding, and is left off the statement. */
export const MINOR_LINE_SHARE_OF_REVENUE = 0.0005;

const num = (row, key) => {
    const value = parseFloat(row[key]);
    return Number.isFinite(value) ? value : 0;
};
const present = (row, key) => row[key] !== null && row[key] !== undefined && row[key] !== '';

/** One report's lines, each a number; `eps` is null when the report states no share count. */
export function statementLines(row, isReit = false) {
    const revenue = num(row, 'revenue');
    const operatingCosts = num(row, 'operating_costs');
    const ebitda = num(row, 'ebitda');
    const depreciation = num(row, 'depreciation');
    const ebit = num(row, 'ebit');
    const interestIncome = num(row, 'interest_income');
    const interestExpense = num(row, 'interest_expense');
    const preTax = num(row, 'pre_tax_income');
    const tax = num(row, 'tax_paid');
    const bankLevy = num(row, 'bank_levy');
    const goodwillImpairment = num(row, 'goodwill_impairment');
    // A trust reports funds from operations: net income with the property depreciation added back.
    const depreciationAddBack = isReit ? depreciation : 0;
    const netIncome = num(row, 'net_income');
    const shares = num(row, 'shares');

    return {
        revenue,
        operatingCosts: -operatingCosts,
        charges: -(revenue - operatingCosts - ebitda),
        ebitda,
        depreciation: -depreciation,
        ebit,
        interestIncome,
        interestExpense: -interestExpense,
        preTax,
        tax: -tax,
        bankLevy: -bankLevy,
        goodwillImpairment: -goodwillImpairment,
        depreciationAddBack,
        otherItems: netIncome - (preTax - tax - bankLevy - goodwillImpairment + depreciationAddBack),
        netIncome,
        eps: shares > 0 ? netIncome / shares : null,
    };
}

/** Whether a report carries the lines the statement is built from (older reports stored no EBITDA). */
export function hasStatement(row) {
    return !!row && present(row, 'ebitda') && present(row, 'depreciation');
}

/**
 * The last quarters and their trailing-year sum, with the rows to print.
 *
 * @param {object[]} reports oldest first, as /api/fundamentals returns them
 * @param {{isReit?: boolean}} options
 * @returns {{columns: {label: string, lines: object}[], rows: {key: string, label: string, total?: boolean, signed?: boolean, eps?: boolean}[]}|null}
 */
export function buildIncomeStatement(reports, { isReit = false } = {}) {
    const filed = (reports || []).filter(hasStatement).slice(-STATEMENT_QUARTERS);
    if (filed.length === 0) return null;

    const columns = filed.map((row, index) => {
        const age = filed.length - 1 - index;
        return { label: row.period || (age === 0 ? 'Latest' : `-${age}Q`), lines: statementLines(row, isReit) };
    });
    if (columns.length === STATEMENT_QUARTERS) {
        const lines = {};
        for (const key of Object.keys(columns[0].lines)) {
            const values = columns.map(column => column.lines[key]);
            // Trailing EPS is the sum of the four quarters' EPS, as filings state it; one missing leaves it unknown.
            lines[key] = values.some(v => v === null) ? null : values.reduce((sum, v) => sum + v, 0);
        }
        columns.push({ label: 'Trailing year', lines, trailing: true });
    }

    const material = key => columns.some(column => Math.abs(column.lines[key]) > MINOR_LINE_SHARE_OF_REVENUE * Math.abs(column.lines.revenue));
    const rows = [
        { key: 'revenue', label: 'Revenue', total: true },
        { key: 'operatingCosts', label: 'Operating costs' },
        ...(material('charges') ? [{ key: 'charges', label: 'Write-downs, provisions and severance' }] : []),
        { key: 'ebitda', label: 'EBITDA', total: true, signed: true },
        { key: 'depreciation', label: 'Depreciation and amortisation' },
        { key: 'ebit', label: 'Operating income', total: true, signed: true },
        { key: 'interestIncome', label: 'Interest income' },
        { key: 'interestExpense', label: 'Interest expense' },
        { key: 'preTax', label: 'Pre-tax income', total: true, signed: true },
        { key: 'tax', label: 'Income tax' },
        ...(material('bankLevy') ? [{ key: 'bankLevy', label: 'Bank levy' }] : []),
        ...(material('goodwillImpairment') ? [{ key: 'goodwillImpairment', label: 'Goodwill impairment' }] : []),
        ...(isReit ? [{ key: 'depreciationAddBack', label: 'Depreciation added back' }] : []),
        ...(material('otherItems') ? [{ key: 'otherItems', label: 'Other items, net' }] : []),
        { key: 'netIncome', label: isReit ? 'Funds from operations' : 'Net income', total: true, signed: true },
        { key: 'eps', label: isReit ? 'Per share' : 'Earnings per share', signed: true, eps: true },
    ];

    return { columns, rows };
}

/** The table's markup: one column per quarter and the trailing year, totals ruled off, a loss in red. */
export function incomeStatementTableHtml(statement) {
    const head = `<thead><tr class="table-head"><th class="py-2"></th>${statement.columns
        .map(column => `<th class="py-2 pl-4 text-right whitespace-nowrap">${column.label}</th>`).join('')}</tr></thead>`;
    const body = statement.rows.map(row => {
        const cells = statement.columns.map(column => {
            const value = column.lines[row.key];
            const tone = row.signed && value !== null && value < 0 ? 'text-tertiary' : 'text-on-surface';
            const text = row.eps ? formatCurrency(value) : formatLarge(value, '$');
            return `<td class="py-1 pl-4 text-right font-mono tabular-nums whitespace-nowrap ${row.total ? 'font-semibold' : ''} ${tone}">${text}</td>`;
        }).join('');
        const label = row.total ? 'font-semibold text-on-surface' : 'pl-3 text-on-surface-variant';
        return `<tr class="${row.total ? 'border-t border-outline-variant/20' : ''}"><td class="py-1 ${label}">${row.label}</td>${cells}</tr>`;
    }).join('');

    return `${head}<tbody class="text-xs">${body}</tbody>`;
}
