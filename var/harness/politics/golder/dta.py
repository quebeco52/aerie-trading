"""Minimal Stata 113 (.dta, Stata 8) reader: returns a list of dict rows with missing values as None."""
import struct


def read(path):
    b = open(path, 'rb').read()
    assert b[0] == 113, b[0]
    le = b[1] == 2
    e = '<' if le else '>'
    nvar, nobs = struct.unpack(e + 'hi', b[4:10])
    p = 10 + 81 + 18
    types = list(b[p:p + nvar]); p += nvar
    names = [b[p + 33 * i:p + 33 * (i + 1)].split(b'\0')[0].decode() for i in range(nvar)]; p += 33 * nvar
    p += 2 * (nvar + 1) + 12 * nvar + 33 * nvar + 81 * nvar
    while True:
        t = b[p]; ln = struct.unpack(e + 'i', b[p + 1:p + 5])[0]; p += 5
        if t == 0 and ln == 0:
            break
        p += ln
    fmt = {251: ('b', 1, 100), 252: ('h', 2, 32740), 253: ('i', 4, 2147483620), 254: ('f', 4, 1.7e38), 255: ('d', 8, 8.9e307)}
    rows = []
    for _ in range(nobs):
        r = {}
        for n, t in zip(names, types):
            if t in fmt:
                c, w, miss = fmt[t]
                v = struct.unpack(e + c, b[p:p + w])[0]; p += w
                r[n] = None if v > miss else v
            else:
                r[n] = b[p:p + t].split(b'\0')[0].decode('latin-1'); p += t
        rows.append(r)
    return rows
