# Definition of Done

A checkpoint is done only after its bounded implementation, meaningful tests,
functional review, architecture review, security/operations review, fixes, full
gate rerun, JSON report, dedicated commit, clean-worktree check, and non-force
push. A failed or unexecuted gate is reported, never inferred as passing.

P0 means exploitable security, data loss, or unusable core path. P1 means a
material contract, correctness, authorization, migration, or operational defect.
Neither may remain open in a passing checkpoint. P2/P3 may be handed off with
impact and mitigation documented.
