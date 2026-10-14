#!/usr/bin/env python3
"""Recession-regime diagnosis: python3 analyse.py [arm=D1]. Reads runs/<arm>-*.tick.csv (one row a tick: tick, gap, nairu,
u, u_ema, sahm, ebp, latch, potential) and runs/<arm>-*.macro.jsonl (quarter rows), TPY 360, year 1 dropped.
US: us/UNRATE.csv, us/SAHMREALTIME.csv, us/USREC.csv (FRED), ../us_gdpc1.csv, ../us_gdppot.csv, ../ebp.csv (GZ), 1985-2019.
Sections A-F as in RUN.md. Prints per-seed rows, then mean (se) over seeds beside the US value."""
import csv, glob, json, math, os, sys

H = os.path.dirname(os.path.abspath(__file__))
ARM = sys.argv[1] if len(sys.argv) > 1 else 'D1'
TPY, TPM, TPQ = 360, 30, 90
SAHM = 0.005
DT = 1.0 / TPY
NAT, NMIN, NMAX, FRIC = 0.04, 0.025, 0.08, 0.032
EMA_W = 1.0 - math.exp(-DT / 0.25)


def mean(x): return sum(x) / len(x) if x else float('nan')
def sd(x):
    if len(x) < 2: return float('nan')
    m = mean(x); return math.sqrt(sum((a - m) ** 2 for a in x) / (len(x) - 1))
def acf1(x):
    m = mean(x); return sum((x[k] - m) * (x[k + 1] - m) for k in range(len(x) - 1)) / sum((a - m) ** 2 for a in x)


def tech_rec(level):
    """regime_diag.py's nber(): two consecutive negative q/q quarters; marks peak+1 .. trough."""
    g = [level[k] / level[k - 1] - 1.0 for k in range(1, len(level))]
    rec = [False] * len(level); k = 0
    while k < len(g) - 1:
        if g[k] < 0 and g[k + 1] < 0:
            j = k
            while j < len(g) and g[j] < 0:
                rec[j + 1] = True; j += 1
            k = j
        else:
            k += 1
    return rec


def onsets(flags):
    return [k for k in range(len(flags)) if flags[k] and (k == 0 or not flags[k - 1])]


def episodes(flags, start):
    """Runs of True that intersect [start, end): count and mean length in samples (truncated at start/end)."""
    runs, k, n = [], start, len(flags)
    while k < n:
        if flags[k]:
            j = k
            while j < n and flags[j]: j += 1
            runs.append(j - k); k = j
        else:
            k += 1
    return len(runs), (mean(runs) if runs else 0.0)


def labour(gap, nairu_rec, u_ema_rec, speed_up=3.0, speed_dn=1.5, okun=0.7, smooth=False, fixed_nairu=None, resim_nairu=True):
    """The labour filter of LaborMarketSubsystem::calculateUnemployment, tick by tick. Tick k reads tick k-1's gap
    (the gap is updated after unemployment in updateMacroState). Returns u and the month-end Sahm indicator held per tick."""
    n = len(gap); u, nairu, uema, g_prev = NAT, NAT, NAT, 0.0
    us, sahm, months, cur, roll = [], [], [], 0.0, []
    for k in range(n):
        if fixed_nairu is not None:
            nairu = fixed_nairu
        elif resim_nairu:
            nairu += 0.10 * max(0.0, uema - nairu - 0.005) * DT
            nairu -= max(0.0, nairu - NAT) * 0.10 * DT
            nairu = max(NMIN, min(NMAX, nairu))
        else:
            nairu = nairu_rec[k]
        gin = g_prev
        if smooth:
            roll.append(g_prev); roll = roll[-TPQ:]; gin = mean(roll)
        if gin <= 0.0:
            tgt = nairu - okun * gin
        else:
            rng = max(0.001, nairu - FRIC); tgt = FRIC + rng * math.exp(-(okun * gin) / rng)
        sp = speed_up if tgt - u > 0 else speed_dn
        u += sp * (tgt - u) * DT
        if (k + 1) % TPM == 0:
            months.append(u); months = months[-15:]
            av = [sum(months[e - 3:e]) / 3 for e in range(3, len(months) + 1)]
            c = av.pop() if av else None
            cur = 0.0 if c is None or not av else max(0.0, c - min(av))
        us.append(u); sahm.append(cur)
        uema += EMA_W * (u - uema); g_prev = gap[k]
    return us, sahm


def false_trips(trig_q, rec_q_flags, window):
    """Share of triggers (quarter indices) with no technical-recession start within +-window quarters."""
    starts = [k for k in onsets(rec_q_flags)]
    if not trig_q: return float('nan'), 0
    f = sum(1 for t in trig_q if not any(abs(s - t) <= window for s in starts))
    return f / len(trig_q), len(trig_q)


# ---------------- sim ----------------
per = {}
for f in sorted(glob.glob(f'{H}/runs/{ARM}-*.tick.csv')):
    seed = int(os.path.basename(f).split('.')[0].split('-')[1])
    T = [list(map(float, l.split(','))) for l in open(f) if l.strip()]
    if len(T) < 21 * TPY: continue
    gap = [r[1] for r in T]; nairu = [r[2] for r in T]; u = [r[3] for r in T]; uema = [r[4] for r in T]
    sahm = [r[5] for r in T]; ebp = [r[6] for r in T]; lat = [r[7] > 0 for r in T]; pot = [r[8] for r in T]
    n = len(T); s0 = TPY; yrs = (n - s0) / TPY
    S = [x >= SAHM for x in sahm]
    m = {}
    # A
    w = range(s0, n)
    m['sahm_t'] = mean([S[k] for k in w]); m['latch_t'] = mean([lat[k] for k in w])
    m['both'] = mean([S[k] and lat[k] for k in w]); m['sahm_only'] = mean([S[k] and not lat[k] for k in w])
    m['latch_only'] = mean([lat[k] and not S[k] for k in w]); m['union'] = mean([S[k] or lat[k] for k in w])
    ne, dur = episodes(S, s0); m['sahm_ep/c'] = 100 * ne / yrs; m['sahm_dur_m'] = dur / TPM
    ne, dur = episodes(lat, s0); m['latch_ep/c'] = 100 * ne / yrs; m['latch_dur_m'] = dur / TPM
    # B: quarterly averages of exact real GDP
    nq = n // TPQ
    rg = [mean([pot[k] * (1 + gap[k]) for k in range(q * TPQ, (q + 1) * TPQ)]) for q in range(nq)]
    pq = [mean(pot[q * TPQ:(q + 1) * TPQ]) for q in range(nq)]
    gq = [mean(gap[q * TPQ:(q + 1) * TPQ]) for q in range(nq)]
    rec = tech_rec(rg); q0 = 4
    m['techrec'] = mean(rec[q0:])
    rows = [json.loads(l) for l in open(f.replace('.tick.csv', '.macro.jsonl')) if l.strip()]
    p, cpi = 1.0, []
    for r in rows:
        p *= 1.0 + r['infl'] / 4.0; cpi.append(r['nominal_gdp'] / p)
    rec_cpi = tech_rec(cpi)
    m['techrec_cpi'] = mean(rec_cpi[q0:])
    gr = [400 * math.log(rg[k] / rg[k - 1]) for k in range(q0 + 1, nq)]
    pg = [400 * math.log(pq[k] / pq[k - 1]) for k in range(q0 + 1, nq)]
    m['rgdp_acf1'] = acf1(gr); m['neg_q'] = mean([x < 0 for x in gr]); m['techrec_ep/c'] = 100 * len([k for k in onsets(rec) if k >= q0]) / ((nq - q0) / 4)
    m['rgdp_g%'] = mean(gr); m['rgdp_sd%'] = sd(gr); m['pot_g%'] = mean(pg); m['pot_sd%'] = sd(pg)
    m['dgap_sd%'] = sd([100 * (gq[k] - gq[k - 1]) for k in range(q0 + 1, nq)])
    m['rgdp_cpi_sd%'] = sd([400 * math.log(cpi[k] / cpi[k - 1]) for k in range(q0 + 1, len(cpi))])
    # C: month-end unemployment
    um = [u[k] for k in range(TPM - 1, n, TPM)][12:]
    d1 = [100 * (um[k] - um[k - 1]) for k in range(1, len(um))]; d12 = [100 * (um[k] - um[k - 12]) for k in range(12, len(um))]
    m['du1_sd'] = sd(d1); m['du12_sd'] = sd(d12); m['ratio'] = m['du12_sd'] / m['du1_sd']
    m['u_sd%'] = 100 * sd(um); m['gap_sd%'] = 100 * sd(gq[q0:])
    # D: Sahm triggers vs technical recessions on exact real GDP
    trig = [k // TPQ for k in onsets(S) if k >= s0]
    m['false_trip'], m['n_trig'] = false_trips(trig, rec, 4)
    # E: offline filter counterfactuals
    u0, s0v = labour(gap, nairu, uema)
    m['E0_maxdu_bp'] = 1e4 * max(abs(a - b) for a, b in zip(u0, u))
    m['E0_maxdsahm_bp'] = 1e4 * max(abs(a - b) for a, b in zip(s0v, sahm))
    for name, kw in [('E0', {}), ('E1sym2', {'speed_up': 2.0, 'speed_dn': 2.0}), ('E2smooth', {'smooth': True}),
                     ('E3nairu04', {'fixed_nairu': NAT}), ('E4okun45', {'okun': 0.45})]:
        _, sv = labour(gap, nairu, uema, **kw) if name != 'E0' else (u0, s0v)
        fl = [x >= SAHM for x in sv]
        m[f'{name}_sahm'] = mean(fl[s0:]); m[f'{name}_ep/c'] = 100 * episodes(fl, s0)[0] / yrs
    # F: EBP and the latch
    e = ebp[s0:]
    m['ebp_mean%'] = 100 * mean(e); m['ebp_sd%'] = 100 * sd(e); m['ebp_ge1'] = mean([x >= 0.010 for x in e])
    m['ebp_acf1q'] = acf1([ebp[k] for k in range(s0 + TPQ - 1, n, TPQ)])
    lon = [k for k in onsets(lat) if k >= s0]
    m['latch_entry/c'] = 100 * len(lon) / yrs
    recq = set(q for q in range(nq) if rec[q])
    orphan = [k for k in lon if not any(S[j] for j in range(max(0, k - 2 * TPQ), min(n, k + 2 * TPQ)))
              and not any((k // TPQ + d) in recq for d in range(-2, 3))]
    m['latch_orphan'] = len(orphan) / len(lon) if lon else float('nan')
    per[seed] = m

# ---------------- US 1985-2019 ----------------
def fred(path, col):
    out = {}
    for r in csv.DictReader(open(path)):
        v = r[col]
        if v not in ('', '.'): out[r['observation_date'][:7]] = float(v)
    return out

US = {}
un = fred(f'{H}/us/UNRATE.csv', 'UNRATE'); sr = fred(f'{H}/us/SAHMREALTIME.csv', 'SAHMREALTIME'); ur = fred(f'{H}/us/USREC.csv', 'USREC')
gd = fred(f'{H}/../us_gdpc1.csv', 'GDPC1'); gp = fred(f'{H}/../us_gdppot.csv', 'GDPPOT')
eb = {}
for r in csv.DictReader(open(f'{H}/../ebp.csv')):
    mo, _, yr = r['date'].split('/'); eb[f'{yr}-{int(mo):02d}'] = float(r['ebp'])
ms = sorted(k for k in un if '1985' <= k[:4] <= '2019')
Su = [sr[k] >= 0.5 for k in ms]; Lu, on = [], False
for k in ms:
    on = eb[k] >= 1.0 or (on and eb[k] > 0.0); Lu.append(on)
US['sahm_t'] = mean(Su); US['latch_t'] = mean(Lu); US['both'] = mean([a and b for a, b in zip(Su, Lu)])
US['sahm_only'] = mean([a and not b for a, b in zip(Su, Lu)]); US['latch_only'] = mean([b and not a for a, b in zip(Su, Lu)])
US['union'] = mean([a or b for a, b in zip(Su, Lu)]); yrs_us = len(ms) / 12
ne, d = episodes(Su, 0); US['sahm_ep/c'] = 100 * ne / yrs_us; US['sahm_dur_m'] = d
ne, d = episodes(Lu, 0); US['latch_ep/c'] = 100 * ne / yrs_us; US['latch_dur_m'] = d
qs = sorted(k for k in gd if '1985' <= k[:4] <= '2019'); qpre = sorted(k for k in gd if '1984' <= k[:4] <= '2019')
lv = [gd[k] for k in qpre]; recu = tech_rec(lv)[4:]
US['techrec'] = mean(recu); US['usrec_share'] = mean([ur[k] > 0 for k in ms])
gr = [400 * math.log(gd[qs[k]] / gd[qs[k - 1]]) for k in range(1, len(qs))]
pg = [400 * math.log(gp[qs[k]] / gp[qs[k - 1]]) for k in range(1, len(qs))]
gapu = [100 * (gd[k] / gp[k] - 1) for k in qs]
US['rgdp_acf1'] = acf1(gr); US['neg_q'] = mean([x < 0 for x in gr]); US['techrec_ep/c'] = 100 * len(onsets(recu)) / (len(qs) / 4)
US['rgdp_g%'] = mean(gr); US['rgdp_sd%'] = sd(gr); US['pot_g%'] = mean(pg); US['pot_sd%'] = sd(pg)
US['dgap_sd%'] = sd([gapu[k] - gapu[k - 1] for k in range(1, len(gapu))]); US['gap_sd%'] = sd(gapu)
uu = [un[k] for k in ms]
d1 = [uu[k] - uu[k - 1] for k in range(1, len(uu))]; d12 = [uu[k] - uu[k - 12] for k in range(12, len(uu))]
US['du1_sd'] = sd(d1); US['du12_sd'] = sd(d12); US['ratio'] = US['du12_sd'] / US['du1_sd']; US['u_sd%'] = sd(uu)
qidx = {k: i for i, k in enumerate(qs)}
def mq(k): return qidx[f'{k[:4]}-{(int(k[5:7]) - 1) // 3 * 3 + 1:02d}']
trig_us = [mq(ms[k]) for k in onsets(Su)]
US['false_trip'], US['n_trig'] = false_trips(trig_us, recu, 4)
usrec_q = [any(ur[f'{k[:4]}-{int(k[5:7]) + j:02d}'] > 0 for j in range(3)) for k in qs]
US['false_trip_vs_USREC'], _ = false_trips(trig_us, usrec_q, 4)
e = [eb[k] / 100 for k in ms]
US['ebp_mean%'] = 100 * mean(e); US['ebp_sd%'] = 100 * sd(e); US['ebp_ge1'] = mean([x >= 0.010 for x in e])
US['ebp_acf1q'] = acf1([eb[k] for k in ms if int(k[5:7]) % 3 == 0])
lon = onsets(Lu); US['latch_entry/c'] = 100 * len(lon) / yrs_us
recq_us = set(q for q in range(len(qs)) if recu[q])
US['latch_orphan'] = mean([not any(Su[j] for j in range(max(0, k - 6), min(len(ms), k + 6)))
                           and not any((mq(ms[k]) + d) in recq_us for d in range(-2, 3)) for k in lon]) if lon else float('nan')

# ---------------- print ----------------
seeds = sorted(per)
print(f'arm {ARM}: n={len(seeds)} seeds x 20 years after year 1, TPY {TPY}; US 1985-2019 monthly/quarterly')
cols = ['sahm_t', 'latch_t', 'latch_only', 'union', 'sahm_ep/c', 'latch_ep/c', 'techrec', 'techrec_cpi', 'rgdp_sd%', 'ratio', 'false_trip', 'E0_maxdu_bp']
print('\nper seed\n' + 'seed ' + ''.join(f'{c:>12}' for c in cols))
for s in seeds:
    print(f'{s:<5}' + ''.join(f'{per[s][c]:>12.3f}' for c in cols))
sections = {'A shares': ['sahm_t', 'latch_t', 'latch_only', 'sahm_only', 'both', 'union', 'sahm_ep/c', 'sahm_dur_m', 'latch_ep/c', 'latch_dur_m'],
            'B real GDP': ['techrec', 'techrec_ep/c', 'neg_q', 'rgdp_acf1', 'techrec_cpi', 'usrec_share', 'rgdp_g%', 'rgdp_sd%', 'rgdp_cpi_sd%', 'pot_g%', 'pot_sd%', 'dgap_sd%'],
            'C unemployment (pp)': ['du1_sd', 'du12_sd', 'ratio', 'u_sd%', 'gap_sd%'],
            'D Sahm false trips (+-4q)': ['false_trip', 'false_trip_vs_USREC', 'n_trig'],
            'E filter counterfactuals': ['E0_maxdu_bp', 'E0_maxdsahm_bp'] + [f'{a}_{b}' for a in ('E0', 'E1sym2', 'E2smooth', 'E3nairu04', 'E4okun45') for b in ('sahm', 'ep/c')],
            'F EBP latch': ['ebp_mean%', 'ebp_sd%', 'ebp_acf1q', 'ebp_ge1', 'latch_entry/c', 'latch_orphan']}
for sec, ks in sections.items():
    print(f'\n{sec}\n{"quantity":<20}{"sim mean":>10}{"(se)":>9}{"range":>18}{"US":>9}')
    for k in ks:
        xs = [per[s][k] for s in seeds if k in per[s] and not math.isnan(per[s][k])]
        sim = f'{mean(xs):>10.3f}{"(" + format(sd(xs) / math.sqrt(len(xs)), ".3f") + ")":>9}{min(xs):>9.3f}..{max(xs):<7.3f}' if xs else f'{"":>36}'
        usv = US.get(k); print(f'{k:<20}{sim}{"" if usv is None else format(usv, ".3f"):>9}')
