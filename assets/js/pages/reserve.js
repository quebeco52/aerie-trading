import { setupChartDefaults } from '../utils/chart-config.js';
import { showLoading, showError, hideStatus } from '../utils/fetch-status.js';
import { formatLarge } from '../utils/formatters.js';
import { renderReserveCharts, resizeReserveCharts, destroyReserveCharts, returnHistory, annualisedReturn } from '../reserve/reserve-charts.js';
import { onPageLoad } from '../utils/page-init.js';

let marketFrameHandler = null;
let resizeHandler = null;
/** The recorded quarters of the return index, and the live reading the trailing returns run up to. */
let recordedReturns = [];
let liveReturn = null;

function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

const pct = (v, d = 2) => `${(v * 100).toFixed(d)}%`;

/**
 * The trailing returns: a year and ten years real, and the whole record real and nominal, each annualised. They run
 * from the recorded quarters up to the live index, or up to the last quarter until the first live frame arrives.
 */
function paintReturns() {
    const now = liveReturn ?? recordedReturns[recordedReturns.length - 1];
    if (!now) return;

    const year = annualisedReturn(recordedReturns, now, 'real', 1);
    setText('reserve-return-year', year ? pct(year.rate) : '-');
    setText('reserve-return-year-detail', year ? `Over ${year.years.toFixed(1)} years, a year` : 'Under a year recorded');

    const decade = annualisedReturn(recordedReturns, now, 'real', 10);
    setText('reserve-return-decade', decade ? pct(decade.rate) : '-');

    const spanReal = annualisedReturn(recordedReturns, now, 'real');
    const spanNominal = annualisedReturn(recordedReturns, now, 'index');
    setText('reserve-return-span-real', spanReal ? pct(spanReal.rate) : '-');
    setText('reserve-return-span-nominal', spanNominal ? pct(spanNominal.rate) : '-');
    setText('reserve-return-span', spanReal ? `A year over ${spanReal.years.toFixed(1)} years` : 'Under a year recorded');
}

/**
 * Where a gauge's track starts and ends: the band twice over either side of policy, widened to keep the weight on it.
 * Presentation only; the band itself is the fund's and arrives on the element.
 */
function gaugeDomain(policy, band, weight) {
    const half = Math.max(2 * band, Math.abs(weight - policy) * 1.15);
    return [policy - half, policy + half];
}

function paintGauge(el, weight) {
    const policy = parseFloat(el.dataset.policy);
    const band = parseFloat(el.dataset.band);
    if (!Number.isFinite(policy) || !Number.isFinite(band) || !Number.isFinite(weight)) return;

    const [lo, hi] = gaugeDomain(policy, band, weight);
    const at = (v) => `${((v - lo) / (hi - lo)) * 100}%`;

    const bandEl = el.querySelector('[data-gauge-band]');
    if (bandEl) {
        bandEl.style.left = at(policy - band);
        bandEl.style.width = `${((2 * band) / (hi - lo)) * 100}%`;
    }
    const policyEl = el.querySelector('[data-gauge-policy]');
    if (policyEl) policyEl.style.left = at(policy);
    const marker = el.querySelector('[data-gauge-marker]');
    if (marker) marker.style.left = at(weight);

    const reading = el.querySelector('[data-gauge-reading]');
    if (reading) reading.textContent = pct(weight);

    const status = el.querySelector('[data-gauge-status]');
    if (status) {
        const gap = weight - policy;
        status.textContent = `${(Math.abs(gap) * 100).toFixed(2)}pp ${gap < 0 ? 'under' : 'over'} policy, `
            + `${Math.abs(gap) <= band ? 'inside' : 'outside'} the ±${(band * 100).toFixed(2)}pp band.`;
    }
}

/** Repaints the headline tiles, the allocation and the gauges from a live macro payload (wire keys). */
function paintReserve(m) {
    const dollarsPerGdp = m.sovereign_fund_dollars_per_gdp;
    if (!(dollarsPerGdp > 0)) return;

    const value = m.sovereign_fund_to_gdp * dollarsPerGdp * m.nominal_gdp_index;
    setText('reserve-value', formatLarge(value, '$'));
    setText('reserve-to-gdp', `${(m.sovereign_fund_to_gdp * 100).toFixed(0)}% of GDP`);
    setText('reserve-draw', `${pct(m.sovereign_fund_draw_to_gdp)} of GDP`);
    setText('reserve-draw-value', `${formatLarge(m.sovereign_fund_annual_draw, '$')} this year`);
    const stabilisation = m.sovereign_fund_stabilisation_to_gdp ?? 0;
    setText('reserve-stabilisation', stabilisation === 0
        ? 'No stabilisation'
        : `${stabilisation > 0 ? 'Slump spending +' : 'Boom saving '}${pct(Math.abs(stabilisation))} of GDP`);

    const lastYearDuty = m.sovereign_fund_stamp_duty_to_gdp;
    setText('reserve-duty', lastYearDuty > 0 ? `${pct(lastYearDuty)} of GDP` : '-');
    setText('reserve-duty-ytd', `${lastYearDuty > 0 ? 'Last year · ' : 'First year open · '}${formatLarge(m.sovereign_fund_stamp_duty_year_to_date, '$')} so far`);
    setText('reserve-ownership', `${pct(m.sovereign_fund_ownership_share)} of float`);

    const monthsLeft = m.sovereign_fund_rebalance_months_left;
    if (monthsLeft > 0) {
        const share = m.sovereign_fund_rebalance_share;
        const months = monthsLeft.toFixed(0);
        setText('reserve-programme', share > 0 ? 'Buying' : 'Trimming');
        setText('reserve-programme-detail', `${pct(Math.abs(share))} of float to go · ${months} ${months === '1' ? 'month' : 'months'} left`);
    } else {
        const last = m.last_sovereign_rebalance_at;
        setText('reserve-programme', 'None running');
        setText('reserve-programme-detail', last >= 0
            ? `Last one ${(12 * Math.max(0, m.total_time - last)).toFixed(0)} months ago`
            : 'None since inception');
    }

    setText('reserve-net-debt', `${(m.sovereign_net_debt_to_gdp * 100).toFixed(0)}% of GDP`);
    setText('reserve-gross-debt', `Gross ${(m.sovereign_debt_to_gdp * 100).toFixed(0)}% less the fund's bonds`);
    setText('reserve-bond-yield', pct(m.foreign_bond_yield));
    setText('reserve-return-assumed', pct(m.sovereign_fund_expected_real_return));
    if (m.sovereign_fund_return_index > 0) {
        liveReturn = { time: m.total_time, index: m.sovereign_fund_return_index, real: m.sovereign_fund_real_return_index };
        paintReturns();
    }

    const weight = m.sovereign_fund_domestic_weight;
    const equity = m.sovereign_fund_equity_share;
    const sleeves = { 'district': weight, 'foreign-equities': equity - weight, 'foreign-bonds': 1 - equity };
    Object.entries(sleeves).forEach(([key, w]) => {
        const segment = document.querySelector(`[data-sleeve-segment="${key}"]`);
        if (segment) segment.style.width = `${w * 100}%`;
        const row = document.querySelector(`[data-sleeve="${key}"]`);
        if (!row) return;
        const weightCell = row.querySelector('[data-sleeve-weight]');
        if (weightCell) weightCell.textContent = pct(w);
        const valueCell = row.querySelector('[data-sleeve-value]');
        if (valueCell) valueCell.textContent = formatLarge(w * value, '$');
    });

    const district = document.querySelector('[data-gauge="district"]');
    if (district) paintGauge(district, weight);
    const equityGauge = document.querySelector('[data-gauge="equity"]');
    if (equityGauge) paintGauge(equityGauge, equity);
}

function initReservePage() {
    const page = document.getElementById('reservePage');
    if (!page || page.dataset.initialized) return;
    page.dataset.initialized = 'true';

    cleanupPageResources();

    // The first paint of the gauges, from the server's reading; the live frame moves them from there.
    document.querySelectorAll('[data-gauge]').forEach(el => paintGauge(el, parseFloat(el.dataset.weight)));

    marketFrameHandler = (event) => {
        if (event.detail?.macro) paintReserve(event.detail.macro);
    };
    document.addEventListener('market:frame', marketFrameHandler);

    let isAborted = false;
    const grid = document.getElementById('reserveChartsGrid');
    if (grid) {
        setupChartDefaults();
        const bands = {
            domesticPolicy: parseFloat(grid.dataset.domesticPolicy),
            domesticBand: parseFloat(grid.dataset.domesticBand),
            equityPolicy: parseFloat(grid.dataset.equityPolicy),
            equityBand: parseFloat(grid.dataset.equityBand)
        };

        async function loadReports() {
            showLoading('reserveChartsStatus', 'Loading the fund\'s history…');
            try {
                const res = await fetch('/api/macro-reports');
                if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
                const data = await res.json();
                if (isAborted) return;

                hideStatus('reserveChartsStatus');
                recordedReturns = returnHistory(data);
                paintReturns();
                if (renderReserveCharts(data, bands) === 0) {
                    showLoading('reserveChartsStatus', 'No quarter has closed since the fund opened. The history fills in one point a quarter.');
                }
            } catch (err) {
                if (isAborted) return;
                console.error('Failed to load the fund history:', err);
                showError('reserveChartsStatus', 'Could not load the fund\'s history.', loadReports);
            }
        }
        loadReports();

        resizeHandler = () => setTimeout(resizeReserveCharts, 50);
        window.addEventListener('resize', resizeHandler);
    }

    document.addEventListener('turbo:before-render', () => {
        isAborted = true;
        cleanupPageResources();
    }, { once: true });
}

function cleanupPageResources() {
    destroyReserveCharts();
    recordedReturns = [];
    liveReturn = null;

    if (marketFrameHandler) {
        document.removeEventListener('market:frame', marketFrameHandler);
        marketFrameHandler = null;
    }
    if (resizeHandler) {
        window.removeEventListener('resize', resizeHandler);
        resizeHandler = null;
    }
}

onPageLoad(initReservePage);
