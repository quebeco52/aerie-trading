"""Full-term dynamics of German Bundestag vote intention, 1998-2026 (wahlrecht.de tables).

Q2a variogram of monthly log shares, scaled by mean share, fitted as nugget + OU short-term + lasting part.
Q2b noise of a single poll around the monthly average, vs pure sampling error sqrt(p(1-p)/n).
Q2c cabinet parties' combined poll share by month of term (midterm slump?).
Q2d honeymoon: cabinet / chancellor party vs their result in the first months.
Q3  poll releases per month by months to the next election; sample sizes.

Input: data/de_polls_long.csv, data/de_polls_stimmung_long.csv, data/de_results.csv (parse_wahlrecht.py).
Run:   python de_poll_dynamics.py > de_poll_dynamics.out
"""
import numpy as np
import pandas as pd
from scipy.optimize import least_squares

CORE = ["allensbach", "dimap", "emnid", "forsa", "politbarometer"]
OLD5 = ["UNION", "SPD", "GRUENE", "FDP", "LINKE"]
ELECTIONS = pd.to_datetime(["1998-09-27", "2002-09-22", "2005-09-18", "2009-09-27", "2013-09-22",
                            "2017-09-24", "2021-09-26", "2025-02-23"])
# Cabinet formed after each election (public record: Schroeder I/II, Merkel I-IV, Scholz, Merz).
CABINETS = {"1998-09-27": ["SPD", "GRUENE"], "2002-09-22": ["SPD", "GRUENE"], "2005-09-18": ["UNION", "SPD"],
            "2009-09-27": ["UNION", "FDP"], "2013-09-22": ["UNION", "SPD"], "2017-09-24": ["UNION", "SPD"],
            "2021-09-26": ["SPD", "GRUENE", "FDP"], "2025-02-23": ["UNION", "SPD"]}
CHANCELLOR = {"1998-09-27": "SPD", "2002-09-22": "SPD", "2005-09-18": "UNION", "2009-09-27": "UNION",
              "2013-09-22": "UNION", "2017-09-24": "UNION", "2021-09-26": "SPD", "2025-02-23": "UNION"}
GAME_PHI_YEAR = 0.905
RNG = np.random.default_rng(1998)


def load():
    p = pd.read_csv("data/de_polls_long.csv", parse_dates=["date"])
    w = p.pivot_table(index=["institute", "date"], columns="party", values="share_pct").reset_index()
    nn = p.groupby(["institute", "date"]).n.first().reset_index()
    w = w.merge(nn, on=["institute", "date"], how="left")
    w["month"] = w.date.dt.to_period("M")
    res = pd.read_csv("data/de_results.csv", parse_dates=["date"]).pivot(index="date", columns="party", values="median")
    st = pd.read_csv("data/de_polls_stimmung_long.csv", parse_dates=["date"])
    return w, res, st


def monthly(w, institutes, parties):
    x = w[w.institute.isin(institutes)]
    im = x.groupby(["month", "institute"])[parties].mean()
    m = im.groupby(level="month").mean()
    k = im.groupby(level="month").size()
    m = m.reindex(pd.period_range(m.index.min(), m.index.max(), freq="M"))
    return m, k


# ---------------------------------------------------------------- Q2a variogram
def variogram(m, parties, hmax=48, start=None):
    rows = []
    for j in parties:
        s = m[j].dropna()
        if start is not None and j in start:
            s = s[s.index >= pd.Period(start[j], "M")]
        s = s.reindex(pd.period_range(s.index.min(), s.index.max(), freq="M"))
        x = np.log(s.values / 100.0)
        pbar = np.nanmean(s.values) / 100.0
        for h in range(1, hmax + 1):
            d = x[h:] - x[:-h]
            d = d[~np.isnan(d)]
            rows.append(dict(party=j, h=h, D=np.mean(d ** 2), S=pbar * np.mean(d ** 2), pairs=len(d), pbar=pbar))
    v = pd.DataFrame(rows)
    pooled = v.groupby("h").apply(lambda g: np.average(g.S, weights=g.pairs), include_groups=False)
    return v, pooled


HALF_905 = 12 * np.log(2) / -np.log(GAME_PHI_YEAR)  # game's lasting persistence as a half-life, months (~83)
GAME_LASTING_VAR = 0.0118 / 4 / (1 - GAME_PHI_YEAR ** 2)  # stationary scaled var implied by 0.0118/term at 0.905/yr


def vg_ou(var, half, h):
    return 2 * var * (1 - 2.0 ** (-h / half))


def fit_general(pooled, spec, hmax=48):
    """spec: dict of fixed values among nug, fv, fh (fast var, half-life), sv, sh (slow var, half-life) or q (RW).
    Free parameters are those not in spec; slow kind 'ou' or 'rw' via spec['slow']."""
    h = np.arange(1, hmax + 1, dtype=float)
    y = pooled.loc[1:hmax].values
    names = ["nug", "fv", "fh", "sv", "sh"]
    free = [n for n in names if n not in spec]
    lo = {"nug": 0, "fv": 0, "fh": 0.05, "sv": 0, "sh": 0.05}
    hi = {"nug": np.inf, "fv": np.inf, "fh": 600, "sv": np.inf, "sh": 6000}

    def full(t):
        d = dict(spec)
        d.update(dict(zip(free, t)))
        return d

    def curve(d):
        slow = d["sv"] * h if spec.get("slow") == "rw" else vg_ou(d["sv"], d["sh"], h)
        return 2 * d["nug"] + vg_ou(d["fv"], d["fh"], h) + slow

    def resid(t):
        return (curve(full(t)) - y) / y

    best = None
    starts = {"nug": [y[0] / 10], "fv": [y[0], y[11] / 2], "fh": [1, 3, 12], "sv": [y[-1] / 4, y[-1], 0.0005], "sh": [24, 120]}
    import itertools
    for combo in itertools.product(*[starts[n] for n in free]):
        try:
            r = least_squares(resid, np.array(combo, float), bounds=([lo[n] for n in free], [hi[n] for n in free]))
        except ValueError:
            continue
        if best is None or r.cost < best.cost:
            best = r
    d = full(best.x)
    d["rel_rmse"] = float(np.sqrt(np.mean(resid(best.x) ** 2)))
    d["S48_fast"] = float(vg_ou(d["fv"], d["fh"], 48.0))
    d["S48_slow"] = float(d["sv"] * 48 if spec.get("slow") == "rw" else vg_ou(d["sv"], d["sh"], 48.0))
    return d


def show(d, label):
    slow = f"RW var/month {d['sv']:.5f}" if d.get("slow") == "rw" else f"slow OU var {d['sv']:.4f} half-life {d['sh']:6.1f} m"
    tot = d["S48_fast"] + d["S48_slow"]
    print(f"  {label:52s} nug {d['nug']:.5f} | fast OU var {d['fv']:.4f} half-life {d['fh']:5.1f} m | {slow} | "
          f"S(48): fast {d['S48_fast']:.4f} slow {d['S48_slow']:.4f} (fast share {d['S48_fast'] / tot if tot else np.nan:.2f}) | rel RMSE {d['rel_rmse']:.3f}")
    return d


def nugget_estimate(w, m, parties):
    """Monthly-average noise variance (scaled log units) from within-institute successive release differences."""
    tot = []
    x = w[w.institute.isin(CORE)].sort_values(["institute", "date"])
    for j in parties:
        var_rel = {}
        for inst, g in x.groupby("institute"):
            g = g.dropna(subset=[j])
            dd = g.date.diff().dt.days
            d = g[j].diff()[(dd > 0) & (dd <= 16)]
            if len(d) > 30:
                var_rel[inst] = (d ** 2).mean() / 2.0  # pts^2 per release (incl. 1-2 weeks of true change)
        if not var_rel:
            continue
        # releases per institute-month
        cnt = x.dropna(subset=[j]).groupby(["month", "institute"]).size().unstack()
        mv = []
        for mo, row in cnt.iterrows():
            row = row.dropna()
            k = len(row)
            if k == 0:
                continue
            mv.append(sum(var_rel.get(i, np.mean(list(var_rel.values()))) / c for i, c in row.items()) / k ** 2)
        pbar = np.nanmean(m[j]) / 100.0
        tot.append(np.mean(mv) / 1e4 / pbar)  # var(share)/p^2 * p = var(share)/p, share as fraction
    return float(np.mean(tot))


def q2a(w, monthly_core, monthly_all):
    print("=" * 100)
    print("Q2a. Variogram of monthly log vote-intention share, scaled by party mean share (S(h) = pbar * E[(x_{t+h}-x_t)^2])")
    m, k = monthly_core
    print(f"  core-5 institutes monthly average {m.index.min()}..{m.index.max()}, months {len(m)}, "
          f"mean institutes per month {k.mean():.2f}")
    v, pooled = variogram(m, OLD5)
    print("  Per-party S(h) [and unscaled D(h)] at h = 1, 3, 6, 12, 24, 36, 48 months:")
    for j in OLD5:
        vv = v[v.party == j].set_index("h")
        print(f"   {j:7s} pbar {vv.pbar.iloc[0]:.3f}  S: " + "  ".join(f"{vv.S[h]:.4f}" for h in [1, 3, 6, 12, 24, 36, 48])
              + "   D: " + "  ".join(f"{vv.D[h]:.4f}" for h in [1, 12, 48]))
    print("  Pooled S(h) (pairs-weighted mean over the 5 parties):")
    print("   " + "  ".join(f"h{h}:{pooled[h]:.4f}" for h in [1, 2, 3, 4, 6, 9, 12, 18, 24, 30, 36, 42, 48]))
    # 1/p scaling check: regress log D(h) on log pbar across parties
    for h in [1, 12, 48]:
        vv = v[v.h == h]
        b = np.polyfit(np.log(vv.pbar), np.log(vv.D), 1)[0]
        print(f"   scaling check h={h}: slope of log D on log pbar across 5 parties = {b:.2f} (game assumes -1)")
    nug_est = nugget_estimate(w, m, OLD5)
    print(f"  Independent nugget estimate (monthly-average noise var, scaled log units) from successive releases: {nug_est:.5f}")
    print("  Fits on h = 1..48 (relative least squares on S(h)); S(h) = 2 nug + 2 fv (1 - 2^(-h/fh)) + slow(h)")
    out = []
    out.append(show(fit_general(pooled, {"slow": "ou", "sv": 0.0, "sh": 1.0}), "A single OU (+nugget)"))
    out.append(show(fit_general(pooled, {"slow": "ou", "sv": 0.0, "sh": 1.0, "nug": nug_est}), "A' single OU, nugget fixed at estimate"))
    out.append(show(fit_general(pooled, {"slow": "rw"}), "B fast OU + RW lasting"))
    out.append(show(fit_general(pooled, {"slow": "ou", "sh": HALF_905}), f"C fast OU + slow OU at game persistence (half-life {HALF_905:.0f} m)"))
    out.append(show(fit_general(pooled, {"slow": "ou"}), "D fast OU + slow OU, both free"))
    out.append(show(fit_general(pooled, {"slow": "ou", "sh": HALF_905, "sv": GAME_LASTING_VAR}),
                    f"E slow fixed at game lasting (var {GAME_LASTING_VAR:.4f}), fast free"))
    print("  Profile over a fixed fast half-life (slow = OU at game persistence, variances free):")
    for fh in [0.5, 1, 2, 3, 4, 6, 9, 12, 18, 24, 36]:
        out.append(show(fit_general(pooled, {"slow": "ou", "sh": HALF_905, "fh": fh}), f"   fast half-life fixed {fh} m"))
    print("  Robustness of model A (single OU + nugget) and model C:")
    variants = [("core-5 (baseline)", pooled)]
    mA, _ = monthly_all
    variants.append(("all 8 institutes", variogram(mA, OLD5)[1]))
    variants.append(("core-5, + AfD from 2013-10", variogram(m, OLD5 + ["AFD"], start={"AFD": "2013-10"})[1]))
    for lab, sub in [("1998-2011 only", (m.index < pd.Period("2012-01", "M"))),
                     ("2012-2026 only", (m.index >= pd.Period("2012-01", "M")))]:
        variants.append((lab, variogram(m[sub], OLD5)[1]))
    for j in OLD5:
        variants.append((f"drop {j}", variogram(m, [x for x in OLD5 if x != j])[1]))
    for lab, pp in variants:
        out.append(show(fit_general(pp, {"slow": "ou", "sv": 0.0, "sh": 1.0}), "A " + lab))
        out.append(show(fit_general(pp, {"slow": "ou", "sh": HALF_905}), "C " + lab))
    out.append(show(fit_general(pooled, {"slow": "ou", "sv": 0.0, "sh": 1.0}, hmax=24), "A core-5, fit on h=1..24 only"))
    print("  Party-resampling bootstrap (resample the 5 parties' variograms with replacement), model A and C:")
    parts = {j: v[v.party == j].set_index("h") for j in OLD5}
    resA, resC = [], []
    for _ in range(200):
        pick = RNG.choice(OLD5, size=5, replace=True)
        pp = sum(parts[j].S * parts[j].pairs for j in pick) / sum(parts[j].pairs for j in pick)
        a = fit_general(pp, {"slow": "ou", "sv": 0.0, "sh": 1.0})
        c = fit_general(pp, {"slow": "ou", "sh": HALF_905})
        resA.append((a["fh"], a["fv"]))
        resC.append((c["fh"], c["fv"], c["sv"], c["S48_fast"] / (c["S48_fast"] + c["S48_slow"])))
    resA, resC = np.array(resA), np.array(resC)
    for lab, arr in [("A half-life (m)", resA[:, 0]), ("A OU var", resA[:, 1]), ("C fast half-life (m)", resC[:, 0]),
                     ("C fast var", resC[:, 1]), ("C slow var", resC[:, 2]), ("C fast share of S(48)", resC[:, 3])]:
        print(f"   {lab:24s} median {np.median(arr):.4f}  5-95%: {np.percentile(arr, 5):.4f} .. {np.percentile(arr, 95):.4f}")
    return pooled, out


# ---------------------------------------------------------------- Q2b poll noise
def q2b(w, st):
    print("=" * 100)
    print("Q2b. Noise of a single poll around the monthly average (core-5 + others, 1998-2026)")
    x = w.copy()
    x["term"] = pd.cut(x.date, bins=list(ELECTIONS) + [pd.Timestamp("2030-01-01")], labels=False)
    im = x.groupby(["month", "institute"])[OLD5 + ["AFD"]].mean()
    print("  (a) deviation from the leave-one-institute-out monthly mean (months with >=3 other institutes)")
    print("  (b) same after removing institute x party x term house effects")
    print("  (c) within-institute successive releases <=16 days apart: SD(diff)/sqrt2")
    print("  party   pbar   n_med  sampSD(pts)  (a)SD  (a)/samp  (b)SD  (b)/samp   (c)SD  (c)/samp   [rounding SD of integer reporting = 0.29]")
    for j in OLD5 + ["AFD"]:
        y = x.dropna(subset=[j]).copy()
        if j == "AFD":
            y = y[y.date >= "2013-10-01"]
        y = y[y.n.notna() & (y.n > 300)]
        # leave-one-institute-out mean
        tab = im[j].unstack()
        tot = tab.sum(axis=1, min_count=1)
        cnt = tab.notna().sum(axis=1)
        own = tab.stack().rename("own").reset_index()
        y = y.merge(own, on=["month", "institute"], how="left")
        y["loo"] = (y.month.map(tot) - y.own) / (y.month.map(cnt) - 1)
        y["k_other"] = y.month.map(cnt) - 1
        y = y[y.k_other >= 3]
        y["e"] = y[j] - y.loo
        p = y[j] / 100.0
        y["sv"] = 1e4 * p * (1 - p) / y.n
        samp = np.sqrt(y.sv.mean())
        a = y.e.std()
        y["eb"] = y.e - y.groupby(["institute", "term"]).e.transform("mean")
        g = y.groupby(["institute", "term"]).e.transform("size")
        dof = len(y) - y.groupby(["institute", "term"]).ngroups
        b = np.sqrt((y.eb ** 2).sum() / dof)
        # successive differences
        cs = []
        for inst, gg in x.dropna(subset=[j]).sort_values("date").groupby("institute"):
            if j == "AFD":
                gg = gg[gg.date >= "2013-10-01"]
            dd = gg.date.diff().dt.days
            d = gg[j].diff()[(dd > 0) & (dd <= 16)]
            nbar = (gg.n + gg.n.shift(1)) / 2
            pp = gg[j] / 100
            sv = (1e4 * pp * (1 - pp) / nbar)[(dd > 0) & (dd <= 16)]
            ok = d.notna() & sv.notna()
            cs.append(pd.DataFrame({"d": d[ok], "sv": sv[ok]}))
        cs = pd.concat(cs)
        c = np.sqrt((cs.d ** 2).mean() / 2)
        csamp = np.sqrt(cs.sv.mean())
        print(f"  {j:7s} {y[j].mean() / 100:.3f}  {y.n.median():5.0f}   {samp:5.2f}       {a:5.2f}   {a / samp:5.2f}   "
              f"{b:5.2f}   {b / samp:5.2f}    {c:5.2f}   {c / csamp:5.2f}")
    print("  (c) by institute, pooled over the five old parties, ratio SD(diff)/sqrt2 / sampling SD:")
    for inst, gg0 in x.sort_values("date").groupby("institute"):
        rr = []
        for j in OLD5:
            gg = gg0.dropna(subset=[j])
            dd = gg.date.diff().dt.days
            d = gg[j].diff()[(dd > 0) & (dd <= 16)]
            pp = gg[j] / 100
            sv = (1e4 * pp * (1 - pp) / ((gg.n + gg.n.shift(1)) / 2))[(dd > 0) & (dd <= 16)]
            ok = d.notna() & sv.notna()
            rr.append(pd.DataFrame({"d": d[ok], "sv": sv[ok]}))
        rr = pd.concat(rr)
        if len(rr) > 50:
            print(f"     {inst:15s} pairs {len(rr):5d}  ratio {np.sqrt((rr.d ** 2).mean() / 2) / np.sqrt(rr.sv.mean()):.2f}")
    # raw Stimmung vs Projektion (Politbarometer)
    sw = st[st.institute == "politbarometer"].pivot_table(index="date", columns="party", values="share_pct")
    pw = w[w.institute == "politbarometer"].set_index("date")[OLD5 + ["AFD"]]
    common = sw.index.intersection(pw.index)
    print(f"  Politbarometer raw 'Stimmung' vs published 'Projektion', {len(common)} common releases "
          f"{common.min().date()}..{common.max().date()}: SD of release-to-release change (pts)")
    for j in ["UNION", "SPD", "GRUENE", "FDP", "LINKE", "AFD"]:
        if j in sw and j in pw:
            a = sw.loc[common, j].diff().std()
            b = pw.loc[common, j].diff().std()
            print(f"     {j:7s} raw {a:.2f}   projection {b:.2f}   ratio {a / b:.2f}")


# ---------------------------------------------------------------- Q2c/Q2d cabinet path
def term_paths(w, res, institutes):
    x = w[w.institute.isin(institutes)].copy()
    rows = []
    for i, e in enumerate(ELECTIONS):
        end = ELECTIONS[i + 1] if i + 1 < len(ELECTIONS) else pd.Timestamp("2026-10-05")
        key = str(e.date())
        cab = CABINETS[key]
        chanc = CHANCELLOR[key]
        r = res.loc[e]
        t = x[(x.date > e) & (x.date < end)].copy()
        t["k"] = ((t.date - e).dt.days // 30.4375).astype(int)
        t["cab"] = t[cab].sum(axis=1, min_count=len(cab))
        im = t.groupby(["k", "institute"])[["cab", chanc]].mean().groupby(level="k").mean()
        for k, row in im.iterrows():
            rows.append(dict(term=e.year, k=k, len_m=(end - e).days / 30.4375, cab=row.cab - r[cab].sum(),
                             chanc=row[chanc] - r[chanc], cab_res=r[cab].sum(), complete=i + 1 < len(ELECTIONS)))
        if i + 1 < len(ELECTIONS):
            nxt = res.loc[end]
            rows.append(dict(term=e.year, k=-1, len_m=(end - e).days / 30.4375, cab=nxt[cab].sum() - r[cab].sum(),
                             chanc=nxt[chanc] - r[chanc], cab_res=r[cab].sum(), complete=True))
    return pd.DataFrame(rows)


def q2cd(w, res):
    print("=" * 100)
    print("Q2c/Q2d. Cabinet parties' combined poll share minus their combined result at the election that started the term")
    tp = term_paths(w, res, CORE + ["insa", "yougov", "gms"])
    by = tp[tp.k >= 0].pivot(index="k", columns="term", values="cab")
    print("  Per term (pts), months since election 0,1,2,3,6,9,12,18,24,30,36,42,47 and next result (-1 = next election):")
    for term, g in tp.groupby("term"):
        gg = g.set_index("k").cab
        cab = CABINETS[[str(e.date()) for e in ELECTIONS if e.year == term][0]]
        print(f"   {term} {'+'.join(cab):16s} result {g.cab_res.iloc[0]:5.1f}: " +
              " ".join(f"{gg.get(k, np.nan):+5.1f}" for k in [0, 1, 2, 3, 6, 9, 12, 18, 24, 30, 36, 42, 47])
              + f" | next vote {gg.get(-1, np.nan):+5.1f}")
    print("  Mean over terms by month of term (SE = SD/sqrt(n terms)):")
    print("   month  n_terms  mean_cab  se    mean_chancellor_party  se")
    for k in [0, 1, 2, 3, 4, 5, 6, 9, 12, 15, 18, 21, 24, 27, 30, 33, 36, 39, 42, 45, 47]:
        s = tp[tp.k == k]
        if len(s) < 2:
            continue
        print(f"   {k:5d}  {len(s):5d}    {s.cab.mean():+6.2f}  {s.cab.std() / np.sqrt(len(s)):5.2f}    "
              f"{s.chanc.mean():+6.2f}               {s.chanc.std() / np.sqrt(len(s)):5.2f}")
    fin = tp[tp.k == -1]
    print(f"   next election (complete terms, n={len(fin)}): cabinet {fin.cab.mean():+.2f} (se {fin.cab.std() / np.sqrt(len(fin)):.2f}), "
          f"chancellor party {fin.chanc.mean():+.2f} (se {fin.chanc.std() / np.sqrt(len(fin)):.2f})")
    # midterm vs end, per complete term, by fraction of term
    print("  By fraction of term (complete terms only): mean cabinet gap, and the 'recovery' = last 3 months minus mid-term (35-65% of term)")
    c = tp[(tp.k >= 0) & tp.complete].copy()
    c["f"] = (c.k + 0.5) / c.len_m
    c["dec"] = np.minimum((c.f * 10).astype(int), 9)
    d = c.groupby(["term", "dec"]).cab.mean().unstack()
    print("   decile: " + " ".join(f"{q:6d}" for q in range(10)))
    print("   mean  : " + " ".join(f"{d[q].mean():+6.2f}" for q in range(10)))
    print("   se    : " + " ".join(f"{d[q].std() / np.sqrt(d[q].notna().sum()):6.2f}" for q in range(10)))
    rec = []
    for term, g in c.groupby("term"):
        mid = g[(g.f >= 0.35) & (g.f <= 0.65)].cab.mean()
        last = g[g.k >= g.len_m - 3].cab.mean()
        rec.append(last - mid)
    rec = np.array(rec)
    print(f"   recovery (last 3 months - midterm) per term: {np.round(rec, 1)}; mean {rec.mean():+.2f}, se {rec.std(ddof=1) / np.sqrt(len(rec)):.2f}")
    print("  Honeymoon: months 1-3 and 4-6 after the election (all terms incl. 2025):")
    for lo, hi in [(0, 0), (1, 3), (4, 6), (7, 12)]:
        s = tp[(tp.k >= lo) & (tp.k <= hi)].groupby("term")[["cab", "chanc"]].mean()
        print(f"   months {lo}-{hi}: cabinet {s.cab.mean():+.2f} (se {s.cab.std() / np.sqrt(len(s)):.2f}, n {len(s)}; "
              f"per term {np.round(s.cab.values, 1)}), chancellor party {s.chanc.mean():+.2f} (se {s.chanc.std() / np.sqrt(len(s)):.2f}; "
              f"{np.round(s.chanc.values, 1)})")


# ---------------------------------------------------------------- Q3 publication frequency
def q3(w):
    print("=" * 100)
    print("Q3. Poll releases per month by months to the next election (all 8 institutes; Germany 1998-2026)")
    x = w.drop_duplicates(["institute", "date"]).copy()
    nxt = x.date.apply(lambda d: next((e for e in ELECTIONS if e >= d), pd.NaT))
    x["mto"] = ((nxt - x.date).dt.days // 30.4375)
    x = x[x.mto.notna()]
    x["year"] = x.date.dt.year
    for lab, sub in [("1998-2011", x[x.year <= 2011]), ("2012-2025", x[(x.year >= 2012) & (x.year <= 2025)])]:
        print(f"  {lab}: releases per month (all institutes) by months before the election")
        for lo, hi in [(0, 0), (1, 2), (3, 5), (6, 11), (12, 23), (24, 47)]:
            s = sub[(sub.mto >= lo) & (sub.mto <= hi)]
            months = s.groupby(["mto"]).size()
            nel = s.date.apply(lambda d: next((e for e in ELECTIONS if e >= d))).nunique()
            per = len(s) / max(1, nel * (hi - lo + 1))
            print(f"    {lo:2d}-{hi:<2d} months out: {per:5.1f} per month  (elections {nel})")
    print("  Stated sample size per release (Befragte):")
    print(x.groupby("institute").n.describe(percentiles=[0.1, 0.5, 0.9])[["count", "10%", "50%", "90%"]].round(0).to_string())
    print(f"  all releases: median n {x.n.median():.0f}, IQR {x.n.quantile(0.25):.0f}..{x.n.quantile(0.75):.0f}")


def main():
    w, res, st = load()
    print(f"Loaded {w.groupby(['institute', 'date']).ngroups} releases, {w.date.min().date()}..{w.date.max().date()}")
    mc = monthly(w, CORE, OLD5 + ["AFD"])
    ma = monthly(w, CORE + ["insa", "yougov", "gms"], OLD5 + ["AFD"])
    mc[0].round(2).to_csv("data/de_monthly_core5.csv")
    q2a(w, mc, ma)
    q2b(w, st)
    q2cd(w, res)
    q3(w)


if __name__ == "__main__":
    main()
