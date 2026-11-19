"""Tickers whose InitialMarket row states 'addressable_share' (the operating rows that build their own opening books).
Usage: python3 ops77.py prints them; other scripts import OPS77.
"""
import os
import re

_SRC = os.path.join(os.path.dirname(os.path.abspath(__file__)), '../../../src/Data/Company/InitialMarket.php')


def _load():
    text = open(_SRC).read()
    out, cur = [], None
    for line in text.splitlines():
        m = re.search(r"'ticker' => '([A-Z]+)'", line)
        if m:
            cur = m.group(1)
        elif cur and "'addressable_share' =>" in line:
            out.append(cur)
            cur = None
    return out


OPS77 = _load()

if __name__ == '__main__':
    print(len(OPS77), ' '.join(OPS77))
