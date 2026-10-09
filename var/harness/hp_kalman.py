"""Steady-state Kalman gains of the HP filter's state-space form (Harvey & Jaeger 1993), for the one-sided Basel gap.

y_t = tau_t + eps_t,  tau_{t+1} = tau_t + beta_t,  beta_{t+1} = beta_t + eta_t,  var(eta)/var(eps) = 1/lambda.
The filtered tau_t is the one-sided HP trend; with the steady-state gains it is a two-line recursion.
"""
import json, os, random, sys

LAM = 400000.0
q = 1.0 / LAM
P = [[1e6, 0.0], [0.0, 1e6]]
for _ in range(200000):
    # predict: P- = F P F' + Q, F = [[1,1],[0,1]], Q = diag(0, q)
    a, b, c = P[0][0], P[0][1], P[1][1]
    Pm = [[a + 2 * b + c, b + c], [b + c, c + q]]
    s = Pm[0][0] + 1.0
    k1, k2 = Pm[0][0] / s, Pm[1][0] / s
    newP = [[(1 - k1) * Pm[0][0], (1 - k1) * Pm[0][1]], [Pm[1][0] - k2 * Pm[0][0], Pm[1][1] - k2 * Pm[0][1]]]
    if abs(newP[0][0] - P[0][0]) < 1e-18 and abs(newP[1][1] - P[1][1]) < 1e-22:
        P = newP
        break
    P = newP
print(f"lambda {LAM:.0f}: steady-state gains k_level {k1:.6f}, k_slope {k2:.8f}")

# check against the batch one-sided HP on a long random walk with drift
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'crisis_fit.py')).read()
exec(src[src.index('def hp_trend_last'):src.index('obs = []')])
random.seed(3)
y, v = [1.0], 0.0
for _ in range(399):
    v = 0.9 * v + random.gauss(0, 0.01)
    y.append(y[-1] + 0.002 + v)
tau, beta = y[0], 0.0
errs = []
for t in range(1, len(y)):
    tp, bp = tau + beta, beta
    innov = y[t] - tp
    tau, beta = tp + k1 * innov, bp + k2 * innov
    if t >= 200:
        errs.append(tau - hp_trend_last(y[:t + 1], LAM))
print(f"Kalman vs batch one-sided HP after 50 years: max |diff| {max(abs(e) for e in errs):.5f} (y sd {(__import__('statistics').pstdev(y)):.3f})")
json.dump({'lambda': LAM, 'k_level': k1, 'k_slope': k2}, open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'hp_kalman.json'), 'w'), indent=1)
