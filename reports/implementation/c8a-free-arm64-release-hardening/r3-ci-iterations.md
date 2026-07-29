# C8A R3 CI iterations

| Iteration | Run ID | Commit SHA | Job conclusions | Root cause | Fix commit |
| ---: | ---: | --- | --- | --- | --- |
| 0 | `30444883540` | `686d72e49e10af845e056963d6962cb419c39a02` | quality-x64=failure; browser-x64=failure; security-x64=failure; native-arm64-full-stack=failure; provenance=skipped | Chained quality/browser gates hid the exact internal failure; Gitleaks exited 1 without publicly retrievable report content; combined ARM provisioning exited 1 before rehearsal. See `r3-run-30444883540-diagnosis.md`. | Pending R3 repair commit |
