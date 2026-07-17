# Checkpoint Execution Runbook

Run checkpoint gates from the repository root. Record exact commands and their
exit status in the checkpoint JSON. Execute functional, architecture, and
security/operations review before commit. Fix P0/P1 with a regression test and
rerun both the focused test and complete checkpoint gate.

Never use schema-update force, skipped tests, disabled validators, `--no-verify`,
force push, or automatic fixture loading in production. A failed push preserves
the local commit and is reported as an external blocker.

Symfony minor upgrades are reviewed at least quarterly and may be expedited for
security fixes. The window includes release-note and deprecation review,
dependency updates, full CI, and a rollback-compatible deployment assessment.
