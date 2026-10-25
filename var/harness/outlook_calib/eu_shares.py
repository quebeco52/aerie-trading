"""Cross-check series at matching aggregation: Eurostat nama_10_a64 output (P1, current prices) for ten EU economies
and BEA's 71-industry annual gross output, as log shares of total output, 1997-2019, grouped by the same categories.

python3 -I eu_shares.py  ->  data/eu_shares.json, data/us71_shares.json (keys 'geo|category|industry')
"""
import json, math, os, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from xlsx import sheet_grid  # noqa: E402
from fit_quarterly import CATS as US71_CATS  # noqa: E402

EU_CATS = {
    'settled': ['C10-C12', 'C17', 'D35', 'E36', 'E37-E39', 'G47', 'I', 'P85', 'Q86', 'Q87_Q88', 'M69_M70', 'H49'],
    'industrial': ['F', 'C16', 'C20', 'C22', 'C23', 'C25', 'C27', 'C28', 'C33', 'G46', 'H52', 'M71', 'M73', 'N77', 'N78',
                   'N80-N82', 'J61', 'J62_J63'],
    'discretionary': ['H51', 'C13-C15', 'C31_C32', 'J59_J60', 'R90-R92', 'R93', 'G45', 'N79', 'S96'],
    'frontier': ['C26', 'C21', 'C29', 'C30', 'J58', 'M72'],
    'commodity': ['A01', 'B', 'C19', 'C24', 'H50'],
}
YEARS = [str(y) for y in range(1997, 2020)]


def eu():
    d = json.load(open(os.path.join(HERE, 'data', 'eu_a64.json')))
    ids, size = d['id'], d['size']
    stride = [1] * len(size)
    for i in range(len(size) - 2, -1, -1):
        stride[i] = stride[i + 1] * size[i + 1]
    idx = {k: d['dimension'][k]['category']['index'] for k in ids}

    def val(nace, geo, year):
        pos = {'freq': 0, 'unit': 0, 'na_item': 0, 'nace_r2': idx['nace_r2'][nace], 'geo': idx['geo'][geo], 'time': idx['time'][year]}
        v = d['value'].get(str(sum(pos[k] * stride[i] for i, k in enumerate(ids))))
        return v
    out = {}
    for geo in idx['geo']:
        for cat, codes in EU_CATS.items():
            for code in codes:
                s = []
                for y in YEARS:
                    v, t = val(code, geo, y), val('TOTAL', geo, y)
                    s.append(math.log(v / t) if v and t and v > 0 else None)
                if all(x is not None for x in s):
                    out[f'{geo}|{cat}|{code}'] = s
    return out


def us71():
    g = sheet_grid(os.path.join(HERE, 'data', 'GrossOutput.xlsx'), 'TGO105-A')
    header, rows = None, {}
    for r in g:
        v = [r[k] for k in sorted(r)]
        if v and v[0] == 'Line':
            header = v[1:]
            continue
        if header and len(v) == len(header) + 2:
            rows.setdefault(v[1].strip(), dict(zip(header, v[2:])))
    out = {}
    for cat, names in US71_CATS.items():
        for n in names:
            s = [math.log(float(rows[n][y]) / float(rows['All industries'][y])) for y in YEARS]
            out[f'US|{cat}|{n}'] = s
    return out


if __name__ == '__main__':
    e = eu()
    json.dump({'series': e}, open(os.path.join(HERE, 'data', 'eu_shares.json'), 'w'))
    u = us71()
    json.dump({'series': u}, open(os.path.join(HERE, 'data', 'us71_shares.json'), 'w'))
    print('eu series', len(e), 'us71 series', len(u))
