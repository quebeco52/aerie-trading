"""Full-cycle poll dynamics in PR parliamentary democracies from the Jennings & Wlezien (2018)
replication data (doi:10.7910/DVN/8421DX), which carries every poll of a cycle, not only the last 200 days.

Q2a cross-check: variogram of monthly log poll shares (scaled by party mean share), pooled over country-parties.
Q2c: governing parties' combined poll share minus their combined previous-election vote, by fraction of the term.
Q2d: honeymoon in the first months after the previous election (governing parties, largest party, largest gainer).
Q3:  polls per month by days to the election.
Also: election-to-election scaled log-vote variance, the analogue of the game's per-term variance.

gov_ = party in the government that faced the voters at election E (outgoing cabinet), so the cabinet
during the term is proxied by the cabinet at its end. Japan dropped as in the authors' do-files.
Run: python jw_cycles.py > jw_cycles.out
"""
import itertools

import numpy as np
import pandas as pd
from scipy.optimize import least_squares

DATA = "data/jw2018/LONG_MI_NATURE_20180111.dta"
PHI = 0.905
HALF_905 = 12 * np.log(2) / -np.log(PHI)
GAME_LASTING_VAR = 0.0118 / 4 / (1 - PHI ** 2)
FIXED_TERM = ["Norway", "Sweden"]  # no early dissolution in practice / fixed election dates
RNG = np.random.default_rng(2016)


def load():
    df = pd.read_stata(DATA, convert_categoricals=False)
    df = df[(df.countryid != 20) & (df.election == "Legislative") & (df["round"] == 1) & (df.rule == "PR")].copy()
    df["gov"] = (df.gov_ > 0).astype(int)
    df.loc[df["sample"] <= 0, "sample"] = np.nan
    df.loc[df.poll_ <= 0, "poll_"] = np.nan  # 72 zero entries in the file: parties not yet polled
    return df


def election_table(df):
    e = (df.drop_duplicates(["country", "elecdate", "partyid"])[["country", "elecdate", "partyid", "vote_", "gov", "pollcycle"]]
         .sort_values(["country", "elecdate"]))
    dates = e[["country", "elecdate"]].drop_duplicates().sort_values(["country", "elecdate"])
    dates["prevdate"] = dates.groupby("country").elecdate.shift(1)
    dates["prev2date"] = dates.groupby("country").elecdate.shift(2)
    e = e.merge(dates, on=["country", "elecdate"])
    v = e.set_index(["country", "elecdate", "partyid"]).vote_
    e["prev_vote"] = [v.get((c, d, p), np.nan) for c, d, p in zip(e.country, e.prevdate, e.partyid)]
    e["prev2_vote"] = [v.get((c, d, p), np.nan) for c, d, p in zip(e.country, e.prev2date, e.partyid)]
    return e, dates


# ------------------------------------------------------------------ variogram
def monthly_series(df):
    x = df[df.poll_.notna() & (df.poll_ > 0)].copy()  # a few zero entries are parties not reported that day
    x["month"] = x.polldate.dt.to_period("M")
    g = x.groupby(["country", "partyid", "month"]).agg(p=("poll_", "mean"), n=("sample", lambda s: s.sum(min_count=1)), k=("npolls", "sum"))
    return g.reset_index()


def vg_ou(var, half, h):
    return 2 * var * (1 - 2.0 ** (-h / half))


def fit_general(pooled, spec, hmax=48):
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
        return 2 * d["nug"] + vg_ou(d["fv"], d["fh"], h) + vg_ou(d["sv"], d["sh"], h)

    def resid(t):
        return (curve(full(t)) - y) / y

    starts = {"nug": [y[0] / 4], "fv": [y[0], y[11] / 2], "fh": [1, 3, 12], "sv": [y[-1] / 4, y[-1]], "sh": [24, 120]}
    best = None
    for combo in itertools.product(*[starts[n] for n in free]):
        r = least_squares(resid, np.array(combo, float), bounds=([lo[n] for n in free], [hi[n] for n in free]))
        if best is None or r.cost < best.cost:
            best = r
    d = full(best.x)
    d["rel_rmse"] = float(np.sqrt(np.mean(resid(best.x) ** 2)))
    d["S48_fast"] = float(vg_ou(d["fv"], d["fh"], 48.0))
    d["S48_slow"] = float(vg_ou(d["sv"], d["sh"], 48.0))
    return d


def show(d, label):
    tot = d["S48_fast"] + d["S48_slow"]
    print(f"  {label:50s} nug {d['nug']:.5f} | fast OU var {d['fv']:.4f} half-life {d['fh']:5.1f} m | slow OU var {d['sv']:.4f} "
          f"half-life {d['sh']:6.1f} m | S(48) fast {d['S48_fast']:.4f} slow {d['S48_slow']:.4f} "
          f"(fast share {d['S48_fast'] / tot if tot else np.nan:.2f}) | rel RMSE {d['rel_rmse']:.3f}")
    return d


def variogram(ms, hmax=48, min_months=60, min_share=3.0):
    rows = []
    for (c, pid), g in ms.groupby(["country", "partyid"]):
        if len(g) < min_months or g.p.mean() < min_share:
            continue
        s = g.set_index("month").p
        s = s.reindex(pd.period_range(s.index.min(), s.index.max(), freq="M"))
        x = np.log(s.values / 100.0)
        nn = g.set_index("month").n.reindex(s.index).values
        pbar = np.nanmean(s.values) / 100.0
        nug_samp = np.nanmean(pbar * (1 - s.values / 100) / (s.values / 100) / nn)
        for h in range(1, hmax + 1):
            d = x[h:] - x[:-h]
            d = d[~np.isnan(d)]
            if len(d) < 10:
                continue
            rows.append(dict(country=c, party=pid, h=h, S=pbar * np.mean(d ** 2), pairs=len(d), pbar=pbar, nug_samp=nug_samp))
    v = pd.DataFrame(rows)
    pooled = v.groupby("h").apply(lambda g: np.average(g.S, weights=g.pairs), include_groups=False)
    return v, pooled


def q2a(df):
    print("=" * 100)
    print("Q2a cross-check: variogram of monthly log poll share x pbar, PR legislative, country-parties with >=60 poll-months and mean share >=3%")
    ms = monthly_series(df)
    sets = [("all PR countries", None), ("Netherlands", ["Netherlands"]), ("Denmark", ["Denmark"]), ("Norway", ["Norway"]),
            ("Germany 1961-2017", ["Germany"]), ("Sweden", ["Sweden"]), ("Spain+Portugal", ["Spain", "Portugal"]),
            ("Ireland", ["Ireland"]), ("New Zealand", ["New Zealand"])]
    for lab, cs in sets:
        sub = ms if cs is None else ms[ms.country.isin(cs)]
        v, pooled = variogram(sub)
        if v.empty or len(pooled) < 48:
            print(f"  {lab}: not enough data")
            continue
        ncp = v.groupby(["country", "party"]).ngroups
        nug_s = v.drop_duplicates(["country", "party"]).nug_samp.mean()
        print(f"\n  [{lab}] country-parties {ncp}; pooled S(h): " + "  ".join(f"h{h}:{pooled[h]:.4f}" for h in [1, 2, 3, 6, 12, 24, 36, 48])
              + f"; pure-sampling nugget (1-p)/n_month x pbar/p = {nug_s:.5f}")
        show(fit_general(pooled, {"sv": 0.0, "sh": 1.0}), "A single OU + nugget")
        show(fit_general(pooled, {"sh": HALF_905}), f"C fast OU + slow OU at 0.905/yr")
        show(fit_general(pooled, {}), "D two free OUs")
        show(fit_general(pooled, {"sh": HALF_905, "sv": GAME_LASTING_VAR}), "E slow fixed at game lasting, fast free")
        if cs is None:
            print("  Profile over a fixed fast half-life (slow OU at 0.905/yr, variances + nugget free), all PR:")
            for fh in [1, 2, 3, 6, 12, 18, 24]:
                show(fit_general(pooled, {"sh": HALF_905, "fh": fh}), f"   fast half-life fixed {fh} m")
            print("  Country-cluster bootstrap (resample country-parties' variograms by country), model A and C:")
            parts = {k: g.set_index("h") for k, g in v.groupby("country")}
            cs_ = list(parts)
            ra, rc = [], []
            for _ in range(150):
                pick = RNG.choice(cs_, size=len(cs_), replace=True)
                num = sum(parts[c].S * parts[c].pairs for c in pick)
                den = sum(parts[c].pairs for c in pick)
                pp = (num / den).dropna()
                if len(pp) < 48:
                    continue
                a = fit_general(pp, {"sv": 0.0, "sh": 1.0})
                c = fit_general(pp, {"sh": HALF_905})
                ra.append((a["fh"], a["fv"], a["nug"]))
                rc.append((c["fh"], c["fv"], c["sv"], c["S48_fast"] / max(1e-12, c["S48_fast"] + c["S48_slow"])))
            ra, rc = np.array(ra), np.array(rc)
            for lab2, arr in [("A half-life", ra[:, 0]), ("A OU var", ra[:, 1]), ("A nugget", ra[:, 2]), ("C fast half-life", rc[:, 0]),
                              ("C fast var", rc[:, 1]), ("C slow var", rc[:, 2]), ("C fast share S(48)", rc[:, 3])]:
                print(f"   {lab2:20s} median {np.median(arr):.4f}  5-95%: {np.percentile(arr, 5):.4f} .. {np.percentile(arr, 95):.4f}")


# ------------------------------------------------------------------ election-to-election variance
def term_variance(e):
    print("=" * 100)
    print("Election-to-election change in log vote, scaled by mean share: pbar * (log v_E - log v_P)^2 (PR legislative, both shares >= 2%)")
    x = e[e.prev_vote.notna() & (e.vote_ >= 2) & (e.prev_vote >= 2)].copy()
    x["gap_y"] = (x.elecdate - x.prevdate).dt.days / 365.25
    x = x[x.gap_y <= 5.5]
    x["pbar"] = (x.vote_ + x.prev_vote) / 200
    x["S"] = x.pbar * (np.log(x.vote_) - np.log(x.prev_vote)) ** 2
    print(f"  pairs {len(x)}, elections {x.groupby(['country', 'elecdate']).ngroups}, countries {x.country.nunique()}, mean gap {x.gap_y.mean():.2f} y")
    print(f"  mean S = {x.S.mean():.4f}; median S = {x.S.median():.4f}; by gap: "
          + "; ".join(f"{lo}-{hi}y: {x[(x.gap_y >= lo) & (x.gap_y < hi)].S.mean():.4f} (n {((x.gap_y >= lo) & (x.gap_y < hi)).sum()})"
                      for lo, hi in [(0, 2.5), (2.5, 3.5), (3.5, 5.5)]))
    for lab, m in [("p<10%", x.pbar < 0.10), ("10-25%", (x.pbar >= 0.10) & (x.pbar < 0.25)), (">=25%", x.pbar >= 0.25)]:
        print(f"   by size {lab:7s}: n {m.sum():4d}  mean S {x[m].S.mean():.4f}  (unscaled var of dlog {((np.log(x[m].vote_) - np.log(x[m].prev_vote)) ** 2).mean():.4f})")
    y = x[x.country.isin(["Germany", "Netherlands", "Denmark", "Norway", "Sweden"])]
    print(f"  five core countries: n {len(y)}, mean S {y.S.mean():.4f}")


# ------------------------------------------------------------------ governing parties' path
def cabinet_paths(df, e):
    elec = e.groupby(["country", "elecdate"]).agg(prevdate=("prevdate", "first")).reset_index()
    rows, finals = [], []
    for _, r in elec.iterrows():
        if pd.isna(r.prevdate):
            continue
        gap = (r.elecdate - r.prevdate).days
        if gap > 5.5 * 365.25 or gap < 300:
            continue
        ee = e[(e.country == r.country) & (e.elecdate == r.elecdate)]
        gov = ee[ee.gov == 1]
        if gov.empty or gov.prev_vote.isna().any():
            continue
        prevp = e[(e.country == r.country) & (e.elecdate == r.prevdate)]
        largest = prevp.loc[prevp.vote_.idxmax(), "partyid"]
        gains = (prevp.vote_ - prevp.prev_vote)
        gainer = prevp.loc[gains.idxmax(), "partyid"] if gains.notna().any() else np.nan
        prev_votes = prevp.set_index("partyid").vote_
        cyc = df[(df.country == r.country) & (df.elecdate == r.elecdate) & (df.polldate > r.prevdate) & df.poll_.notna()]
        if cyc.empty:
            continue
        wide = cyc.pivot_table(index="polldate", columns="partyid", values="poll_")
        gp = list(gov.partyid)
        if not set(gp).issubset(wide.columns):
            continue
        comb = wide[gp].sum(axis=1, min_count=len(gp)).dropna()
        gov_prev = gov.prev_vote.sum()
        out = pd.DataFrame({"date": comb.index, "cab": comb.values - gov_prev})
        out["largest"] = (wide[largest].reindex(comb.index).values - prev_votes[largest]) if largest in wide else np.nan
        out["gainer"] = ((wide[gainer].reindex(comb.index).values - prev_votes[gainer])
                         if (not pd.isna(gainer) and gainer in wide) else np.nan)
        out["country"], out["elecdate"], out["gap"] = r.country, r.elecdate, gap
        out["f"] = (out.date - r.prevdate).dt.days / gap
        out["k"] = ((out.date - r.prevdate).dt.days // 30.4375).astype(int)
        out["ngov"] = len(gp)
        out["gov_prev"] = gov_prev
        rows.append(out)
        finals.append(dict(country=r.country, elecdate=r.elecdate, gap_y=gap / 365.25, ngov=len(gp), gov_prev=gov_prev,
                           cab_final=gov.vote_.sum() - gov_prev, pollcycle=gov.pollcycle.iloc[0]))
    return pd.concat(rows, ignore_index=True), pd.DataFrame(finals)


def q2cd(df, e):
    print("=" * 100)
    print("Q2c/Q2d. Governing parties' combined poll share minus their combined previous-election vote (pts)")
    paths, fin = cabinet_paths(df, e)
    for lab, cs in [("all PR cycles", None), ("fixed-term (Norway, Sweden)", FIXED_TERM),
                    ("Germany+Netherlands+Denmark+Norway+Sweden", ["Germany", "Netherlands", "Denmark", "Norway", "Sweden"])]:
        P = paths if cs is None else paths[paths.country.isin(cs)]
        F = fin if cs is None else fin[fin.country.isin(cs)]
        # only cycles polled from the first year of the term
        early = P.groupby(["country", "elecdate"]).f.min()
        keep = early[early <= 0.15].index
        P = P.set_index(["country", "elecdate"]).loc[lambda d: d.index.isin(keep)].reset_index()
        F = F.set_index(["country", "elecdate"]).loc[lambda d: d.index.isin(keep)].reset_index()
        print(f"\n  [{lab}] cycles polled from <=15% of the term: {len(keep)}, countries {P.country.nunique()}")
        P = P.copy()
        P["dec"] = np.minimum((P.f * 10).astype(int), 9)
        d = P.groupby(["country", "elecdate", "dec"]).cab.mean().unstack()
        print("   term decile        : " + " ".join(f"{q:6d}" for q in range(10)) + "   next vote")
        print("   mean (cycles)      : " + " ".join(f"{d[q].mean():+6.2f}" for q in range(10)) + f"   {F.cab_final.mean():+6.2f}")
        print("   se (cycle = unit)  : " + " ".join(f"{d[q].std() / np.sqrt(d[q].notna().sum()):6.2f}" for q in range(10))
              + f"   {F.cab_final.std() / np.sqrt(len(F)):6.2f}")
        print("   n cycles           : " + " ".join(f"{d[q].notna().sum():6d}" for q in range(10)) + f"   {len(F):6d}")
        mid = P[(P.f >= 0.35) & (P.f <= 0.65)].groupby(["country", "elecdate"]).cab.mean()
        last = P[P.f >= 0.9].groupby(["country", "elecdate"]).cab.mean()
        fv = F.set_index(["country", "elecdate"]).cab_final
        rec = (last - mid).dropna()
        rec2 = (fv - mid).dropna()
        print(f"   recovery, last 10% of term minus midterm (35-65%): mean {rec.mean():+.2f} se {rec.std() / np.sqrt(len(rec)):.2f} (n {len(rec)}); "
              f"next vote minus midterm: {rec2.mean():+.2f} se {rec2.std() / np.sqrt(len(rec2)):.2f}")
        ds = P.groupby(["country", "elecdate"]).apply(
            lambda g: np.polyfit(g.f, g.cab, 2)[0] if g.f.max() - g.f.min() > 0.6 and len(g) > 10 else np.nan,
            include_groups=False).dropna()
        print(f"   per-cycle quadratic in term fraction, mean curvature coefficient {ds.mean():+.2f} se {ds.std() / np.sqrt(len(ds)):.2f} "
              f"(positive = U-shaped slump-and-recovery; n {len(ds)})")
        print("   Honeymoon (months after previous election):")
        for lo, hi in [(0, 2), (3, 5), (6, 11), (12, 23)]:
            s = P[(P.k >= lo) & (P.k <= hi)].groupby(["country", "elecdate"])[["cab", "largest", "gainer"]].mean()
            print(f"     months {lo:2d}-{hi:<2d}: governing {s.cab.mean():+5.2f} (se {s.cab.std() / np.sqrt(s.cab.notna().sum()):.2f}, n {s.cab.notna().sum()}) | "
                  f"largest party at prev. election {s.largest.mean():+5.2f} (se {s.largest.std() / np.sqrt(s.largest.notna().sum()):.2f}) | "
                  f"biggest gainer at prev. election {s.gainer.mean():+5.2f} (se {s.gainer.std() / np.sqrt(s.gainer.notna().sum()):.2f}, n {s.gainer.notna().sum()})")
    print("\n  Realized change of the governing parties' combined vote, previous election -> this election (all linked PR cycles):")
    print(f"   n {len(fin)}, mean {fin.cab_final.mean():+.2f} pts (se {fin.cab_final.std() / np.sqrt(len(fin)):.2f}), "
          f"median {fin.cab_final.median():+.2f}; per country: "
          + "; ".join(f"{c} {g.cab_final.mean():+.1f} (n {len(g)})" for c, g in fin.groupby("country") if len(g) >= 3))


# ------------------------------------------------------------------ Q3
def q3(df):
    print("=" * 100)
    print("Q3. Polls per 30 days by days before the election (PR legislative, cycles polled >= 1000 days out), by decade")
    x = df[df.poll_.notna() & (df.pollcycle >= 1000)].drop_duplicates(["country", "elecdate", "polldate"]).copy()
    x["dec"] = (x.elecdate.dt.year // 10) * 10
    for dec, g in x.groupby("dec"):
        if g.elecdate.nunique() < 5:
            continue
        nel = g[["country", "elecdate"]].drop_duplicates().shape[0]
        cells = []
        for lo, hi in [(1, 30), (31, 90), (91, 180), (181, 365), (366, 730), (731, 1000)]:
            s = g[(g.daysbeforeED >= lo) & (g.daysbeforeED <= hi)]
            cells.append(f"{lo}-{hi}d: {s.npolls.sum() / nel / ((hi - lo + 1) / 30.4375):5.1f}")
        print(f"  {dec}s ({nel} cycles, {g.country.nunique()} countries): " + " | ".join(cells))
    s = df[df.poll_.notna() & (df.npolls == 1)].drop_duplicates(["country", "elecdate", "polldate"])
    s = s[s["sample"].notna()]
    print(f"  single-poll days with sample size, PR legislative: n polls {len(s)}, median sample {s['sample'].median():.0f}, "
          f"IQR {s['sample'].quantile(.25):.0f}..{s['sample'].quantile(.75):.0f}; since 2000: median {s[s.polldate.dt.year >= 2000]['sample'].median():.0f}")


def main():
    df = load()
    e, dates = election_table(df)
    print(f"PR legislative: elections {e.groupby(['country', 'elecdate']).ngroups}, countries {e.country.nunique()}")
    q2a(df)
    term_variance(e)
    q2cd(df, e)
    q3(df)


if __name__ == "__main__":
    main()
