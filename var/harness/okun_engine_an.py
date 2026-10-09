"""The Okun slope of engine scan output measured exactly as okun.py measures data: HP(1600) cycles of u and of 100 log
real output (potential x (1 + gap)) over 96-quarter windows, averaged across windows. python3 var/harness/okun_engine_an.py <dir> <arm>... (gapsplit_run.sh scans)"""
import glob, json, math, os, sys
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'okun_real.py')).read()
ns = {'math': math}; exec(src[src.index('def hp'):src.index('PAIRS =')], ns); hp = ns['hp']
D = sys.argv[1]
for arm in sys.argv[2:]:
    by = {}
    for f in glob.glob(f'{D}/{arm}diag-*.ndjson'):
        for line in open(f):
            r = json.loads(line)
            if r['total_time'] > 10:
                by.setdefault(r['seed'], []).append(r)
    slopes, ratios, corrs, acf4 = [], [], [], []
    for rs in by.values():
        for w in range(0, len(rs) - 95, 96):
            win = rs[w:w + 96]
            uc = hp([100 * r['unemployment_rate'] for r in win])
            yc = hp([100 * math.log(r['potential_gdp_index'] * (1 + r['output_gap'])) for r in win])
            n = len(uc); mu, my = sum(uc) / n, sum(yc) / n
            su = math.sqrt(sum((x - mu) ** 2 for x in uc) / n); sy = math.sqrt(sum((x - my) ** 2 for x in yc) / n)
            cov = sum((a - mu) * (c - my) for a, c in zip(uc, yc)) / n
            slopes.append(cov / sy ** 2); ratios.append(su / sy); corrs.append(cov / (su * sy))
            acf4.append(sum((uc[i] - mu) * (uc[i - 4] - mu) for i in range(4, n)) / sum((v - mu) ** 2 for v in uc))
    m = lambda x: sum(x) / len(x)
    sd = lambda x: math.sqrt(sum((v - m(x)) ** 2 for v in x) / (len(x) - 1))
    print(f'{arm}: {len(slopes)} windows  slope {m(slopes):+.3f} (window sd {sd(slopes):.3f})  corr {m(corrs):+.2f}  u/y sd {m(ratios):.2f}  uACF4 {m(acf4):.2f}')
