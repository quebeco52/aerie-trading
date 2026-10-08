import { formatPercent } from '../utils/formatters.js';

/**
 * Repaints the economy page's header from the coalesced market frame. Inflation is the quarter's annual rate, the
 * reading the history charts plot, so the header and the charts below it never print two figures for one thing.
 */
export function updateEconomyVitals(payload) {
    if (payload.economic_cycle) {
        const el = document.getElementById('market-economic-cycle');
        if (el) el.textContent = payload.economic_cycle;
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
    if (qeStatusEl) qeStatusEl.textContent = qeOn ? `−${(macro.qe_intensity * 10000).toFixed(0)} bps` : '';
    document.getElementById('qe-status-container')?.classList.toggle('hidden', !qeOn);

    const gapEl = document.getElementById('macro-output-gap');
    if (gapEl && Number.isFinite(macro.output_gap)) {
        const gapVal = macro.output_gap * 100;
        gapEl.textContent = formatPercent(macro.output_gap, 2);
        gapEl.classList.toggle('text-tertiary', gapVal < -1.0);
        gapEl.classList.toggle('text-secondary', gapVal > 1.0);
        gapEl.classList.toggle('text-on-surface', gapVal >= -1.0 && gapVal <= 1.0);
    }
}
