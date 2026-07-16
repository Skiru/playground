# Checkpoint Ledger

| Checkpoint | Authorized scope | Entry condition | Exit evidence |
| --- | --- | --- | --- |
| C0 | architecture and contracts | initialized repository | PASS at `ccfaeb2c0c6c1566d421e31fe1d10362b0d12333`, pushed |
| C1 | production walking skeleton | C0 PASS | PASS at `495ecc7241dc8958b8c7c336d92a297222175ab5`, pushed |
| C1R | hardened walking skeleton | C1 PASS | PASS at `af8672eb3b762927a4092f0abc5fe93e2bdb8859`, pushed |
| C2A | identity, Places schema and fixtures | C1R PASS | PASS at `2c21804d7b5ffbe652601ae1ae6d02fcfdd92c83`, pushed |
| C2B | discovery API and administration | C2A PASS | PASS at `e2ebdd3cf85b83b11029b74ce5d3e2755e45c0a7`, pushed |
| C2C | SSR catalogue and map enhancement | C2B PASS | PASS at `c596932fa741514d58c1b47eca24b139a39aa24b`, pushed |
| C2D | review, handoff and certification | C2C PASS | final review and remote CI evidence |
| C3-C8 | forbidden in this run | separate authorization | not started |

Initialization commit: `5671a1cc6161c0c1e7a281f0b19654f2cadc66c6`.
The HTTPS credential path was unavailable, but existing SSH authentication
allowed a normal non-force push while `origin` remained the required HTTPS URL.
