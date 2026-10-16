"""The few numpy calls analyse.py needs, in pure Python (no numpy on this machine)."""
import math
import statistics


def mean(v):
    return statistics.fmean(v)


def median(v):
    return statistics.median(v)


def percentile(v, p):
    s = sorted(v)
    k = (len(s) - 1) * p / 100.0
    lo = math.floor(k)
    hi = min(lo + 1, len(s) - 1)
    return s[lo] + (s[hi] - s[lo]) * (k - lo)


def corr(x, y):
    pairs = [(a, b) for a, b in zip(x, y) if a is not None and b is not None and math.isfinite(a) and math.isfinite(b)]
    if len(pairs) < 4:
        return float('nan')
    return statistics.correlation([a for a, _ in pairs], [b for _, b in pairs])
