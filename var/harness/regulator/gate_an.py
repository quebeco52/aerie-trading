# gate_an.py <run dir>: regulator on vs noregulator (paired seeds), plus the +1pp step impulse vs off.
import json, sys, glob, statistics as st, math
D = sys.argv[1]
def load(pat):
    rows = {}
    for f in glob.glob(f'{D}/{pat}'):
        for line in open(f):
            r = json.loads(line); rows.setdefault(r['s'], []).append(r)
    return rows
on, off = load('on_[0-7].jsonl'), load('noregulator_[0-7].jsonl')
seeds = sorted(set(on) & set(off))
print('seeds', len(seeds), 'quarters/seed', len(on[seeds[0]]))
def moments(rows):
    g = [r['gap'] for r in rows]; i = [r['infl'] for r in rows]
    acf = lambda x, k: (lambda m: sum((x[t]-m)*(x[t-k]-m) for t in range(k, len(x)))/sum((v-m)**2 for v in x))(st.mean(x))
    crises = sum(1 for a, b in zip(rows, rows[1:]) if b['lastcrisis'] != a['lastcrisis'] and b['lastcrisis'] > 0)
    return dict(gap_sd=st.pstdev(g), gap_mean=st.mean(g), acf4=acf(g, 4), infl_mean=st.mean(i), infl_sd=st.pstdev(i), rate=st.mean(r['rate'] for r in rows),
                debt=rows[-1]['debt'], sloos=st.mean(r['sloos'] for r in rows), crises=crises, cdrag=st.mean(r['cdrag'] for r in rows),
                y10=st.mean(r['y10'] for r in rows), house_sd=st.pstdev([math.log(r['house']) for r in rows]))
keys = list(moments(on[seeds[0]]).keys())
print(f"{'moment':10s} {'on':>9s} {'noreg':>9s} {'diff':>9s} {'se':>8s}")
for k in keys:
    a = [moments(on[s])[k] for s in seeds]; b = [moments(off[s])[k] for s in seeds]
    d = [x - y for x, y in zip(a, b)]
    print(f"{k:10s} {st.mean(a):9.4f} {st.mean(b):9.4f} {st.mean(d):9.4f} {st.pstdev(d)/math.sqrt(len(d)):8.4f}")
# requirement distribution
req = [r['req'] for s in seeds for r in on[s]]
names = {'light': 0, 'middle': 0, 'strict': 0}
for x in req: names['light' if x < 0.0958 else ('strict' if x > 0.119 else 'middle')] += 1
print('requirement mean %.4f sd %.4f p5 %.4f p50 %.4f p95 %.4f' % (st.mean(req), st.pstdev(req), sorted(req)[len(req)//20], sorted(req)[len(req)//2], sorted(req)[19*len(req)//20]))
print('share of quarters', {k: round(v/len(req), 3) for k, v in names.items()})
heads = sum(len({r['regname'] for r in on[s]}) for s in seeds) / len(seeds)
creg_changes = st.mean(sum(1 for a, b in zip(on[s], on[s][1:]) if abs(b['creg'] - a['creg']) > 1e-9) for s in seeds)
print('heads per century %.1f; Council banks-median changes per century %.2f' % (heads, creg_changes))
cmed = st.mean(sum(1 for a, b in zip(on[s], on[s][1:]) if b['cmed'] != a['cmed']) for s in seeds)
maj = {1.0: 0, 0.0: 0, -1.0: 0}
for s in seeds:
    for r in on[s]: maj[r['maj']] += 1
tot = sum(maj.values())
print('money: Council median changes per century %.2f; committee hawkish %.2f dovish %.2f' % (cmed, maj[1.0]/tot, maj[-1.0]/tot))
# step impulse
imp_off, imp_on = load('off_imp.jsonl'), load('step_imp.jsonl')
print('\nstep impulse (+1pp at year 10), step minus off, mean over', len(imp_on), 'seeds')
print(f"{'yrs after':>9s} {'built':>7s} {'sloos':>8s} {'gap pp':>8s} {'hdti %':>8s} {'house %':>8s}")
for q in [1, 2, 4, 8, 12, 20, 40]:
    rows = []
    for s in imp_on:
        a = {r['t']: r for r in imp_on[s]}; b = {r['t']: r for r in imp_off[s]}
        t = round(10.0 + q / 4, 3)
        t0 = 10.0
        if t in a and t in b:
            rows.append((a[t]['built'] - b[t]['built'], a[t]['sloos'] - b[t]['sloos'], 100 * (a[t]['gap'] - b[t]['gap']),
                         100 * (math.log(a[t]['hdti']) - math.log(b[t]['hdti'])), 100 * (math.log(a[t]['house']) - math.log(b[t]['house']))))
    m = [st.mean(c) for c in zip(*rows)]
    print(f"{q/4:9.2f} {m[0]*100:7.3f} {m[1]:8.4f} {m[2]:8.3f} {m[3]:8.3f} {m[4]:8.3f}")
