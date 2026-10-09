"""The vote's short-term swing in the polls: when it builds and how fast it fades.

1. Build-up (Jennings & Wlezien 2018 replication data, PR legislative elections, single-poll days):
   the error of a poll on the result, z2 = (poll - vote)^2 / p(1-p) in points^2, by days before the vote, fitted as
       z2(d) = e + b * min(d, W) / W + c * d
   e = a poll's own error on the day (effective sample 1e4 / e), b = what moves in the last W days, c = the slow drift.
2. Fade (Germany, wahlrecht.de releases 1998-2025, the five parties polled throughout):
   the campaign's change C = log(result / pre-campaign polls, days -120..-60) against the polls after the vote,
   D(m) = log(polls m months after / result): beta(m) = Cov(D(m), C) / Var(C). A swing that fades shows as beta(m)
   falling from 0 toward its floor; the month it gets halfway is the fade's half-life.

Run: python fit_campaign.py > fit_campaign.out
"""
import numpy as np
import pandas as pd

RNG = np.random.default_rng(20261005)
JW = "data/jw2018/LONG_MI_NATURE_20180111.dta"


def build_up():
    df = pd.read_stata(JW, convert_categoricals=False)
    df = df[(df.countryid != 20) & (df.election == "Legislative") & (df.rule == "PR")].copy()
    df["eid"] = df.country.astype(str) + "|" + df.elecdate.astype(str) + "|r" + df["round"].astype(int).astype(str)
    df = df[df.poll_.notna() & (df.poll_ > 0) & df.vote_.notna() & (df.daysbeforeED >= 1) & (df.npolls == 1)]
    df["p"] = df.vote_ / 100.0
    df["z2"] = (df.poll_ - df.vote_) ** 2 / (df.p * (1 - df.p))
    bins = [(1, 3), (4, 7), (8, 14), (15, 21), (22, 30), (31, 45), (46, 60), (61, 80), (81, 100), (101, 130),
            (131, 160), (161, 200), (201, 250), (251, 300)]
    rows = []
    for lo, hi in bins:
        w = df[(df.daysbeforeED >= lo) & (df.daysbeforeED <= hi)]
        eids = w.eid.unique()
        boots = []
        groups = {e: g.z2.values for e, g in w.groupby("eid")}
        for _ in range(300):
            pick = RNG.choice(eids, len(eids))
            boots.append(np.concatenate([groups[e] for e in pick]).mean())
        rows.append((lo, hi, w.daysbeforeED.mean(), w.z2.mean(), np.std(boots), len(w), len(eids), w["sample"].median()))
    print("=== 1. Poll error on the result by days out: PR legislative, single-poll days ===")
    print("  days       mean d   z2 = MSE/p(1-p)   se    rows  elections  median n")
    for lo, hi, d, z, se, n, ne, ns in rows:
        print(f"  {lo:3d}-{hi:<3d}   {d:6.1f}   {z:8.1f}        {se:5.1f}  {n:5d}  {ne:4d}       {ns:.0f}")
    d = np.array([r[2] for r in rows])
    z = np.array([r[3] for r in rows])
    se = np.array([r[4] for r in rows])
    best = None
    for W in range(10, 151, 5):
        X = np.c_[np.ones_like(d), np.minimum(d, W) / W, d]
        beta, *_ = np.linalg.lstsq(X / se[:, None], z / se, rcond=None)
        chi2 = (((z - X @ beta) / se) ** 2).sum()
        if best is None or chi2 < best[0]:
            best = (chi2, W, beta)
        if W % 15 == 0:
            print(f"   W {W:3d}: e {beta[0]:5.1f}  b {beta[1]:5.1f}  c {beta[2]:.3f}/day  chi2 {chi2:6.1f}")
    chi2, W, beta = best
    print(f"  best W {W} days: e {beta[0]:.1f} (effective sample {1e4 / beta[0]:.0f}), b {beta[1]:.1f}, c {beta[2]:.3f}/day, chi2 {chi2:.1f} on {len(d) - 4} df")
    # The same in the game's units: a party's log-share variance times its share, b * 1e-4 * (1 - p), at p = 0.2.
    print(f"  b in the game's scaled log units at p = 0.2: {beta[1] * 1e-4 * 0.8:.4f}; c per year: {beta[2] * 365 * 1e-4 * 0.8:.4f}")
    # Total error of a single poll on the result in the final three days against pure sampling at its own n.
    f = df[df.daysbeforeED <= 3]
    f = f[f["sample"] > 0]
    ratio = ((f.poll_ - f.vote_) ** 2 / (1e4 * f.p * (1 - f.p) / f["sample"])).mean()
    common = f.groupby(["eid", "partyid"]).apply(lambda g: (g.poll_ - g.vote_).mean(), include_groups=False)
    print(f"  final 3 days, single polls with n: rows {len(f)}, median n {f['sample'].median():.0f}, MSE / sampling var {ratio:.2f}")
    return W


def fade():
    polls = pd.read_csv("data/de_polls_long.csv", parse_dates=["date"])
    results = pd.read_csv("data/de_results.csv", parse_dates=["date"])
    parties = ["UNION", "SPD", "GRUENE", "FDP", "LINKE"]
    dates = sorted(results.date.unique())
    months = [1, 2, 3, 4, 6, 9, 12]
    rows = []
    for e in dates:
        e = pd.Timestamp(e)
        res = results[results.date == e].set_index("party")["median"]
        for party in parties:
            if party not in res.index:
                continue
            p = polls[polls.party == party]
            pre = p[(p.date >= e - pd.Timedelta(days=120)) & (p.date <= e - pd.Timedelta(days=60))].share_pct.mean()
            if not np.isfinite(pre) or pre <= 0:
                continue
            row = {"election": e.date(), "party": party, "C": np.log(res[party] / pre), "p": res[party] / 100.0}
            for m in months:
                w = p[(p.date >= e + pd.Timedelta(days=30 * m - 15)) & (p.date <= e + pd.Timedelta(days=30 * m + 15))]
                row[f"D{m}"] = np.log(w.share_pct.mean() / res[party]) if len(w) else np.nan
            rows.append(row)
    t = pd.DataFrame(rows)
    print("\n=== 2. Fade of the campaign's change after the vote: Germany, five parties ===")
    print(f"  party-elections {len(t)}, elections {t.election.nunique()}; scaled Var(C) = mean(p C^2) {np.mean(t.p * t.C ** 2):.4f}")
    print("  month  n    beta = Cov(D, C)/Var(C)   se (bootstrap by election)")
    elections = t.election.unique()
    for m in months:
        x = t[t[f"D{m}"].notna()]
        b = np.cov(x[f"D{m}"], x.C)[0, 1] / x.C.var()
        boots = []
        for _ in range(400):
            pick = RNG.choice(elections, len(elections))
            y = pd.concat([x[x.election == e] for e in pick])
            if y.C.var() > 0:
                boots.append(np.cov(y[f"D{m}"], y.C)[0, 1] / y.C.var())
        print(f"  {m:4d}  {len(x):3d}   {b:+.3f}                     {np.std(boots):.3f}")


if __name__ == "__main__":
    build_up()
    fade()
