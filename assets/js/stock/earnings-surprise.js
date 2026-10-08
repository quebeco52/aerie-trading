/**
 * Reported earnings per share against the consensus the market held going into each report, as the report filed
 * both (EarningsReportSubscriber: the consensus the surprise was struck against, and the EPS it was struck on).
 * A report filed before the consensus was carried has none, and plots as a gap. No DOM here, so the series can be
 * checked on their own.
 */

/** Quarters the quarterly view shows, as the other financial charts do. */
export const QUARTERS_SHOWN = 12;

/** Years the annual view shows, as the other financial charts do. */
export const YEARS_SHOWN = 12;

/** Quarters summed into a year. */
const QUARTERS_PER_YEAR = 4;

const numberOrNull = (value) => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = parseFloat(value);
    return Number.isFinite(parsed) ? parsed : null;
};

/** One report's EPS as filed; a report filed before EPS was carried falls back on net income over its share count. */
export function reportedEps(row) {
    const filed = numberOrNull(row.reported_eps);
    if (filed !== null) return filed;
    const shares = numberOrNull(row.shares);
    const netIncome = numberOrNull(row.net_income);

    return shares !== null && shares > 0 && netIncome !== null ? netIncome / shares : null;
}

/** The surprise as a fraction of the consensus; null without a consensus or against a zero one. */
export function surpriseFraction(reported, consensus) {
    if (reported === null || consensus === null || consensus === 0) return null;

    return (reported - consensus) / Math.abs(consensus);
}

/**
 * Reported and consensus EPS per period, aligned with the other financial charts: the last twelve quarters, or the
 * last twelve years summed from the four quarters ending at each year-end report. A year missing any quarter's
 * consensus has none.
 *
 * @returns {{reported: Array<number|null>, consensus: Array<number|null>, surprise: Array<number|null>}}
 */
export function earningsAgainstConsensus(reports, timeframe) {
    const reported = [];
    const consensus = [];

    if (timeframe === '12Q') {
        reports.slice(-QUARTERS_SHOWN).forEach((row) => {
            reported.push(reportedEps(row));
            consensus.push(numberOrNull(row.consensus_eps));
        });
    } else {
        let years = 0;
        for (let i = reports.length - 1; i >= 0 && years < YEARS_SHOWN; i -= QUARTERS_PER_YEAR) {
            const quarters = reports.slice(Math.max(0, i - QUARTERS_PER_YEAR + 1), i + 1);
            const eps = quarters.map(reportedEps);
            const forecasts = quarters.map(row => numberOrNull(row.consensus_eps));
            reported.unshift(eps.includes(null) ? null : eps.reduce((sum, v) => sum + v, 0));
            consensus.unshift(quarters.length < QUARTERS_PER_YEAR || forecasts.includes(null) ? null : forecasts.reduce((sum, v) => sum + v, 0));
            years++;
        }
    }

    return { reported, consensus, surprise: reported.map((value, index) => surpriseFraction(value, consensus[index])) };
}
