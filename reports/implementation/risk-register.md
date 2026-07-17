# Risk Register

| ID | Risk | Probability/impact | Mitigation | Residual state |
| --- | --- | --- | --- | --- |
| R1 | GitHub credentials unavailable | high/high | use existing SSH authentication for normal non-force pushes and public Actions API for status | resolved for C1R-C2 |
| R2 | Required versions unavailable or incompatible | medium/high | stable versions locked and verified in PHP 8.5/Node 24 containers | resolved in C1R |
| R3 | Geography coordinate inversion | medium/high | `[lon, lat]` GeoJSON, explicit SQL axes, PostGIS round-trip and runtime tests | controlled |
| R4 | Search creates scans or N+1 | medium/high | GiST index, DB projections, synthetic 10000-row EXPLAIN and bounded responses | controlled; monitor production plans |
| R5 | Map provider outage/terms | medium/medium | explicit env, attribution, lazy map and SSR textual fallback | controlled; provider configuration required |
| R6 | Admin publication bypass | low/high | role, stateless/login and stateful/action CSRF, application workflow and runtime test | controlled |
| R7 | SSR injection or metadata misuse | low/high | React escaping, generated transport types, validated URLs and no raw HTML | controlled |
| R8 | Single-VPS state loss | medium/high | backup boundary and restore runbook before production | accepted outside C0-C2 |
| R9 | Scope leakage into identity/community | medium/medium | checkpoint gates and explicit out-of-scope review | controlled |
| R10 | Timebox prevents complete C2 evidence | medium/high | checkpoint reports and dedicated pushed commits preserve independently passing states | resolved |
