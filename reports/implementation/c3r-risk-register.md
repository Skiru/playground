# C3R Risk Register — Product & Security Closure

This register lists identified technical, product, and security risks during the C3R remediation phase, along with their impact, likelihood, mitigation strategy, and current status.

| ID | Description | Impact | Likelihood | Mitigation Strategy | Status |
|---|---|---|---|---|---|
| R01 | Concurrent user creations leading to duplicate profiles | High | Medium | Implemented strict UNIQUE database constraints and atomic transaction handling with rollback in `AuthenticateWithGoogle`. | **Mitigated** |
| R02 | Insecure dev login endpoints remaining active in production | Critical | Low | Hardened `DevAuthController` with a production guard throwing `NotFoundHttpException` when `APP_ENV=prod`. | **Mitigated** |
| R03 | N+1 database query issues during batch state checks | Medium | High | Added batch `findFavoritesByPlaces` querying in `PersonalizationController::getPlaceState` to minimize query count. | **Mitigated** |
| R04 | Exposure of raw backend database exception messages | High | Medium | Redacted all raw exception details and mapped them to safe Problem Details payloads. | **Mitigated** |
| R05 | Multi-byte UTF-8 initials processing errors | Low | Low | Handled initials calculations using multi-byte string functions in `calculateInitials`. | **Mitigated** |
