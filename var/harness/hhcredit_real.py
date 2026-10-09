"""Pulls the household credit cycle series (FRED + NY Fed HLW r*) into hhcredit_data.json."""
import io, json, os, urllib.request, zipfile, re

OUT = os.path.join(os.path.dirname(__file__), 'hhcredit_data.json')
FRED = ['GDP', 'CMDEBT', 'DSPI', 'USSTHPI', 'CPIAUCSL', 'MORTGAGE30US', 'FEDFUNDS', 'TDSP', 'DRTSCILM', 'GDPC1', 'GDPPOT']
UA = {'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36'}
data = {'fred': {}}
for s in FRED:
    lines = urllib.request.urlopen(f'https://fred.stlouisfed.org/graph/fredgraph.csv?id={s}', timeout=60).read().decode().splitlines()[1:]
    data['fred'][s] = {r.split(',')[0]: float(r.split(',')[1]) for r in lines if r.split(',')[1] not in ('.', '')}
    print(s, len(data['fred'][s]), min(data['fred'][s]), max(data['fred'][s]))
try:
    url = 'https://www.newyorkfed.org/medialibrary/media/research/economists/williams/data/Holston_Laubach_Williams_current_estimates.xlsx'
    raw = urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60).read()
    z = zipfile.ZipFile(io.BytesIO(raw))
    print('HLW sheets:', [n for n in z.namelist() if 'sheet' in n][:10])
    data['hlw_xlsx_members'] = z.namelist()
    open(os.path.join(os.path.dirname(__file__), 'hlw.xlsx'), 'wb').write(raw)
except Exception as e:
    print('HLW FAILED', e)
json.dump(data, open(OUT, 'w'))
