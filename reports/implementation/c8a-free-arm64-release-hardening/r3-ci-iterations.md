# C8A R3 CI iterations

| Iteration | Run ID | Commit SHA | Job conclusions | Root cause | Fix commit |
| ---: | ---: | --- | --- | --- | --- |
| 0 | `30444883540` | `686d72e49e10af845e056963d6962cb419c39a02` | quality-x64=failure; browser-x64=failure; security-x64=failure; native-arm64-full-stack=failure; provenance=skipped | Chained quality/browser gates hid the exact internal failure; Gitleaks exited 1 without publicly retrievable report content; combined ARM provisioning exited 1 before rehearsal. See `r3-run-30444883540-diagnosis.md`. | `b21e93ce095c7e274b843bf0905e4d7b87a8a7e1` |
| 1 | `30450775549` | `b21e93ce095c7e274b843bf0905e4d7b87a8a7e1` | quality-x64=failure; browser-x64=failure; security-x64=failure; native-arm64-full-stack=failure; provenance=skipped | Quality `backend-format` exited 127 because the development bind mount had no installed `vendor/`; browser services and fresh database passed but host readiness curl exited 7; the Gitleaks regression precheck failed before scanner startup; ARM tool installation and verification passed, then the rehearsal failed without public raw logs, requiring an always-run stage summary. | Pending iteration 1 fix commit |
