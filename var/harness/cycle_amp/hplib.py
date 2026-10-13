import math, statistics as st
def solve(A,b):
    n=len(b); M=[row[:]+[b[i]] for i,row in enumerate(A)]
    for i in range(n):
        p=max(range(i,n),key=lambda r:abs(M[r][i])); M[i],M[p]=M[p],M[i]
        for r in range(i+1,n):
            f=M[r][i]/M[i][i]
            if f:
                for c in range(i,n+1): M[r][c]-=f*M[i][c]
    x=[0]*n
    for i in reversed(range(n)): x[i]=(M[i][n]-sum(M[i][c]*x[c] for c in range(i+1,n)))/M[i][i]
    return x
def hp(y,lam):
    n=len(y); A=[[0.0]*n for _ in range(n)]
    for i in range(n): A[i][i]=1.0
    for k in range(n-2):
        d={k:1,k+1:-2,k+2:1}
        for a,va in d.items():
            for b_,vb in d.items(): A[a][b_]+=lam*va*vb
    t=solve(A,y); return [y[i]-t[i] for i in range(n)]
def cyc(levels,lam):
    c=[100*v for v in hp([math.log(x) for x in levels],lam)]
    return st.stdev(c), min(c), sum(1 for i in range(1,len(levels)) if levels[i]<levels[i-1])
