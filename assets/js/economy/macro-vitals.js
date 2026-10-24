import { formatBps, formatPercent } from '../utils/formatters.js';

/**
 * Repaints the economy page's header from the coalesced market frame. Inflation is the quarter's annual rate, the
 * reading the history charts plot, so the header and the charts below it never print two figures for one thing.
 */
export function updateEconomyVitals(payload) {
    const cycle = payload.economic_cycle;
    if (cycle) {
        const el = document.getElementById('market-economic-cycle');
        if (el) el.textContent = cycle;
        // The output gap takes the cycle's colours, so its band is the one MacroStateDTO::economicCycleLabel() draws.
        const gapEl = document.getElementById('macro-output-gap');
        if (gapEl) {
            gapEl.classList.toggle('text-tertiary', cycle === 'Bust');
            gapEl.classList.toggle('text-secondary', cycle === 'Boom');
            gapEl.classList.toggle('text-on-surface', cycle !== 'Bust' && cycle !== 'Boom');
        }
    }

    const macro = payload.macro;
    if (!macro) return;

    const setPercent = (id, value) => {
        const el = document.getElementById(id);
        if (el && Number.isFinite(value)) el.textContent = formatPercent(value, 2);
    };
    setPercent('macro-inflation', macro.inflation_ema);
    setPercent('macro-policy-rate', macro.policy_rate);
    setPercent('macro-yield', macro.yield_10y);

    // Only the text and the tone change here; the template owns every other class.
    const qeOn = Boolean(macro.qe_active);
    const qeStatusEl = document.getElementById('macro-qe-status');
    if (qeStatusEl) qeStatusEl.textContent = qeOn ? `−${formatBps(macro.balance_sheet_intensity)}` : '';
    document.getElementById('qe-status-container')?.classList.toggle('hidden', !qeOn);

    setPercent('macro-output-gap', macro.output_gap);
}
