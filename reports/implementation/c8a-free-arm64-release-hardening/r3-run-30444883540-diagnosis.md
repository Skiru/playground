# C8A R3 diagnosis: run 30444883540

## Run metadata

- Workflow: `C8A certification`
- Run URL: https://github.com/Skiru/playground/actions/runs/30444883540
- Run ID: `30444883540`
- Attempt: `1`
- Event: `push`
- Branch: `feat/family-places-c8a-free-arm64-production-release-hardening`
- Commit: `686d72e49e10af845e056963d6962cb419c39a02`
- Tree: `d29aae4e4a66280ce4aa2d56fe9d9fa410202674`
- Created: `2026-07-29T10:45:10Z`
- Started: `2026-07-29T10:45:10Z`
- Completed/updated: `2026-07-29T10:51:02Z`
- Status: `completed`
- Conclusion: `failure`

Metadata was retrieved from the public GitHub Actions, jobs, check-run, annotation, and artifact APIs. The local GitHub CLI has no OAuth session. GitHub returned HTTP 403 for the unauthenticated run-log archive and requires sign-in to view job logs. Therefore the original run's final 100 log lines and artifact file contents are not publicly retrievable. This is not treated as a release blocker: the repair workflow splits every mandatory gate into a named step and adds deterministic failure diagnostics for subsequent runs.

## Failed jobs

### quality-x64

- Job ID: `90552757969`
- Job URL: https://github.com/Skiru/playground/actions/runs/30444883540/job/90552757969
- Runner: `ubuntu-24.04`
- Started: `2026-07-29T10:45:36Z`
- Completed: `2026-07-29T10:47:39Z`
- Failing step: `Check and test repository contracts`
- Exact command: `./scripts/check && ./scripts/test && docker compose config --quiet && docker compose -f compose.yaml -f compose.prod.yaml -f compose.prod.arm64.yaml config --quiet && ./scripts/api-check`
- Exit code: `255`
- Relevant log tail: unavailable because GitHub returned HTTP 403 for unauthenticated log download. The check annotation contains only `Process completed with exit code 255.`
- Reached status: the chained step failed; the specific internal gate that failed and all later gate execution states are unknowable from this run and are recorded as `NOT_REACHED_OR_UNDETERMINED`, not individual failures.

### browser-x64

- Job ID: `90552757859`
- Job URL: https://github.com/Skiru/playground/actions/runs/30444883540/job/90552757859
- Runner: `ubuntu-24.04`
- Started: `2026-07-29T10:45:31Z`
- Completed: `2026-07-29T10:51:01Z`
- Failing step: unnamed Compose build/start step
- Exact command: `docker compose -f compose.yaml -f compose.e2e.yaml build api web && docker compose -f compose.yaml -f compose.e2e.yaml up --wait -d database api web && docker compose -f compose.yaml -f compose.e2e.yaml up -d worker`
- Exit code: `1`
- Relevant log tail: unavailable because GitHub returned HTTP 403 for unauthenticated log download. The check annotation contains only `Process completed with exit code 1.`
- Reached status: the combined build/start step failed after approximately 293 seconds. Desktop and mobile Playwright were skipped and are `NOT_REACHED`; accessibility had no independent gate.

### security-x64

- Job ID: `90552757997`
- Job URL: https://github.com/Skiru/playground/actions/runs/30444883540/job/90552757997
- Runner: `ubuntu-24.04`
- Started: `2026-07-29T10:45:31Z`
- Completed: `2026-07-29T10:46:17Z`
- Failing step: unnamed Gitleaks step
- Exact command: `docker run --rm -v "$PWD:/repo" -w /repo zricethezav/gitleaks@sha256:cdbb7c955abce02001a9f6c9f602fb195b7fadc1e812065883f695d1eeaba854 detect --source=/repo --config=.gitleaks.toml --redact --report-format=json --report-path=evidence/security/gitleaks.json`
- Exit code: `1`
- Relevant log tail: unavailable because GitHub returned HTTP 403 for unauthenticated log download. The check annotation contains only `Process completed with exit code 1.`
- Reached status: Gitleaks executed and failed. Trivy, image builds/scans, Hadolint, ShellCheck, dependency audits, Compose policy, and SBOM were skipped and are `NOT_REACHED`.

### native-arm64-full-stack

- Job ID: `90552757920`
- Job URL: https://github.com/Skiru/playground/actions/runs/30444883540/job/90552757920
- Runner: `ubuntu-24.04-arm`
- Started: `2026-07-29T10:45:32Z`
- Completed: `2026-07-29T10:46:05Z`
- Failing step: unnamed ARM tool-provisioning step
- Exact command: `sudo apt-get update && sudo apt-get install -y age curl jq unzip && curl --fail --silent --show-error --location https://awscli.amazonaws.com/awscli-exe-linux-aarch64.zip -o /tmp/awscliv2.zip && unzip -q /tmp/awscliv2.zip -d /tmp && sudo /tmp/aws/install`
- Exit code: `1`
- Relevant log tail: unavailable because GitHub returned HTTP 403 for unauthenticated log download. The check annotation contains only `Process completed with exit code 1.`
- Reached status: tool provisioning failed after approximately 27 seconds. The native rehearsal was skipped. No native API, web, or PostGIS CI build is claimed.

## Skipped job

- `provenance`, job ID `90553923328`, was skipped because its required jobs failed.

## Artifact inventory

| Artifact | ID | Size | Digest | Availability |
| --- | ---: | ---: | --- | --- |
| `quality-x64-evidence` | `8721044724` | 2,093,254 bytes | `sha256:53270e04942dd9f701a297f6341b957f82e295e0c4968d9129e68970f1875240` | Metadata public; archive download requires OAuth |
| `security-x64-evidence` | `8721009862` | 624 bytes | `sha256:b33b771f4c213177142fd4e4acf9a011e8b46bb2d868b553b5b2bdde50a39490` | Metadata public; archive download requires OAuth |

No browser or native ARM artifact was created according to the run artifact API.

## Diagnostic conclusion

The original workflow obscures quality and browser root causes by chaining independent gates. Security proves only that Gitleaks returned 1, not why. Native ARM proves only that the combined provisioning command returned 1 before rehearsal. The next iteration must provide named gates, fresh Compose project cleanup, database failure capture, an always-uploaded Gitleaks JSON/stderr report, and separated pinned ARM tool installation.
