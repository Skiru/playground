# C3 Entry Contract

This document outlines the strict entry criteria and state verification required before C3 implementation may commence.

## Entry Criteria and Prerequisites

C3 implementation is authorized to begin ONLY when the following conditions are simultaneously met:

1. **Pull Request #1 Merged**:
   - Pull Request #1 has been successfully merged into `main` branch.
   - Squash merges and rebase history rewrites are strictly forbidden.
2. **Exact Merge Commit SHA**:
   - A verified, exact merge commit SHA exists on the remote `main` branch.
3. **Pristine Remote Status**:
   - All remote GitHub Action checks on the merged `main` branch are `PASS` (green).
4. **Attestable Images Available**:
   - Pinned production base images are built and published, with verified CycloneDX SBOMs and 0 fixed HIGH/CRITICAL vulnerabilities.
5. **Clean New Branch**:
   - A clean new branch is created from the `main` merge SHA for C3 features.
6. **Architect Prompt Received**:
   - A separate explicit authorization and design prompt from the architect has been received and verified.

## Forbidden Actions in C3 Pre-Merge

- Do NOT implement any unauthorized features (e.g. Google Identity, public registration, reviews, favorites) until the architect's specific C3 design is provided.
- Do NOT rewrite or weaken any of the certified C2 domain, application, or transaction invariants.
- Do NOT bypass any automatic static or security linting gates.
