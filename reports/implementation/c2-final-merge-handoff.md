# C2 Final Merge Handoff and Runbook

This document provides the necessary handoff runbook for the repository operator to safely merge Pull Request #1.

## 1. Release Metrics and Identifiers

- **Repository**: `Skiru/playground`
- **Branch**: `feat/family-places-platform-v1`
- **PR Number**: `1`
- **Base Branch**: `main`
- **Application Baseline SHA**: `5436570f8cb847256a31dd72ac596a4744cb150a`
- **PR Head SHA (Expected)**: `2338908d630973138a6d9fd27d2ae8d758ba6d50`
- **C2 Final Implementation SHA**: `2338908d630973138a6d9fd27d2ae8d758ba6d50`
- **C2 Final Implementation Tree**: `b8441623177f083640328f805422cf41f54b2de3`
- **Branch-Protection Result**: `OPERATOR_ACTION_REQUIRED`

## 2. Operator Verification Steps (Runbook)

Before merging PR #1, the operator must execute the following verifications:

### Step 2.1: Check Remote Head and Alignment
Verify that the remote repository branch matches our certified SHA:
```fish
git fetch origin
git ls-remote origin refs/heads/feat/family-places-platform-v1
```
Expected output:
`2338908d630973138a6d9fd27d2ae8d758ba6d50  refs/heads/feat/family-places-platform-v1`

### Step 2.2: Verify Branch Protection and Required Status Checks
Due to GitHub API authorization limits in the automated runner environment, the branch protection state has been set to `OPERATOR_ACTION_REQUIRED`. The operator must manually verify that on `main`:
1. **Force Push** is blocked.
2. **Branch Deletion** is blocked.
3. **Pull Request** is required before merge.
4. **Required Status Checks** are configured and passing.
5. **Conversations** are fully resolved.

## 3. Merging the Pull Request

Do not merge an unidentified local branch. Use the protected PR path.
To merge PR #1 safely while matching the certified head commit, run:

```fish
gh pr merge 1 \
  --merge \
  --match-head-commit 2338908d630973138a6d9fd27d2ae8d758ba6d50
```

## 4. Same-Artifact Production Deployment Principles

* **No Rebuilds**: Do not rebuild "certified" PR artifacts in this merge runbook.
* **PR Artifacts Certify the Candidate**: The images built during PR #1 (`family-places-api:sha-2338908d630973138a6d9fd27d2ae8d758ba6d50` and `family-places-web:sha-2338908d630973138a6d9fd27d2ae8d758ba6d50`) are the ones that have been verified.
* **Main Creates a New Release Build**: Upon merge to `main`, a new release build is triggered.
* **Release Verification**: The new `main` build must receive its own unique image digest, Trivy vulnerability scan, SBOM, and provenance attestations before being deployed to production.
