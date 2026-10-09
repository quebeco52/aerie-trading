"""Poll-vote error by days before the election, party-size scaling, final-week error,
and the poll/previous-vote "pull" regression, on the Jennings & Wlezien (2018) replication data.

Data: Jennings, W. & Wlezien, C. (2017) "Replication Data for: Election Polling Errors across
Time and Space", Harvard Dataverse, doi:10.7910/DVN/8421DX, V3, file LONG_MI_NATURE_20180111.dta.
Variables (codebook): poll_ = raw poll-of-polls on that day, ipoll_ = interpolated poll,
vote_ = election-day vote, npolls = polls in the day's poll-of-polls, sample = their total n,
daysbeforeED, pollcycle, gov_ (party in government / governing coalition at the election).
Japan (countryid 20) dropped, day 0 dropped, as in the authors' do-files.

Run: python jw_poll_errors.py > jw_poll_errors.out
"""
import numpy as np
import pandas as pd

DATA = "data/jw2018/LONG_MI_NATURE_20180111.dta"
RNG = np.random.default_rng(20180312)
N_BOOT = 400


def load():
    df = pd.read_stata(DATA, convert_categoricals=False)
    df = df[df.countryid != 20].copy()
    df["eid"] = (df.country.astype(str) + "|" + df.election + "|" + df.elecdate.astype(str)
                 + "|r" + df["round"].astype(int).astype(str))
    df["err"] = df.poll_ - df.vote_
    df["p"] = df.vote_ / 100.0
    df.loc[df["sample"] <= 0, "sample"] = np.nan
    df.loc[df.poll_ <= 0, "poll_"] = np.nan  # 72 zero entries in the file: parties not yet polled
    return df


def counts(df, label):
    x = df[df.poll_.notna() & df.vote_.notna()]
    pdays = x.drop_duplicates(["eid", "polldate"])
    print(f"  [{label}] polls (sum npolls over poll-days) = {int(pdays.npolls.sum())}, "
          f"poll-days = {len(pdays)}, party x poll-day rows = {len(x)}, "
          f"elections = {x.eid.nunique()}, countries = {x.country.nunique()}")


def mae_by_day(df):
    print("\n=== 1. Mean absolute error |vote - poll| by days before election (raw poll_, Fig 1a recipe) ===")
    x = df[df.poll_.notna() & (df.daysbeforeED > 0)]
    by = x.groupby("daysbeforeED").err.agg(lambda s: s.abs().mean())
    n = x.groupby("daysbeforeED").err.size()
    print("  day   MAE(exact day)  MAE(+-5 day window)  rows(window)")
    for d in [400, 300, 200, 150, 100, 75, 50, 30, 14, 7, 3, 1]:
        w = x[(x.daysbeforeED >= d - 5) & (x.daysbeforeED <= d + 5)]
        print(f"  {d:4d}  {by.get(float(d), np.nan):6.2f}          {w.err.abs().mean():6.2f}"
              f"               {len(w)}")
    print("  Legislative only, cycles with pollcycle>=200 (Fig 1b recipe), +-5 day window:")
    y = x[(x.election == "Legislative") & (x.pollcycle >= 200)]
    for d in [200, 150, 100, 50, 30, 7]:
        w = y[(y.daysbeforeED >= d - 5) & (y.daysbeforeED <= d + 5)]
        print(f"    {d:4d}  MAE {w.err.abs().mean():5.2f}  rows {len(w)}  elections {w.eid.nunique()}")


BINS = [(1, 7), (8, 14), (15, 30), (31, 60), (61, 100), (101, 150), (151, 200), (201, 300),
        (301, 450), (451, 600), (601, 800), (801, 1000), (1001, 1200), (1201, 1500)]


def var_by_day(df, label, mask):
    print(f"\n=== 2. Error variance (poll - vote, points^2) by days out: {label} ===")
    x = df[mask & df.poll_.notna() & (df.daysbeforeED > 0)].copy()
    x["samp_var"] = 1e4 * x.p * (1 - x.p) / x["sample"]  # pure sampling var of the day's poll-of-polls
    print("  days        rows  elections  mean_err  MSE    var(err)  MSE/p(1-p)  mean samp var  MSE/samp var")
    out = []
    for lo, hi in BINS:
        w = x[(x.daysbeforeED >= lo) & (x.daysbeforeED <= hi)]
        if len(w) < 200:
            continue
        mse = (w.err ** 2).mean()
        r_pq = mse / (w.p * (1 - w.p)).mean()
        sv = w.samp_var.mean()
        print(f"  {lo:4d}-{hi:<5d} {len(w):6d}  {w.eid.nunique():5d}      {w.err.mean():+5.2f}  {mse:6.2f}  "
              f"{w.err.var():6.2f}    {r_pq:7.1f}      {sv:6.2f}        {mse / sv:5.2f}")
        out.append((lo, hi, mse))
    return out


def size_scaling(df, lo, hi, label, mask=None):
    """Fit E[err^2] = a * p^b on party-share bins; bootstrap b by election clusters.
    b = 1 -> variance proportional to p (sampling-like for small p); b = 2 -> sd proportional to p."""
    x = df[df.poll_.notna() & (df.daysbeforeED >= lo) & (df.daysbeforeED <= hi) & (df.p > 0.005)]
    if mask is not None:
        x = x[mask.reindex(x.index).fillna(False)]
    x = x[["eid", "p", "err", "sample"]].copy()
    edges = [0.0, 0.03, 0.06, 0.10, 0.15, 0.20, 0.30, 0.40, 0.50, 1.0]
    x["bin"] = pd.cut(x.p, edges)

    def fit(d):
        g = d.groupby("bin", observed=True).agg(p=("p", "mean"), mse=("err", lambda s: (s ** 2).mean()),
                                                 pq=("p", lambda s: (s * (1 - s)).mean()), n=("p", "size"))
        g = g[g.n >= 20]
        X = np.c_[np.ones(len(g)), np.log(g.p)]
        W = np.sqrt(g.n.values)
        beta = np.linalg.lstsq(X * W[:, None], np.log(g.mse.values) * W, rcond=None)[0]
        return beta, g

    (la, b), g = fit(x)
    eids = x.eid.unique()
    groups = {e: d for e, d in x.groupby("eid")}
    bs = []
    for _ in range(N_BOOT):
        pick = RNG.choice(eids, size=len(eids), replace=True)
        d = pd.concat([groups[e] for e in pick])
        try:
            bs.append(fit(d)[0][1])
        except Exception:
            pass
    print(f"\n  --- size scaling, {label}, days {lo}-{hi}: rows {len(x)}, elections {x.eid.nunique()} ---")
    print("   share bin        mean p   rows   RMSE(pts)  MSE/(p(1-p))  MSE/p    MSE/p^2")
    for idx, r in g.iterrows():
        print(f"   {str(idx):15s}  {r.p:.3f}  {int(r.n):6d}   {np.sqrt(r.mse):5.2f}     {r.mse / r.pq:7.1f}    "
              f"{r.mse / r.p:7.1f}  {r.mse / r.p ** 2:9.0f}")
    print(f"   fit log MSE = a + b log p :  b = {b:.2f}  (cluster-bootstrap SE {np.std(bs):.2f}, "
          f"95% {np.percentile(bs, 2.5):.2f}..{np.percentile(bs, 97.5):.2f})")
    return b


def final_week(df):
    print("\n=== 3. Final week (days 1-7) error ===")
    x = df[df.poll_.notna() & (df.daysbeforeED >= 1) & (df.daysbeforeED <= 7)].copy()
    counts(x, "final week, all elections")
    print(f"  MAE (party x poll-day rows) = {x.err.abs().mean():.2f} pts, RMSE = {np.sqrt((x.err ** 2).mean()):.2f}")
    # authors' unit: mean over poll-days within party x election, then mean
    pe = x.groupby(["eid", "partyid"]).agg(mae=("err", lambda s: s.abs().mean()), leg=("election", "first"),
                                           rule=("rule", "first"), p=("p", "first"))
    print(f"  MAE averaged per party x election (Table 2 unit) = {pe.mae.mean():.2f} (N={len(pe)}), "
          f"legislative {pe[pe.leg == 'Legislative'].mae.mean():.2f}, "
          f"PR legislative {pe[(pe.leg == 'Legislative') & (pe.rule == 'PR')].mae.mean():.2f}, "
          f"large (>=20%) {pe[pe.p >= 0.2].mae.mean():.2f}, small {pe[pe.p < 0.2].mae.mean():.2f}")
    y = x[x["sample"].notna() & (x.npolls == 1)].copy()
    y["z"] = y.err / (100 * np.sqrt(y.p * (1 - y.p) / y["sample"]))
    print(f"  single-poll days with n: rows {len(y)}, median n {y['sample'].median():.0f}; "
          f"RMSE / pure-sampling SD:  sqrt(mean z^2) = {np.sqrt((y.z ** 2).mean()):.2f}; "
          f"after removing each election x party mean (bias): "
          f"{np.sqrt((y.z - y.groupby(['eid', 'partyid']).z.transform('mean')).pow(2).mean()):.2f}")
    yl = y[(y.election == "Legislative") & (y.rule == "PR")]
    print(f"  PR legislative: rows {len(yl)}, sqrt(mean z^2) = {np.sqrt((yl.z ** 2).mean()):.2f}, "
          f"MAE {yl.err.abs().mean():.2f}")


def pull_regression(df):
    """Jennings-Wlezien (2016) style: vote = a + b*poll(t) + c*previous vote, by day t (interpolated polls)."""
    print("\n=== 4. Pull of the previous vote: vote = a + b*poll_t + c*prev_vote, by days out ===")
    leg = df[(df.election == "Legislative") & (df["round"] == 1)]
    res = (leg.drop_duplicates(["country", "elecdate", "partyid"])[["country", "elecdate", "partyid", "vote_"]]
           .sort_values(["country", "elecdate"]))
    dates = res[["country", "elecdate"]].drop_duplicates().sort_values(["country", "elecdate"])
    dates["prevdate"] = dates.groupby("country").elecdate.shift(1)
    res = res.merge(dates, on=["country", "elecdate"])
    prev = res[["country", "elecdate", "partyid", "vote_"]].rename(columns={"elecdate": "prevdate", "vote_": "prev_vote"})
    res = res.merge(prev, on=["country", "prevdate", "partyid"], how="left")
    leg = leg.merge(res[["country", "elecdate", "partyid", "prev_vote"]], on=["country", "elecdate", "partyid"], how="left")
    for label, m in [("all legislative", np.ones(len(leg), bool)), ("PR legislative", (leg.rule == "PR").values)]:
        print(f"  [{label}]  day   N(party x election)  elections   b(poll)  se     c(prev vote)  se     R2")
        L = leg[m]
        for d in [600, 400, 300, 200, 150, 100, 60, 30, 14, 7, 1]:
            w = L[(L.daysbeforeED == d) & L.ipoll_.notna() & L.prev_vote.notna()]
            if len(w) < 30:
                continue
            X = np.c_[np.ones(len(w)), w.ipoll_.values, w.prev_vote.values]
            yv = w.vote_.values
            beta, *_ = np.linalg.lstsq(X, yv, rcond=None)
            e = yv - X @ beta
            # cluster-robust (by election) covariance
            XtX_inv = np.linalg.inv(X.T @ X)
            meat = np.zeros((3, 3))
            for _, idx in w.reset_index(drop=True).groupby("eid").groups.items():
                s = X[idx].T @ e[idx]
                meat += np.outer(s, s)
            V = XtX_inv @ meat @ XtX_inv
            r2 = 1 - e.var() / yv.var()
            print(f"   {d:5d}   {len(w):6d}            {w.eid.nunique():4d}     {beta[1]:.3f}  {np.sqrt(V[1, 1]):.3f}   "
                  f"{beta[2]:.3f}        {np.sqrt(V[2, 2]):.3f}  {r2:.3f}")


def main():
    df = load()
    print("=== 0. Counts ===")
    counts(df, "all, Japan dropped")
    counts(df[df.daysbeforeED <= 200], "within 200 days")
    mae_by_day(df)
    var_by_day(df, "all elections", np.ones(len(df), bool))
    long_pr = (df.election == "Legislative") & (df.rule == "PR") & (df.pollcycle >= 1000)
    var_by_day(df, "PR legislative, cycles polled >=1000 days out", long_pr)
    print("\n=== 2b. Party-size scaling of the error variance ===")
    for lo, hi in [(1, 7), (31, 60), (151, 200), (301, 450), (801, 1200)]:
        size_scaling(df, lo, hi, "all elections")
    pr = (df.election == "Legislative") & (df.rule == "PR")
    for lo, hi in [(1, 7), (151, 200), (801, 1200)]:
        size_scaling(df, lo, hi, "PR legislative", pr)
    final_week(df)
    pull_regression(df)


if __name__ == "__main__":
    main()
