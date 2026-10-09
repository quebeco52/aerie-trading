"""Reads JSTdatasetR6.xlsx (Jorda-Schularick-Taylor Macrohistory Database) into a list of dicts."""
import re, zipfile, xml.etree.ElementTree as ET, os

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}


def col_index(ref):
    letters = re.match(r'[A-Z]+', ref).group()
    n = 0
    for ch in letters:
        n = n * 26 + ord(ch) - 64
    return n - 1


def load(path=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'jst.xlsx')):
    z = zipfile.ZipFile(path)
    ss = [''.join(t.text or '' for t in si.findall('.//m:t', NS)) for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', NS)] if 'xl/sharedStrings.xml' in z.namelist() else []
    root = ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
    table = []
    for r in root.find('m:sheetData', NS):
        row = {}
        for c in r:
            v = c.find('m:v', NS)
            if v is None:
                continue
            row[col_index(c.get('r'))] = ss[int(v.text)] if c.get('t') == 's' else v.text
        table.append(row)
    header = [table[0].get(i) for i in range(max(table[0]) + 1)]
    out = []
    for row in table[1:]:
        d = {}
        for i, name in enumerate(header):
            val = row.get(i)
            if val is None or val == 'NA':
                d[name] = None
            else:
                try:
                    d[name] = float(val)
                except ValueError:
                    d[name] = val
        out.append(d)
    return out
