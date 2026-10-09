"""Shape of the governing parties' fall from their result over the term (Germany, 8 terms 1998-2025; the means and
standard errors by month of term from de_poll_dynamics.out, Q2c): fitted as gap(m) = A (1 - 2^(-m / H)) against a
straight line gap(m) = s m, weighted by 1 / se^2, months 1-36.

Run: python fit_cabinet_path.py > fit_cabinet_path.out
"""
import numpy as np

# month, n terms, mean cabinet gap (points), se: de_poll_dynamics.out, Q2c/Q2d.
PATH = [(1, 8, -0.65, 0.84), (2, 8, -1.42, 1.07), (3, 8, -1.32, 0.83), (4, 8, -2.41, 0.95), (5, 8, -3.00, 0.86),
        (6, 8, -2.67, 0.74), (9, 8, -5.47, 1.04), (12, 8, -7.66, 1.18), (15, 8, -6.49, 1.23), (18, 8, -6.83, 1.60),
        (21, 7, -7.90, 1.87), (24, 7, -7.94, 2.20), (27, 7, -7.80, 2.48), (30, 7, -7.84, 2.31), (33, 7, -9.01, 2.45),
        (36, 6, -8.72, 2.96)]
m = np.array([r[0] for r in PATH], float)
g = np.array([r[2] for r in PATH])
w = 1 / np.array([r[3] for r in PATH]) ** 2

best = None
for H in np.arange(1.0, 48.01, 0.25):
    x = 1 - 2 ** (-m / H)
    A = (w * x * g).sum() / (w * x * x).sum()
    chi2 = (w * (g - A * x) ** 2).sum()
    if best is None or chi2 < best[0]:
        best = (chi2, H, A)
    if H in (2, 3, 4, 5, 6, 8, 10, 12, 18, 24, 36, 48):
        print(f"  H {H:5.1f} months: A {A:6.2f} pts, chi2 {chi2:6.2f}")
chi2, H, A = best
grid = np.arange(1.0, 48.01, 0.25)
chis = []
for h in grid:
    x = 1 - 2 ** (-m / h)
    a = (w * x * g).sum() / (w * x * x).sum()
    chis.append((w * (g - a * x) ** 2).sum())
inside = grid[np.array(chis) <= chi2 + 1.0]
s = (w * m * g).sum() / (w * m * m).sum()
print(f"best H {H:.2f} months (chi2 + 1 interval {inside.min():.2f}..{inside.max():.2f}), A {A:.2f} pts, chi2 {chi2:.2f} on {len(m) - 2} df")
print(f"straight line: slope {s:.3f} pts/month, chi2 {(w * (g - s * m) ** 2).sum():.2f} on {len(m) - 1} df")
