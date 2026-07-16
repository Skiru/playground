# ADR 0012: Symfony continuous upgrade policy

Status: Accepted

## Decision

Symfony 8.1 is the current project line. Minor Symfony releases are adopted in a
controlled upgrade window with release-note review and complete gates.
Deprecations are time-bounded technical debt and CI performs deprecation checks.
The planned stability destination is Symfony 8.4 LTS.

## Consequences

The project avoids an expensive late upgrade while not accepting unreviewed
framework churn. Prerelease framework versions are excluded.
