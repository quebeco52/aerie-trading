"""check_probe.py <a.jsonl> <b.jsonl>: are two runs identical on every non-probe field? Prints one summary line."""
import json, sys

PROBE = {'dEp', 'dEpHeld', 'dTcr', 'dRet', 'dHur', 'dEva', 'dDisRaw', 'dDyRaw', 'dDis', 'dDy', 'dShed', 'dBuf', 'dScale', 'dHoard',
         'dAbort', 'dPE', 'dNNI', 'roic', 'roe', 'broic', 'gppe'}


def load(p):
    for line in open(p):
        r = json.loads(line)
        if 'final' in r:
            return r
    raise SystemExit(f'no final record in {p}')


a, b = load(sys.argv[1]), load(sys.argv[2])
diff, n = [], 0
for tk, fa in a['final'].items():
    fb = b['final'].get(tk)
    if fb is None:
        diff.append((tk, 'missing'))
        continue
    for k in ('dead', 'px'):
        n += 1
        if fa.get(k) != fb.get(k):
            diff.append((tk, k))
    for i, (qa, qb) in enumerate(zip(fa['q'], fb['q'])):
        for k, v in qa.items():
            if k in PROBE:
                continue
            n += 1
            if qb.get(k) != v:
                diff.append((tk, i, k))
    if len(fa['q']) != len(fb['q']):
        diff.append((tk, 'qlen'))
for k in ('dead', 'reorgs', 'defaults'):
    n += 1
    if a.get(k) != b.get(k):
        diff.append(('run', k))
eps = sum(1 for f in a['final'].values() for q in f['q'] if q.get('dEp'))
print(f'compared {n} values, {len(diff)} differ; probe arm: {len(a.get("sales", []))} sales, {eps} firm-quarters with an open episode; first diffs {diff[:5]}')
