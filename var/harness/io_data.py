"""Pull and cache the data behind the input-output cost weights (BEA 2017 detail Make/Use; EIA industrial power and gas prices)."""
import io, json, os, re, statistics as st, sys, urllib.request, zipfile
from collections import defaultdict
HERE = os.path.dirname(os.path.abspath(__file__))
CACHE = os.path.join(HERE, 'io_data.json')


def col2n(c):
    n = 0
    for ch in c:
        n = n * 26 + ord(ch) - 64
    return n


def sheet_grid(xlsx_bytes, sheet_name=None, sheet_index=None):
    x = zipfile.ZipFile(io.BytesIO(xlsx_bytes))
    ss = [re.sub(r'<[^>]+>', '', m) for m in re.findall(r'<si>(.*?)</si>', x.read('xl/sharedStrings.xml').decode(), re.S)] if 'xl/sharedStrings.xml' in x.namelist() else []
    if sheet_index is None:
        names = re.findall(r'<sheet name="([^"]+)"', x.read('xl/workbook.xml').decode())
        sheet_index = names.index(sheet_name) + 1
    sheet = x.read(f'xl/worksheets/sheet{sheet_index}.xml').decode()
    grid = []
    for row in re.findall(r'<row[^>]*>(.*?)</row>', sheet, re.S):
        d = {}
        for ref, attrs, body in re.findall(r'<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', row, re.S):
            v = re.search(r'<v>([^<]*)</v>', body)
            if v:
                d[col2n(ref)] = ss[int(v.group(1))] if 't="s"' in attrs else v.group(1)
            else:
                t = re.search(r'<t[^>]*>([^<]*)</t>', body)
                if t:
                    d[col2n(ref)] = t.group(1)
        grid.append(d)
    return grid


def table(grid):
    """A BEA table as {row code: {column code: value}} plus the ordered row and column codes and names."""
    hdr = next(i for i, r in enumerate(grid) if r.get(1) == 'Code')
    cols = {k: v for k, v in grid[hdr].items() if k >= 3}
    out, names, order = {}, {}, []
    for r in grid[hdr + 1:]:
        code = r.get(1)
        if not code:
            continue
        order.append(code)
        names[code] = r.get(2, '')
        out[code] = {cols[k]: float(v) for k, v in r.items() if k in cols and v not in ('', '...')}
    return out, order, [cols[k] for k in sorted(cols)], names


def pull():
    z = zipfile.ZipFile(io.BytesIO(urllib.request.urlopen('https://apps.bea.gov/industry/iTables%20Static%20Files/AllTablesIO.zip', timeout=180).read()))
    use, use_rows, use_cols, names = table(sheet_grid(z.read('IOUse_Before_Redefinitions_PRO_2017_Detail.xlsx'), '2017'))
    make, make_rows, make_cols, _ = table(sheet_grid(z.read('IOMake_Before_Redefinitions_2017_Detail.xlsx'), '2017'))

    # EIA-861M: monthly revenue (thousand $) and sales (MWh) by state and sector; the U.S. industrial price is their ratio.
    g = sheet_grid(urllib.request.urlopen('https://www.eia.gov/electricity/data/eia861m/xls/sales_revenue.xlsx', timeout=180).read(), sheet_index=1)
    # Layout: Year, Month, State, Data Status, then Residential, Commercial, INDUSTRIAL, Transportation, Total blocks of
    # (Thousand Dollars, Megawatthours, Count, Cents/kWh); the industrial block starts at column 13.
    assert g[2].get(13) == 'Thousand Dollars' and g[2].get(14) == 'Megawatthours', 'EIA-861M layout changed'
    industrial = defaultdict(lambda: [0.0, 0.0])
    for r in g[3:]:
        try:
            y, m = int(float(r[1])), int(float(r[2]))
            rev, sales = float(r.get(13, 0) or 0), float(r.get(14, 0) or 0)
        except (KeyError, ValueError):
            continue
        if 2017 <= y <= 2025 and r.get(3) not in ('US', 'PR'):
            industrial[f'{y}-{m:02d}'][0] += rev
            industrial[f'{y}-{m:02d}'][1] += sales
    elec = {k: round(1000.0 * v[0] / v[1], 4) for k, v in sorted(industrial.items()) if v[1] > 0}  # $ per MWh

    h = urllib.request.urlopen('https://www.eia.gov/dnav/ng/hist/n3035us3m.htm', timeout=60).read().decode()
    gas = {}
    for y, body in re.findall(r"<td class='B4'>&nbsp;&nbsp;(\d{4})</td>(.*?)</tr>", h, re.S):
        for m, v in enumerate(re.findall(r"<td class='B3'>([0-9.]*)</td>", body)):
            if v and 2017 <= int(y) <= 2025:
                gas[f'{y}-{m + 1:02d}'] = float(v)

    json.dump({'source': 'BEA AllTablesIO.zip (2017 detail, before redefinitions, producers prices); EIA-861M sales_revenue.xlsx; EIA N3035US3',
               'use': use, 'use_rows': use_rows, 'use_cols': use_cols, 'names': names,
               'make': make, 'make_rows': make_rows, 'make_cols': make_cols,
               'industrial_power_price': elec, 'industrial_gas_price': gas}, open(CACHE, 'w'))


if __name__ == '__main__':
    pull()
    d = json.load(open(CACHE))
    print('use rows', len(d['use_rows']), 'cols', len(d['use_cols']), '| make rows', len(d['make_rows']), 'cols', len(d['make_cols']))
    print('industrial power months', len(d['industrial_power_price']), list(d['industrial_power_price'].items())[:3], '... last', list(d['industrial_power_price'].items())[-2:])
    print('industrial gas months', len(d['industrial_gas_price']), list(d['industrial_gas_price'].items())[:3])
