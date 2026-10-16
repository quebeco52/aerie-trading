"""Step-2 moments added to analyse_step1.moments (imported by analyse_step2.py)."""
import math, statistics as st


def pct(v, p):
    v = sorted(v); i = (len(v) - 1) * p; lo = int(i); hi = min(lo + 1, len(v) - 1)
    return v[lo] + (v[hi] - v[lo]) * (i - lo)


def acf1(x):
    m = st.mean(x); d = [a - m for a in x]
    return sum(a * b for a, b in zip(d, d[1:])) / sum(a * a for a in d)


def extra(r, burn):
    q = lambda f: [x for x in r[f][burn:] if x is not None] if r.get(f) else []
    m = {}
    pi = q('inflation'); yrs = len(pi) / 4
    g = q('realGdpQuarterlyGrowth')
    m['GDP q/q sd %'] = 100 * st.pstdev(g); m['GDP q/q acf1'] = acf1(g)
    ig = q('inventoryStockGap')
    m['inv gap sd'] = st.pstdev(ig); m['inv gap p5'] = pct(ig, 0.05); m['inv gap p95'] = pct(ig, 0.95)
    m['inv gap min'] = min(ig); m['inv gap max'] = max(ig)
    m['PMI sd'] = st.pstdev(q('manufacturingPmi'))
    # Year-end updates read from the index once a year, so the timing of the update tick does not matter.
    ri, cpl = q('reimbursementRateIndex'), q('consumerPriceLevel')
    yg = [b / a - 1 for a, b in zip(ri[::4], ri[4::4])]
    m['reimb g mean %'] = 100 * st.mean(yg); m['reimb g sd %'] = 100 * st.pstdev(yg)
    m['reimb g <=0 %'] = 100 * st.mean([1 if x <= 1e-12 else 0 for x in yg])
    m['log(R/P) drift /100y'] = (math.log(ri[-1] / cpl[-1]) - math.log(ri[0] / cpl[0])) * 100 / yrs
    # Same drift against a price level cumulated from headline inflation (annual rate, quarterly samples).
    m['logR-cumInfl /100y'] = (math.log(ri[-1] / ri[0]) - sum(x / 4 for x in pi[1:])) * 100 / yrs
    return m
