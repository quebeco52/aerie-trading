/**
 * The output-gap panel of the admin macro dashboard (templates/admin/macroDashboard.html.twig): polls what the running
 * ticker last published and the closed quarters in macro_report, and draws the decomposition tables and charts. The
 * two endpoints arrive in the page's aerie-data JSON. An admin page on EasyAdmin's Bootstrap theme, outside the
 * player pages' house style, so it keeps its own chart ink.
 */
import { readPageData } from '../js/utils/page-data.js';

function initGapPanel() {
    const root = document.getElementById('gapDebug');
    if (!root || typeof Chart === 'undefined') return;
    const { liveUrl, historyUrl } = readPageData('aerie-data');

    const status = document.getElementById('gapDebugStatus');
    const panel = document.getElementById('gapDebugPanel');
    const tiles = document.getElementById('gapDebugTiles');
    const body = document.getElementById('gapDebugBody');
    const foot = document.getElementById('gapDebugFoot');
    const title = document.getElementById('gapWindowTitle');
    const caption = document.getElementById('gapHistoryCaption');
    const expandAll = document.getElementById('gapExpandAll');

    // The page is dark, so the chart ink is set once against it rather than inherited from a light default.
    const INK = '#ffffff';
    const MUTED = '#c3c2b7';
    const GRID = 'rgba(195, 194, 183, 0.12)';
    const ZERO_RULE = 'rgba(195, 194, 183, 0.35)';

    // A stacked segment is separated from its neighbour by a gap in the SURFACE colour, so the surface is
    // read off the card rather than guessed — EasyAdmin's dark card is not the palette's reference dark.
    const surface = getComputedStyle(document.querySelector('#gapDebug .card') || document.body).backgroundColor || '#1a1a19';

    let families = {};
    let residualColour = '#8c8b80';
    let live = null;
    let history = [];
    let which = 'current';
    let range = 40;
    const expanded = new Set();
    let levelChart = null;
    let stackChart = null;

    // --- Inflation and policy accounts (App\Service\Macro\Recorder\MacroDiagnosticsProbe) ---
    let diag = null;
    let palette = [];
    let inflChart = null;
    let policyChart = null;

    const INFLATION_LABELS = {
        target: 'Target', expectationsSlip: 'Expectations slip', demandPull: 'Demand pull', wagePush: 'Wage push',
        servicesRepricing: 'Services repricing', goodsSupply: 'Goods supply', energyPassThrough: 'Energy pass-through', foodPassThrough: 'Food pass-through',
        importPrices: 'Import prices', stickyPriceLag: 'Sticky-price lag', noise: 'Noise', clamp: 'Clamp',
    };
    // The chart stacks the deviation from target, at most eight series in the validated slot order; noise and
    // clamp share the neutral residual, as the gap chart's diffusion does.
    const INFLATION_SERIES = [
        { label: 'Expectations slip', terms: ['expectationsSlip'] },
        { label: 'Demand pull', terms: ['demandPull'] },
        { label: 'Wage push', terms: ['wagePush'] },
        { label: 'Services repricing', terms: ['servicesRepricing'] },
        { label: 'Goods supply', terms: ['goodsSupply'] },
        { label: 'Energy & food', terms: ['energyPassThrough', 'foodPassThrough'] },
        { label: 'Import prices', terms: ['importPrices'] },
        { label: 'Sticky-price lag', terms: ['stickyPriceLag'] },
    ];
    const INFLATION_RESIDUAL = ['noise', 'clamp'];

    const POLICY_LABELS = {
        naturalRate: 'Natural rate r*', inflationTarget: 'Inflation target', coreInflationGap: 'Core inflation gap',
        expectationsGap: 'Expectations gap', outputGap: 'Output gap', productivitySeenThrough: 'Productivity seen through',
        inventoriesSeenThrough: 'Inventories seen through', spendingTimingSeenThrough: 'Spending timing seen through',
        longRateOffset: 'Long-rate offset', committeeMajority: 'Committee majority',
    };
    // Stacked as the deviation from neutral (r* + target), which the table carries on its own lines.
    const POLICY_SERIES = [
        { label: 'Core inflation gap', terms: ['coreInflationGap'] },
        { label: 'Expectations gap', terms: ['expectationsGap'] },
        { label: 'Output gap', terms: ['outputGap'] },
        { label: 'Productivity seen through', terms: ['productivitySeenThrough'] },
        { label: 'Inventories seen through', terms: ['inventoriesSeenThrough'] },
        { label: 'Spending timing seen through', terms: ['spendingTimingSeenThrough'] },
        { label: 'Long-rate offset', terms: ['longRateOffset'] },
        { label: 'Committee majority', terms: ['committeeMajority'] },
    ];
    const CONSTRAINT_LABELS = {
        lowerBound: 'At the lower bound', meeting: 'Meeting days',
        surprise: 'Meetings that surprised', targetCap: 'Target above the 20% cap',
    };
    // Quarters a shock of this kind struck are marked on the gap level chart.
    const MARKED_EVENTS = ['demandDisaster', 'creditCrisis', 'mainlandCrash'];

    /** Percentage points, signed, the unit the gap itself is read in. */
    function pp(value, digits) {
        return (value >= 0 ? '+' : '') + (value * 100).toFixed(digits === undefined ? 2 : digits);
    }

    function cell(row, text, className) {
        const td = row.insertCell();
        td.className = className || '';
        td.textContent = text;
        return td;
    }

    /**
     * A bar growing from a centre line: right when the channel adds demand, left when it drains it.
     * The sign is carried by the side and by the signed number, so colour is free to carry family
     * identity instead — the same colour the charts use for that family.
     */
    function bar(row, value, scale, colour, faded) {
        const track = row.insertCell();
        const axis = document.createElement('div');
        axis.className = 'd-flex';
        axis.style.height = faded ? '8px' : '12px';

        const left = document.createElement('div');
        left.className = 'd-flex justify-content-end';
        left.style.width = '50%';
        const right = document.createElement('div');
        right.className = 'd-flex';
        right.style.width = '50%';

        const fill = document.createElement('div');
        fill.style.width = (scale > 0 ? Math.min(100, (Math.abs(value) / scale) * 100) : 0) + '%';
        fill.style.backgroundColor = colour;
        fill.style.opacity = faded ? '0.55' : '1';
        fill.style.borderRadius = '2px';
        (value >= 0 ? right : left).appendChild(fill);

        axis.appendChild(left);
        axis.appendChild(right);
        track.appendChild(axis);
    }

    function swatch(colour) {
        const dot = document.createElement('span');
        dot.className = 'd-inline-block me-2 align-middle';
        dot.style.width = '10px';
        dot.style.height = '10px';
        dot.style.borderRadius = '2px';
        dot.style.backgroundColor = colour;
        return dot;
    }

    /** A window's contributions folded into the eight declared families, plus what each family holds. */
    function fold(contributions) {
        const folded = [];

        Object.keys(families).forEach(function (key) {
            const family = families[key];
            let subtotal = 0;
            const channels = [];

            family.channels.forEach(function (channel) {
                const value = contributions[channel];
                if (value === undefined) return;
                subtotal += value;
                channels.push({ name: channel, value: value });
            });

            channels.sort(function (a, b) { return b.value - a.value; });
            folded.push({ key: key, label: family.label, colour: family.colour, subtotal: subtotal, channels: channels });
        });

        return folded;
    }

    function tile(label, value, note) {
        const col = document.createElement('div');
        col.className = 'col-6 col-lg-3';
        col.innerHTML = '<div class="card h-100"><div class="card-body py-3">'
            + '<div class="text-uppercase text-muted" style="font-size:.7rem;letter-spacing:.06em"></div>'
            + '<div class="fs-4 fw-semibold mt-1"></div>'
            + '<div class="text-muted small"></div>'
            + '</div></div>';
        const slots = col.querySelectorAll('div.card-body > div');
        slots[0].textContent = label;
        slots[1].textContent = value;
        slots[2].textContent = note;
        return col;
    }

    function renderTable() {
        const window_ = live && live[which];

        body.replaceChildren();
        foot.replaceChildren();
        tiles.replaceChildren();

        if (!window_) {
            const row = body.insertRow();
            const only = row.insertCell();
            only.colSpan = 4;
            only.className = 'text-muted small';
            only.textContent = 'No window yet — the ticker has not closed a quarter.';
            return;
        }

        tiles.appendChild(tile('Output gap', (window_.closing_gap * 100).toFixed(2) + '%',
            'from ' + (window_.opening_gap * 100).toFixed(2) + '% at the open'));
        tiles.appendChild(tile('Change', pp(window_.change) + ' pp',
            window_.ticks + ' ticks · ' + window_.years.toFixed(3) + ' yr'));
        tiles.appendChild(tile('Drift', pp(window_.drift) + ' pp', 'every channel, netted'));
        tiles.appendChild(tile('Residual', pp(window_.diffusion) + ' pp', 'diffusion, no economic story'));

        const folded = fold(window_.contributions || {});
        const scale = folded.reduce(function (max, f) { return Math.max(max, Math.abs(f.subtotal)); }, 0);
        const years = window_.years > 0 ? window_.years : 0;
        const rate = function (value) { return years > 0 ? pp(value / years) : '—'; };

        folded.slice().sort(function (a, b) { return Math.abs(b.subtotal) - Math.abs(a.subtotal); }).forEach(function (family) {
            const open = expanded.has(family.key);
            const row = body.insertRow();
            row.style.cursor = 'pointer';
            row.addEventListener('click', function () {
                if (open) { expanded.delete(family.key); } else { expanded.add(family.key); }
                renderTable();
            });

            const label = row.insertCell();
            label.className = 'fw-semibold';
            label.appendChild(swatch(family.colour));
            label.appendChild(document.createTextNode((open ? '▾ ' : '▸ ') + family.label));

            bar(row, family.subtotal, scale, family.colour, false);
            cell(row, rate(family.subtotal), 'text-end font-monospace fw-semibold');
            cell(row, pp(family.subtotal), 'text-end font-monospace text-muted');

            if (!open) return;

            family.channels.forEach(function (channel) {
                const child = body.insertRow();
                cell(child, channel.name, 'font-monospace small text-muted ps-4');
                bar(child, channel.value, scale, family.colour, true);
                cell(child, rate(channel.value), 'text-end font-monospace small text-muted');
                cell(child, pp(channel.value), 'text-end font-monospace small text-muted');
            });
        });

        const external = window_.external || 0;
        [['drift', window_.drift], ['residual (diffusion)', window_.diffusion], ['clamp', window_.clamp],
         ['external', external], ['unexplained', window_.unexplained], ['Δ gap', window_.change]].forEach(function (entry, index) {
            const row = foot.insertRow();
            // Either residual away from zero means something moved the gap outside the channels.
            if ((entry[0] === 'unexplained' || entry[0] === 'external') && Math.abs(entry[1]) > 1e-9) row.className = 'table-warning';
            if (index === 5) row.className = 'border-top';
            cell(row, entry[0], 'fw-bold');
            cell(row, '');
            cell(row, index === 5 ? '' : rate(entry[1]), 'text-end font-monospace text-muted');
            cell(row, pp(entry[1]), 'text-end font-monospace fw-bold');
        });

        // The decomposition is only worth reading if it adds up to the move it claims to explain.
        const explained = window_.drift + window_.diffusion + window_.clamp + external + window_.unexplained;
        const note = foot.insertRow();
        const only = note.insertCell();
        only.colSpan = 4;
        only.className = 'text-muted small';
        only.textContent = Math.abs(explained - window_.change) < 1e-9
            ? '✓ the lines above reconstruct the quarter exactly'
            : '✗ accounting does not close — a channel reaches the drift and not the decomposition';
    }

    /** One term row: a label and its signed average in points. */
    function termRow(tbody, label, value, className) {
        const row = tbody.insertRow();
        if (className) row.className = className;
        cell(row, label, 'small');
        cell(row, pp(value), 'text-end font-monospace small');
    }

    /** The inflation, policy and shock tables for the window the toggle selects. */
    function renderDiagnostics() {
        const window_ = diag && diag[which];
        const statusLine = document.getElementById('diagStatus');
        const tables = document.getElementById('diagTables');
        ['inflBody', 'inflFoot', 'policyBody', 'policyFoot', 'constraintBody', 'eventBody'].forEach(function (id) {
            document.getElementById(id).replaceChildren();
        });

        if (!window_ || !window_.inflation || !window_.policy) {
            statusLine.textContent = 'No inflation or policy account for this window yet.';
            statusLine.classList.remove('d-none');
            tables.classList.add('d-none');
            return;
        }
        statusLine.classList.add('d-none');
        tables.classList.remove('d-none');

        const inflBody = document.getElementById('inflBody');
        const inflFoot = document.getElementById('inflFoot');
        Object.keys(window_.inflation.terms).forEach(function (key) {
            termRow(inflBody, INFLATION_LABELS[key] || key, window_.inflation.terms[key]);
        });
        const inflationSum = Object.values(window_.inflation.terms).reduce(function (a, b) { return a + b; }, 0);
        termRow(inflFoot, 'Average inflation', window_.inflation.average, 'fw-bold');
        termRow(inflFoot, Math.abs(inflationSum - window_.inflation.average) < 1e-9 ? '✓ terms sum to the average' : '✗ terms do not sum to the average', inflationSum - window_.inflation.average, 'text-muted');

        const policyBody = document.getElementById('policyBody');
        const policyFoot = document.getElementById('policyFoot');
        const policy = window_.policy;
        Object.keys(policy.terms || {}).forEach(function (key) {
            termRow(policyBody, POLICY_LABELS[key] || key, policy.terms[key]);
        });
        if (policy.averageTarget !== null) termRow(policyFoot, 'Average target (shadow rate)', policy.averageTarget, 'fw-bold');
        if (policy.averageRate !== null) termRow(policyFoot, 'Average policy rate', policy.averageRate, 'fw-bold');

        const constraintBody = document.getElementById('constraintBody');
        Object.keys(policy.constraints || {}).forEach(function (key) {
            const row = constraintBody.insertRow();
            if (policy.constraints[key] > 0) row.className = 'table-warning';
            cell(row, CONSTRAINT_LABELS[key] || key, 'small');
            cell(row, (policy.constraints[key] * 100).toFixed(0) + '%', 'text-end font-monospace small');
        });

        const eventBody = document.getElementById('eventBody');
        const events = window_.events || {};
        const names = Object.keys(events).sort(function (a, b) { return events[b].count - events[a].count; });
        names.forEach(function (name) {
            const row = eventBody.insertRow();
            cell(row, name, 'font-monospace small');
            cell(row, String(events[name].count), 'text-end font-monospace small');
            cell(row, events[name].sum.toFixed(4), 'text-end font-monospace small');
            cell(row, events[name].maxAbs.toFixed(4), 'text-end font-monospace small');
        });
        const shocks = window_.shocks || {};
        Object.keys(shocks).forEach(function (name) {
            const row = eventBody.insertRow();
            cell(row, name + ' (summed innovation)', 'font-monospace small text-muted');
            cell(row, '—', 'text-end small text-muted');
            cell(row, shocks[name].toFixed(4), 'text-end font-monospace small text-muted');
            cell(row, '—', 'text-end small text-muted');
        });
        if (names.length === 0 && Object.keys(shocks).length === 0) {
            const row = eventBody.insertRow();
            const only = row.insertCell();
            only.colSpan = 4;
            only.className = 'text-muted small';
            only.textContent = 'No jumps drawn this window.';
        }
    }

    function slice() {
        return range > 0 ? history.slice(-range) : history;
    }

    function baseScales(showX, unit) {
        return {
            x: {
                stacked: true,
                grid: { color: GRID, drawTicks: false },
                border: { color: GRID },
                ticks: {
                    display: showX, color: MUTED, maxRotation: 0, autoSkip: true, maxTicksLimit: 12,
                    font: { size: 10 },
                },
            },
            y: {
                stacked: true,
                grid: {
                    color: function (context) { return context.tick.value === 0 ? ZERO_RULE : GRID; },
                    drawTicks: false,
                },
                border: { display: false },
                // The two charts carry different units — a level in percent, a contribution in points of
                // gap — so each axis says which, rather than leaving the reader to infer it from the caption.
                ticks: {
                    color: MUTED, font: { size: 10 },
                    callback: function (v) { return (v * 100).toFixed(1) + unit; },
                },
            },
        };
    }

    /** The level chart plots readings, not parts of a whole, so its markers must sit on the line rather than stack on it. */
    function levelScales() {
        const scales = baseScales(false, '%');
        scales.x.stacked = false;
        scales.y.stacked = false;
        return scales;
    }

    function renderCharts() {
        const rows = slice();
        const labels = rows.map(function (row) { return 'y' + (row.time || 0).toFixed(1); });

        // A ticker that has not closed a quarter yet has nothing to plot, and an empty chart with axes
        // reads as "the gap was zero" rather than "there is no history".
        if (rows.length === 0) {
            caption.textContent = 'No closed quarters yet — the first lands at the end of this simulation quarter.';
            return;
        }

        caption.textContent = 'Level at the close of each quarter — ' + rows.length + ' quarters.';

        const levelData = {
            labels: labels,
            datasets: [{
                label: 'Output gap',
                data: rows.map(function (row) { return row.closing_gap; }),
                borderColor: '#3987e5',
                borderWidth: 2,
                pointRadius: 0,
                pointHitRadius: 12,
                tension: 0.25,
                fill: false,
            }],
        };

        // Quarters a disaster or a crisis struck, as markers on the level so a slump can be read against its cause.
        const struck = rows.map(function (row) {
            const events = (row.diagnostics && row.diagnostics.events) || {};
            return MARKED_EVENTS.some(function (name) { return events[name]; }) ? row.closing_gap : null;
        });
        const hasMarkers = struck.some(function (value) { return value !== null; });
        if (hasMarkers) {
            levelData.datasets.push({
                label: 'Demand disaster or credit crisis',
                data: struck,
                type: 'line',
                showLine: false,
                pointStyle: 'triangle',
                pointRadius: 7,
                pointHoverRadius: 9,
                pointBackgroundColor: INK,
                pointBorderColor: surface,
                pointBorderWidth: 2,
            });
        }

        const stackSets = Object.keys(families).map(function (key) {
            const family = families[key];
            return {
                label: family.label,
                backgroundColor: family.colour,
                borderColor: surface,
                borderWidth: 1,
                borderSkipped: false,
                maxBarThickness: 18,
                data: rows.map(function (row) {
                    const contributions = row.contributions || {};
                    return family.channels.reduce(function (sum, channel) {
                        return sum + (contributions[channel] || 0);
                    }, 0);
                }),
            };
        });

        // The residual is stacked with them so the bars reconcile to the line, but wears the neutral:
        // it is not a demand channel and must not be coloured like one.
        stackSets.push({
            label: 'Residual (diffusion, clamp, external)',
            backgroundColor: residualColour,
            borderColor: surface,
            borderWidth: 1,
            borderSkipped: false,
            maxBarThickness: 18,
            data: rows.map(function (row) { return row.diffusion + row.clamp + (row.external || 0) + row.unexplained; }),
        });

        const tooltip = {
            mode: 'index', intersect: false,
            callbacks: { label: function (c) { return c.dataset.label + '  ' + pp(c.parsed.y) + ' pp'; } },
        };

        if (levelChart) {
            levelChart.data = levelData;
            levelChart.options.plugins.legend.display = hasMarkers;
            levelChart.update('none');
        } else {
            levelChart = new Chart(document.getElementById('gapLevelChart'), {
                type: 'line',
                data: levelData,
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    interaction: { mode: 'index', intersect: false },
                    // One series, so the title above names it; the event markers, when there are any, get a legend.
                    plugins: {
                        legend: { display: hasMarkers, position: 'bottom', labels: { color: MUTED, boxWidth: 10, boxHeight: 10, font: { size: 10 }, usePointStyle: true } },
                        tooltip: tooltip,
                    },
                    scales: levelScales(),
                },
            });
        }

        const stackData = { labels: labels, datasets: stackSets };

        if (stackChart) {
            stackChart.data = stackData;
            stackChart.update('none');
        } else {
            stackChart = new Chart(document.getElementById('gapStackChart'), {
                type: 'bar',
                data: stackData,
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: MUTED, boxWidth: 10, boxHeight: 10, font: { size: 10 }, usePointStyle: false },
                        },
                        tooltip: tooltip,
                    },
                    scales: baseScales(true, ' pp'),
                },
            });
        }

        inflChart = renderTermStack('inflStackChart', inflChart, rows, labels, INFLATION_SERIES, INFLATION_RESIDUAL,
            function (row) { return row.diagnostics && row.diagnostics.inflation ? row.diagnostics.inflation.terms : null; }, tooltip);
        policyChart = renderTermStack('policyStackChart', policyChart, rows, labels, POLICY_SERIES, [],
            function (row) { return row.diagnostics && row.diagnostics.policy ? row.diagnostics.policy.terms : null; }, tooltip);
    }

    /**
     * A stacked-bar history of one account's terms, series drawn from the validated slots in order. A quarter
     * recorded before the account existed plots as a gap, not as a zero.
     */
    function renderTermStack(canvasId, chart, rows, labels, series, residualTerms, termsOf, tooltip) {
        const datasets = series.map(function (entry, index) {
            return {
                label: entry.label,
                backgroundColor: palette[index] || residualColour,
                borderColor: surface,
                borderWidth: 1,
                borderSkipped: false,
                maxBarThickness: 18,
                data: rows.map(function (row) {
                    const terms = termsOf(row);
                    if (!terms) return null;
                    return entry.terms.reduce(function (sum, key) { return sum + (terms[key] || 0); }, 0);
                }),
            };
        });
        if (residualTerms.length > 0) {
            datasets.push({
                label: 'Residual (noise, clamp)',
                backgroundColor: residualColour,
                borderColor: surface,
                borderWidth: 1,
                borderSkipped: false,
                maxBarThickness: 18,
                data: rows.map(function (row) {
                    const terms = termsOf(row);
                    if (!terms) return null;
                    return residualTerms.reduce(function (sum, key) { return sum + (terms[key] || 0); }, 0);
                }),
            });
        }

        const data = { labels: labels, datasets: datasets };
        if (chart) {
            chart.data = data;
            chart.update('none');
            return chart;
        }

        return new Chart(document.getElementById(canvasId), {
            type: 'bar',
            data: data,
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { color: MUTED, boxWidth: 10, boxHeight: 10, font: { size: 10 }, usePointStyle: false } },
                    tooltip: tooltip,
                },
                scales: baseScales(true, ' pp'),
            },
        });
    }

    root.querySelectorAll('[data-gap-window]').forEach(function (button) {
        button.addEventListener('click', function () {
            which = button.dataset.gapWindow;
            root.querySelectorAll('[data-gap-window]').forEach(function (other) {
                other.classList.toggle('active', other === button);
            });
            title.textContent = button.textContent;
            renderTable();
            renderDiagnostics();
        });
    });

    root.querySelectorAll('[data-gap-range]').forEach(function (button) {
        button.addEventListener('click', function () {
            range = parseInt(button.dataset.gapRange, 10);
            root.querySelectorAll('[data-gap-range]').forEach(function (other) {
                other.classList.toggle('active', other === button);
            });
            renderCharts();
        });
    });

    expandAll.addEventListener('click', function () {
        if (expanded.size > 0) {
            expanded.clear();
            expandAll.textContent = 'Expand all';
        } else {
            Object.keys(families).forEach(function (key) { expanded.add(key); });
            expandAll.textContent = 'Collapse all';
        }
        renderTable();
    });

    async function pollLive() {
        if (document.hidden) return;
        try {
            const response = await fetch(liveUrl);
            if (!response.ok) throw new Error(String(response.status));
            const payload = await response.json();
            families = payload.families || {};
            residualColour = payload.residualColour || residualColour;
            palette = payload.palette || palette;
            live = payload.live;
            diag = payload.diagnostics || null;
        } catch (error) {
            live = null;
        }

        if (!live || (!live.current && !live.previous)) {
            status.classList.remove('d-none');
            status.textContent = live ? 'No window yet — the ticker has not closed a quarter.' : 'Waiting for the ticker…';
            panel.classList.add('d-none');
            return;
        }

        status.classList.add('d-none');
        panel.classList.remove('d-none');
        renderTable();
        renderDiagnostics();
    }

    async function pollHistory() {
        if (document.hidden) return;
        try {
            const response = await fetch(historyUrl);
            if (!response.ok) throw new Error(String(response.status));
            history = (await response.json()).quarters || [];
        } catch (error) {
            return;
        }
        if (history.length > 0 && Object.keys(families).length > 0) renderCharts();
    }

    pollLive().then(pollHistory);
    setInterval(pollLive, 1000);
    // A quarter closes every ~900 ticks, so the history does not need the live cadence.
    setInterval(pollHistory, 10000);
}

initGapPanel();
