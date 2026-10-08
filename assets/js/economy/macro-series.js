// Series the economy page derives from the quarterly rows, kept free of the DOM and of Chart.js so each reads the
// engine's own recorded levels and nothing the page would have to keep a copy of.

/** Rows per year: the macro_report is written once a quarter. */
const QUARTERS_PER_YEAR = 4;

function reading(report, key) {
    const raw = report?.[key];
    if (raw === null || raw === undefined || raw === '') return null;
    const value = parseFloat(raw);
    return Number.isFinite(value) ? value : null;
}

/** Years between two rows, off their own clocks, or the quarter spacing when an older row has no clock. */
function yearsBetween(earlier, later, rows) {
    const from = reading(earlier, 'total_time');
    const to = reading(later, 'total_time');
    return from !== null && to !== null ? to - from : rows / QUARTERS_PER_YEAR;
}

/**
 * The quarter each row closes, as the server dates it, or how many quarters back it sits for a row recorded before
 * the clock was kept.
 */
export function quarterLabels(rows) {
    return rows.map((row, index) => row.quarter_label ?? (index === rows.length - 1 ? 'Now' : `-${rows.length - index - 1}Q`));
}

/**
 * Annualised growth of a level over the trailing year (up to four rows back), in percent, for each of the last
 * `count` rows of `reports`. `level` reads one row's level; a missing or non-positive level leaves a gap.
 */
export function trailingGrowth(reports, count, level) {
    const offset = reports.length - count;
    const growth = [];
    for (let index = offset; index < reports.length; index++) {
        const lookback = Math.min(QUARTERS_PER_YEAR, index);
        const now = level(reports[index]);
        const then = lookback > 0 ? level(reports[index - lookback]) : null;
        const years = lookback > 0 ? yearsBetween(reports[index - lookback], reports[index], lookback) : 0;
        growth.push(now > 0 && then > 0 && years > 0 ? (Math.log(now / then) / years) * 100 : null);
    }
    return growth;
}

/** Real GDP as the engine records it: nominal GDP over the GDP deflator. */
export function realGdpLevel(report) {
    const nominal = reading(report, 'nominal_gdp_index');
    const deflator = reading(report, 'gdp_deflator');
    return nominal !== null && deflator !== null && deflator > 0 ? nominal / deflator : null;
}

export function nominalGdpLevel(report) {
    return reading(report, 'nominal_gdp_index');
}

export function potentialGdpLevel(report) {
    return reading(report, 'potential_gdp_index');
}

/**
 * What a commodity adds to headline inflation each quarter, in basis points a year, for the last `count` rows.
 *
 * The recorded lag is the share of the consumer price LEVEL the commodity has reached; headline inflation takes its
 * rate of change, so a price that stays high adds a step to the level and then nothing more.
 */
export function costPushContribution(reports, count, field) {
    const offset = reports.length - count;
    const contribution = [];
    for (let index = offset; index < reports.length; index++) {
        const now = index > 0 ? reading(reports[index], field) : null;
        const then = index > 0 ? reading(reports[index - 1], field) : null;
        const years = index > 0 ? yearsBetween(reports[index - 1], reports[index], 1) : 0;
        contribution.push(now !== null && then !== null && years > 0 ? ((now - then) / years) * 10000 : null);
    }
    return contribution;
}

/**
 * The output gap's change over each quarter by group, in percentage points of potential output, one array per group
 * in `groups` order; a quarter the probe did not close is a gap.
 */
export function gapBreakdownSeries(rows, groups) {
    return groups.map(group => rows.map(row => {
        const value = row.gap_breakdown?.[group];
        return value === undefined || value === null ? null : value * 100;
    }));
}
