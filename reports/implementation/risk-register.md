# Risk Register

| ID | Risk | Probability/impact | Mitigation | Residual state |
| --- | --- | --- | --- | --- |
| R1 | GitHub credentials unavailable | high/high | preserve commits; retry normal push when credentials exist | open, blocks remote PASS |
| R2 | Required versions unavailable or incompatible | medium/high | resolve stable releases and lock them in C1; fail rather than downgrade | open for C1 |
| R3 | Geography coordinate inversion | medium/high | value objects, `[lon, lat]` API convention, regression tests | planned C2 |
| R4 | Search creates scans or N+1 | medium/high | GiST/FTS indexes, projections, query count and explain review | planned C2 |
| R5 | Map provider outage/terms | medium/medium | explicit env, attribution, lazy map, textual fallback | planned C2 |
| R6 | Admin publication bypass | low/high | role, CSRF, application workflow, functional tests | planned C2 |
| R7 | SSR injection or metadata misuse | low/high | React escaping, plain text, URL allowlists, no raw HTML | planned C2 |
| R8 | Single-VPS state loss | medium/high | backup boundary and restore runbook before production | accepted outside C0-C2 |
| R9 | Scope leakage into identity/community | medium/medium | checkpoint gates and explicit out-of-scope review | controlled |
| R10 | Timebox prevents complete C2 evidence | medium/high | never claim PASS; leave last passing commit and precise PARTIAL report | controlled |
