import json, math, sys, csv, os

def hp(y, lam=1600.0):
    n = len(y)
    # (I + lam D'D) tau = y, D second difference; pentadiagonal SPD, solve by banded Cholesky-free elimination
    a = [[0.0]*5 for _ in range(n)]  # a[i][j] for column i-2+j
    for i in range(n):
        a[i][2] = 1.0
    for t in range(n-2):
        idx = [t, t+1, t+2]; w = [1.0, -2.0, 1.0]
        for p in range(3):
            for q in range(3):
                i, j = idx[p], idx[q]
                a[i][j-i+2] += lam*w[p]*w[q]
    b = list(y)
    # Gaussian elimination within band
    for k in range(n):
        piv = a[k][2]
        for i in range(k+1, min(k+3, n)):
            f = a[i][k-i+2]/piv
            if f == 0: continue
            for j in range(k, min(k+3, n)):
                a[i][j-i+2] -= f*a[k][j-k+2]
            b[i] -= f*b[k]
    x = [0.0]*n
    for k in range(n-1, -1, -1):
        s = b[k]
        for j in range(k+1, min(k+3, n)):
            s -= a[k][j-k+2]*x[j]
        x[k] = s/a[k][2]
    return x

def mean(x): return sum(x)/len(x)
def sd(x):
    m = mean(x); return math.sqrt(sum((v-m)**2 for v in x)/(len(x)-1))
def acf(x, k):
    m = mean(x); d = [v-m for v in x]
    return sum(d[t]*d[t-k] for t in range(k, len(d)))/sum(v*v for v in d)
def ar1(x):
    xs, ys = x[:-1], x[1:]
    mx, my = mean(xs), mean(ys)
    return sum((a-mx)*(b-my) for a, b in zip(xs, ys))/sum((a-mx)**2 for a in xs)
def runs(x):
    out = []; cur = None; n = 0
    for v in x:
        s = v >= 0
        if s == cur: n += 1
        else:
            if cur is not None: out.append((cur, n))
            cur, n = s, 1
    out.append((cur, n))
    return out
def spell_stats(x):
    r = runs(x)
    inner = r[1:-1] if len(r) > 2 else r
    pos = [n for s, n in inner if s]; neg = [n for s, n in inner if not s]
    return dict(crossings=len(r)-1, mean_spell=mean([n for _, n in inner]) if inner else float('nan'),
                pos=mean(pos) if pos else float('nan'), neg=mean(neg) if neg else float('nan'),
                longest=max(n for _, n in r), longest_neg=max([n for s, n in r if not s] or [0]))
def growth_stats(lny):
    g = [100*(lny[t]-lny[t-1]) for t in range(1, len(lny))]
    g4 = [100*(lny[t]-lny[t-4]) for t in range(4, len(lny))]
    tech = sum(1 for t in range(1, len(g)) if g[t] < 0 and g[t-1] < 0 and not (t >= 2 and g[t-2] < 0))
    return dict(g_mean=4*mean(g), g_sd=sd(g), g_ar1=ar1(g), g4_sd=sd(g4), neg_q=sum(1 for v in g if v < 0)/len(g), tech_rec=tech,
                g_min=min(g), g_max=max(g))
def row(name, cyc, lny=None, n=None):
    c = [100*v for v in cyc]
    rho = ar1(c)
    hl = math.log(0.5)/math.log(rho) if 0 < rho < 1 else float('inf')
    s = spell_stats(c)
    out = dict(name=name, n=len(c), mean=mean(c), sd=sd(c), min=min(c), max=max(c), ar1=rho, hl=hl,
               acf4=acf(c, 4), acf8=acf(c, 8), acf12=acf(c, 12), acf20=acf(c, 20), **s)
    if lny: out.update(growth_stats(lny))
    return out

