"""HP-filter output gaps, like for like: python3 var/harness/hp_gap.py

Hodrick-Prescott (1997) two-sided trend, lambda 1600 on quarterly log real GDP, solved as the pentadiagonal system
(I + lambda K'K) tau = y by banded Cholesky. The same filter goes on Norway's mainland GDP (Statistics Norway 09190,
constant 2023 prices, seasonally adjusted: nor_mainland_gdp.json), US real GDP (FRED GDPC1: us_gdpc1.csv) and the
engine's own GDP (log potential + gap), so filtered gaps are compared with filtered gaps and no official gap method
enters. Import hp_cycle / acf from here; run it for the two data moments.
"""
import csv, json, math, os, statistics as st

H = os.path.dirname(os.path.abspath(__file__))
LAMBDA = 1600.0


def hp_trend(y, lam=LAMBDA):
    n = len(y)
    # Bands of A = I + lam * K'K (K the second difference): main, first and second off-diagonals.
    d0 = [1.0 + lam * c for c in ([1, 5] + [6] * (n - 4) + [5, 1])]
    d1 = [-lam * c for c in ([2] + [4] * (n - 3) + [2])]
    d2 = [lam] * (n - 2)
    # Banded Cholesky, bandwidth 2: A = L L'.
    l0, l1, l2 = [0.0] * n, [0.0] * n, [0.0] * n
    for i in range(n):
        s = d0[i] - (l1[i - 1] ** 2 if i >= 1 else 0.0) - (l2[i - 2] ** 2 if i >= 2 else 0.0)
        l0[i] = math.sqrt(s)
        if i + 1 < n:
            l1[i] = (d1[i] - (l2[i - 1] * l1[i - 1] if i >= 1 else 0.0)) / l0[i]
        if i + 2 < n:
            l2[i] = d2[i] / l0[i]
    z = [0.0] * n
    for i in range(n):
        z[i] = (y[i] - (l1[i - 1] * z[i - 1] if i >= 1 else 0.0) - (l2[i - 2] * z[i - 2] if i >= 2 else 0.0)) / l0[i]
    x = [0.0] * n
    for i in reversed(range(n)):
        x[i] = (z[i] - (l1[i] * x[i + 1] if i + 1 < n else 0.0) - (l2[i] * x[i + 2] if i + 2 < n else 0.0)) / l0[i]
    return x


def hp_cycle(log_level, lam=LAMBDA):
    tau = hp_trend(log_level, lam)
    return [a - b for a, b in zip(log_level, tau)]


def acf(x, lag):
    m = st.mean(x)
    v = sum((a - m) ** 2 for a in x)
    return sum((x[i] - m) * (x[i + lag] - m) for i in range(len(x) - lag)) / v


LAGS = (1, 2, 4, 6, 8, 12, 16)


def norway(first='1978K1', last='2019K4'):
    d = json.load(open(os.path.join(H, 'nor_mainland_gdp.json')))
    rows = [(t, v) for t, v in zip(d['t'], d['v']) if first <= t <= last]
    return [t for t, _ in rows], hp_cycle([math.log(v) for _, v in rows])


def usa(first='1978-01-01', last='2019-10-01'):
    rows = [(r['observation_date'], float(r['GDPC1'])) for r in csv.DictReader(open(os.path.join(H, 'us_gdpc1.csv')))]
    rows = [(t, v) for t, v in rows if first <= t <= last]
    return [t for t, _ in rows], hp_cycle([math.log(v) for _, v in rows])


if __name__ == '__main__':
    for label, (t, c) in (('Norway mainland 1978-2019', norway()), ('US 1978-2019', usa()), ('US 1985-2019', usa('1985-01-01'))):
        print(f"{label:26s} n {len(c)}  sd {100 * st.pstdev(c):.2f}%  ACF " + '  '.join(f"{L}q {acf(c, L):+.2f}" for L in LAGS))
