import zipfile, re, xml.etree.ElementTree as ET
M = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
def load(path, sheet_index):
    z = zipfile.ZipFile(path)
    ss = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).iter(M + 'si'):
            ss.append(''.join(t.text or '' for t in si.iter(M + 't')))
    rels = ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))
    target = {r.get('Id'): r.get('Target') for r in rels}
    wb = ET.fromstring(z.read('xl/workbook.xml'))
    rid = list(wb.find(M + 'sheets'))[sheet_index].get('{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id')
    t = target[rid]; t = t if t.startswith('xl/') else 'xl/' + t.lstrip('/')
    rows = []
    for row in ET.fromstring(z.read(t)).iter(M + 'row'):
        out = {}
        for c in row.iter(M + 'c'):
            col = re.match(r'[A-Z]+', c.get('r')).group(0)
            v = c.find(M + 'v'); tp = c.get('t')
            if v is None:
                isv = c.find(M + 'is'); val = ''.join(x.text or '' for x in isv.iter(M + 't')) if isv is not None else None
            else:
                val = ss[int(v.text)] if tp == 's' else v.text
            out[col] = val
        rows.append(out)
    return rows
def colnum(c):
    n = 0
    for ch in c: n = n * 26 + ord(ch) - 64
    return n
