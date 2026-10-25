"""Minimal xlsx reader (no openpyxl in the sandbox): one sheet as a list of {column: value} rows."""
import io, re, zipfile


def col2n(c):
    n = 0
    for ch in c:
        n = n * 26 + ord(ch) - 64
    return n


def sheet_names(path):
    x = zipfile.ZipFile(path)
    return re.findall(r'<sheet name="([^"]+)"', x.read('xl/workbook.xml').decode())


def sheet_grid(path, sheet_name):
    x = zipfile.ZipFile(path)
    ss = []
    if 'xl/sharedStrings.xml' in x.namelist():
        for m in re.findall(r'<si>(.*?)</si>', x.read('xl/sharedStrings.xml').decode(), re.S):
            ss.append(''.join(re.findall(r'<t[^>]*>([^<]*)</t>', m)))
    names = sheet_names(path)
    # Sheet order in workbook.xml maps to rId; resolve through the rels file.
    wb = x.read('xl/workbook.xml').decode()
    rels = x.read('xl/_rels/workbook.xml.rels').decode()
    rid = dict(re.findall(r'<sheet name="([^"]+)"[^>]*r:id="([^"]+)"', wb))[sheet_name]
    target = re.search(r'Id="%s"[^>]*Target="([^"]+)"' % rid, rels) or re.search(r'Target="([^"]+)"[^>]*Id="%s"' % rid, rels)
    path_in = 'xl/' + target.group(1).lstrip('/').replace('xl/', '')
    sheet = x.read(path_in).decode()
    grid = []
    for row in re.findall(r'<row[^>]*>(.*?)</row>', sheet, re.S):
        d = {}
        for ref, attrs, body in re.findall(r'<c r="([A-Z]+)\d+"([^>]*?)(?:/>|>(.*?)</c>)', row, re.S):
            v = re.search(r'<v>([^<]*)</v>', body or '')
            if v:
                d[col2n(ref)] = ss[int(v.group(1))] if 't="s"' in attrs else v.group(1)
            else:
                t = re.search(r'<t[^>]*>([^<]*)</t>', body or '')
                if t:
                    d[col2n(ref)] = t.group(1)
        grid.append(d)
    return grid
