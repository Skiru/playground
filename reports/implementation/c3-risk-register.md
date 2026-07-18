# C3 Risk Register

## Identified Risks & Mitigations

### 1. Google OAuth Token Misuse (Security)
- **Description:** Storing ID tokens or using custom validation could lead to session hijacking or verification flaws.
- **Severity:** High
- **Mitigation:** Rely solely on Symfony Security's stateful session management. Validate Google credentials server-side using the official Google API Client PHP SDK, and throw immediately on expired or invalid signatures.

### 2. Broken Object Level Authorization (BOLA) on Visits (Data Leakage)
- **Description:** A malicious user could edit or delete visits belonging to another family account.
- **Severity:** Critical
- **Mitigation:** All DB queries loading or modifying `Visit` elements must filter by the currently authenticated user's ID (`user_id`). A strict voter/voter-like query condition has been implemented.

### 3. N+1 Queries on Places/Favorites List (Performance)
- **Description:** Displaying search results with favorite toggles can trigger N+1 queries.
- **Severity:** Medium
- **Mitigation:** Implemented a unified `GET /api/v1/me/place-state` batch endpoint that retrieves statuses in bulk using a single Postgres query with array types.
