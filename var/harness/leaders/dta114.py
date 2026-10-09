"""Minimal reader for Stata .dta format 113/114/115 (pure Python), returns (columns, rows, labels)."""
import struct

def read_dta(path):
    b = open(path, 'rb').read()
    fmt = b[0]
    assert fmt in (113, 114, 115), fmt
    e = '<' if b[1] == 2 else '>'
    nvar, = struct.unpack(e + 'H', b[4:6]); nobs, = struct.unpack(e + 'I', b[6:10])
    p = 10 + 81 + 18
    types = list(b[p:p + nvar]); p += nvar
    names = [b[p + 33 * i:p + 33 * (i + 1)].split(b'\0')[0].decode('latin1') for i in range(nvar)]; p += 33 * nvar
    p += 2 * (nvar + 1)
    p += 49 * nvar
    p += 33 * nvar
    labels = [b[p + 81 * i:p + 81 * (i + 1)].split(b'\0')[0].decode('latin1') for i in range(nvar)]; p += 81 * nvar
    while True:
        t = b[p]; ln, = struct.unpack(e + 'i', b[p + 1:p + 5]); p += 5
        if t == 0 and ln == 0:
            break
        p += ln
    spec = {251: ('b', 1, 100), 252: ('h', 2, 32740), 253: ('i', 4, 2147483620), 254: ('f', 4, 1.701e38), 255: ('d', 8, 8.988e307)}
    rows = []
    for _ in range(nobs):
        row = []
        for t in types:
            if t <= 244:
                row.append(b[p:p + t].split(b'\0')[0].decode('latin1')); p += t
            else:
                c, n, miss = spec[t]
                v, = struct.unpack(e + c, b[p:p + n]); p += n
                row.append(None if v > miss else v)
        rows.append(row)
    return names, rows, labels

if __name__ == '__main__':
    import sys, csv
    names, rows, labels = read_dta(sys.argv[1])
    w = csv.writer(open(sys.argv[2], 'w', newline=''))
    w.writerow(names); w.writerows(rows)
    for n, l in zip(names, labels):
        print(n, '|', l)
    print(len(rows), 'rows')
