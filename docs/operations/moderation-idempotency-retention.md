# Moderation Idempotency Retention

`moderation_idempotency_keys` is scoped to the exact moderation endpoint, moderator, report, and SHA-256 request fingerprint. `idempotency_key` is globally unique and remains present while an operation is pending.

The operational retention period is 90 days after `created_at`. The platform operations owner must run a daily purge for rows older than 90 days with `outcome_status <> 0`; pending rows are never deleted. The purge uses `created_at` and the primary-key index, emits the deleted row count, and records failures in the database maintenance alert stream. Table row count and oldest pending row age are monitored; growth or purge failure alerts page the platform operations owner.

Rollback limitation: canonical initial-post reports cannot be safely converted back to `FORUM_POST` without historical provenance.
