"""Revenue each firm books against what the same quarter's line multipliers give at its opening weights.

share_k = w_k m_k / sum(w m), so m_k is proportional to share_k / w_k, and revenue at the opening weights t over actual
revenue is sum(t_k share_k / w_k). Firms whose lines' weights stay put read zero.
"""
import glob, json, statistics as st, sys
from collections import defaultdict

arm = sys.argv[1] if len(sys.argv) > 1 else 'base'
out = defaultdict(lambda: {'mean': [], 'max': [], 'end': []})
models = {}
for path in sorted(glob.glob(f'runs/{arm}-s*.json')):
    d = json.load(open(path))
    for t, f in d['firms'].items():
        models[t] = f['model']
        q = [r for r in f['q'] if isinstance(r['w'], dict) and isinstance(r['s'], dict)]
        if len(q) < 8:
            continue
        t0 = q[0]['w']
        devs = []
        for r in q:
            ratio = sum(t0[k] * r['s'].get(k, 0) / r['w'][k] for k in t0 if r['w'].get(k, 0) > 0)
            if ratio > 0:
                devs.append(1 / ratio - 1)
        if devs:
            out[t]['mean'].append(st.mean(abs(x) for x in devs)); out[t]['max'].append(max(devs, key=abs)); out[t]['end'].append(devs[-1])
rows = sorted(out.items(), key=lambda kv: -max(kv[1]['mean']))
print('ticker model                         mean|dev| per seed          worst quarter per seed')
for t, s in rows[:30]:
    print(f"{t:5}  {models[t]:28}  {' '.join(f'{100*x:5.1f}%' for x in s['mean'])}   {' '.join(f'{100*x:+6.1f}%' for x in s['max'])}")
print(f"firms with mean |dev| > 3% in any seed: {sum(1 for t, s in rows if max(s['mean']) > 0.03)}, > 10%: {sum(1 for t, s in rows if max(s['mean']) > 0.10)}")
