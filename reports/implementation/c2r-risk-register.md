# C2R Risk Register

| ID | Residual risk | Severity | Control | Disposition |
| --- | --- | --- | --- | --- |
| C2R-R1 | Single-VPS database loss | high | deployment runbook must add encrypted backups and a tested restore exercise | accepted outside C2R; required before irreplaceable production data |
| C2R-R2 | Pipe-delimited nested admin fields are error-prone | low | typed commands and aggregate validation remain authoritative | P2 usability item for a later admin UX iteration |
| C2R-R3 | MapLibre client chunk is comparatively large | low | lazy loading, SSR content, list fallback, and local deterministic CI style | monitor against production performance budgets |
| C2R-R4 | Search plans can change with catalogue growth | medium | GiST/GIN/trigram indexes, bounded requests, timeout, and 10,000-row EXPLAIN evidence | controlled; monitor production plans and latency |
| C2R-R5 | Opening-hours timezone edge cases can expand | medium | Warsaw DST, weekly/special overnight, and frozen-clock integration coverage | controlled; expand matrix when supported regions grow |
| C2R-R6 | External production map provider outage or terms change | medium | explicit provider configuration, attribution, error states, and textual fallback | controlled; provider selection remains deployment input |
| C2R-R7 | GitHub upload-artifact action emits a Node 20 deprecation warning | low | action is SHA-pinned and forced to Node 24 by GitHub; artifact upload is non-gating | track migration to a SHA-pinned Node 24 release |

Open P0: 0. Open P1: 0.
