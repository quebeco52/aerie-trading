"""Seed summaries for harness arms: the mean with its standard error, and the paired difference with its t."""

import math
from collections import namedtuple

MeanSe = namedtuple('MeanSe', 'n mean se')
Paired = namedtuple('Paired', 'n diff se t')


def mean_se(xs):
    """n, mean and standard error of the mean (sample sd over sqrt(n)); se is nan below two values."""
    xs = [float(x) for x in xs]
    n = len(xs)
    if n == 0:
        return MeanSe(0, math.nan, math.nan)
    mean = sum(xs) / n
    if n < 2:
        return MeanSe(n, mean, math.nan)
    var = sum((x - mean) ** 2 for x in xs) / (n - 1)
    return MeanSe(n, mean, math.sqrt(var / n))


def paired(a, b):
    """The paired difference b - a: n, mean difference, its standard error and t = diff / se.

    a and b are dicts keyed by seed (paired on the seeds in both) or equal-length sequences in seed order.
    """
    if isinstance(a, dict) and isinstance(b, dict):
        seeds = sorted(set(a) & set(b))
        d = [float(b[s]) - float(a[s]) for s in seeds]
    else:
        a, b = list(a), list(b)
        if len(a) != len(b):
            raise ValueError(f'paired: {len(a)} values against {len(b)}')
        d = [float(y) - float(x) for x, y in zip(a, b)]
    n, diff, se = mean_se(d)
    t = diff / se if n >= 2 and se > 0 else math.nan
    return Paired(n, diff, se, t)


def fmt(p, digits=3):
    """A paired() result as '+0.012 (0.004) t 3.0 n 16'."""
    return f'{p.diff:+.{digits}f} ({p.se:.{digits}f}) t {p.t:.1f} n {p.n}'


if __name__ == '__main__':
    # Self-check: a constant shift has se 0 (t nan); differences 1, 2, 0 give diff 1, se sqrt(1/3).
    assert paired([1, 2, 3], [2, 3, 4]).diff == 1.0 and math.isnan(paired([1, 2, 3], [2, 3, 4]).t)
    p = paired({1: 0.0, 2: 0.0, 3: 5.0}, {1: 1.0, 2: 2.0, 3: 5.0, 4: 9.0})
    assert p.n == 3 and abs(p.diff - 1.0) < 1e-12 and abs(p.se - math.sqrt(1.0 / 3.0)) < 1e-12, p
    m = mean_se([2.0, 4.0, 6.0])
    assert m.n == 3 and m.mean == 4.0 and abs(m.se - 2.0 / math.sqrt(3.0)) < 1e-12, m
    print('stats.py: ok', fmt(p))
