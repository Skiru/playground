# Checkpoint Ledger

| Checkpoint | Authorized scope | Entry condition | Exit evidence |
| --- | --- | --- | --- |
| C0 | architecture and contracts | initialized repository | gates pass; commit pending |
| C1 | production walking skeleton | C0 PASS | healthy stack, quality gates, report, commit |
| C2 | public geospatial vertical slice | C1 PASS | functional/E2E/security gates, handoff, commit |
| C3-C8 | forbidden in this run | separate authorization | not started |

Initialization commit: `5671a1cc6161c0c1e7a281f0b19654f2cadc66c6`.
The HTTPS credential path was unavailable, but existing SSH authentication
allowed a normal non-force push while `origin` remained the required HTTPS URL.
