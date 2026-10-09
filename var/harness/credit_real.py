"""Pulls the real credit-cycle series: spreads, VIX, TED, CBO gap, GZ excess bond premium. Caches to credit_data.json."""
import json, os, urllib.request

OUT = os.path.join(os.path.dirname(__file__), 'credit_data.json')
FRED = ['BAA10Y', 'AAA10Y', 'BAMLC0A0CM', 'BAMLH0A0HYM2', 'VIXCLS', 'TEDRATE', 'GDPC1', 'GDPPOT', 'DRTSCILM', 'NFCI']


def fred(series):
    lines = urllib.request.urlopen(f'https://fred.stlouisfed.org/graph/fredgraph.csv?id={series}', timeout=60).read().decode().splitlines()[1:]
    return {r.split(',')[0]: float(r.split(',')[1]) for r in lines if r.split(',')[1] not in ('.', '')}


data = {'fred': {}}
for s in FRED:
    try:
        data['fred'][s] = fred(s)
        print(s, len(data['fred'][s]), min(data['fred'][s]), max(data['fred'][s]))
    except Exception as e:
        print(s, 'FAILED', e)
try:
    raw = urllib.request.urlopen('https://www.federalreserve.gov/econres/notes/feds-notes/ebp_csv.csv', timeout=60).read().decode()
    data['ebp'] = raw
    print('ebp', len(raw.splitlines()))
except Exception as e:
    print('ebp FAILED', e)
json.dump(data, open(OUT, 'w'))
