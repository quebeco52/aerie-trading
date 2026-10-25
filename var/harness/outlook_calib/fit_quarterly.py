"""Random walk + noise on BEA quarterly gross-output shares (71-industry level, 2005Q1-2026Q2), the game's own step.

Each category pools a common q/r (each industry its own scale). The steady quarterly gain K_q gives the learning
half-life in years, 0.25 ln 0.5 / ln(1 - K_q); sigma_eta per year is sqrt(4 q). 2020Q1-2021Q4 held out as missing.

python3 -I fit_quarterly.py [nogap]
"""
import csv, json, math, os, re, sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from kalman import fit_pooled, ratio_interval, steady_gain  # noqa: E402
from xlsx import sheet_grid  # noqa: E402

CATS = {
    'settled': ['Food and beverage stores', 'Utilities', 'Hospitals', 'Nursing and residential care facilities',
                'Ambulatory health care services', 'Educational services', 'Legal services', 'Waste management and remediation services',
                'Food and beverage and tobacco products', 'Paper products', 'Housing', 'Social assistance', 'General merchandise stores',
                'Food services and drinking places', 'Truck transportation'],
    'industrial': ['Construction', 'Wood products', 'Nonmetallic mineral products', 'Fabricated metal products', 'Machinery',
                   'Electrical equipment, appliances, and components', 'Plastics and rubber products', 'Wholesale trade',
                   'Rail transportation', 'Warehousing and storage', 'Other transportation and support activities',
                   'Computer systems design and related services', 'Miscellaneous professional, scientific, and technical services',
                   'Administrative and support services', 'Broadcasting and telecommunications', 'Other real estate', 'Chemical products'],
    'discretionary': ['Air transportation', 'Accommodation', 'Amusements, gambling, and recreation industries',
                      'Performing arts, spectator sports, museums, and related activities', 'Apparel and leather and allied products',
                      'Motion picture and sound recording industries', 'Furniture and related products', 'Miscellaneous manufacturing',
                      'Motor vehicle and parts dealers', 'Other retail'],
    'frontier': ['Computer and electronic products', 'Data processing, internet publishing, and other information services',
                 'Publishing industries, except internet (includes software)', 'Other transportation equipment',
                 'Motor vehicles, bodies and trailers, and parts', 'Securities, commodity contracts, and investments'],
    'commodity': ['Farms', 'Oil and gas extraction', 'Mining, except oil and gas', 'Support activities for mining',
                  'Petroleum and coal products', 'Water transportation', 'Pipeline transportation', 'Primary metals'],
}


def load():
    g = sheet_grid(os.path.join(HERE, 'data', 'GrossOutput.xlsx'), 'TGO105-Q')
    header, rows = None, {}
    for r in g:
        v = [r[k] for k in sorted(r)]
        if v and v[0] == 'Line':
            header = v[1:]
            continue
        if header and len(v) == len(header) + 2:
            rows.setdefault(v[1].strip(), [float(x) if re.fullmatch(r'-?[0-9.]+', x) and x.strip('.') else None for x in v[2:]])
    return header, rows


def gap(quarters):
    def read(path, col):
        out = {}
        with open(path) as f:
            for row in csv.DictReader(f):
                y, m = int(row['observation_date'][:4]), int(row['observation_date'][5:7])
                out[f'{y}Q{(m - 1) // 3 + 1}'] = float(row[col])
        return out
    real = read(os.path.join(HERE, '..', 'us_gdpc1.csv'), 'GDPC1')
    pot = read(os.path.join(HERE, '..', 'us_gdppot.csv'), 'GDPPOT')
    return [math.log(real[q] / pot[q]) if q in real and q in pot else 0.0 for q in quarters]


def main():
    quarters, rows = load()
    total = rows['All industries']
    gp = gap(quarters)
    X = () if 'nogap' in sys.argv else (gp,)
    print(f"{'category':14s} {'n':>2s} {'eta/yr':>7s} {'eps':>6s} {'q/r':>7s} {'95% q/r':>14s} {'K_q':>5s} {'HL yr':>6s} {'HL 95%':>10s}")
    out = {}
    for cat, names in CATS.items():
        ys = []
        for n in names:
            y = [math.log(v / t) if v and t else None for v, t in zip(rows[n], total)]
            y = [None if q.startswith('2020') or q.startswith('2021') else v for q, v in zip(quarters, y)]
            ys.append(y)
        ratio, ll, rs, prof = fit_pooled(ys, X)
        lo, hi = ratio_interval(prof)
        med = lambda xs: sorted(xs)[len(xs) // 2]
        eta = med([math.sqrt(4 * ratio * r) for r in rs])
        eps = med([math.sqrt(r) for r in rs])
        hl = lambda rt: 0.25 * math.log(0.5) / math.log(1 - steady_gain(rt)) if 0 < steady_gain(rt) < 1 else (float('inf') if rt == 0 else 0.0)
        out[cat] = {'ratio': ratio, 'eta': eta, 'eps': eps, 'hl': hl(ratio), 'hl_lo': hl(hi), 'hl_hi': hl(lo),
                    'per': {n: (math.sqrt(4 * ratio * r), math.sqrt(r)) for n, r in zip(names, rs)}}
        print(f"{cat:14s} {len(names):2d} {eta:7.3f} {eps:6.3f} {ratio:7.3f} {lo:6.3f}-{hi:7.3f} {steady_gain(ratio):5.2f} {hl(ratio):6.2f} {hl(hi):4.2f}-{hl(lo):5.2f}")
    json.dump(out, open(os.path.join(HERE, 'data', 'fit_quarterly.json'), 'w'), indent=1)


if __name__ == '__main__':
    main()
