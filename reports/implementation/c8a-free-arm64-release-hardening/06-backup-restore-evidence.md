# Backup and restore evidence

Production backup uses custom `pg_dump`, validates dump structure, age-encrypts with a public recipient, checksums the encrypted artifact, and uploads separate artifact/checksum using R2 credentials only via environment. It locks concurrent jobs and cleans temporary data. Restore refuses non-empty databases and requires offline age identity. R2 integration and restore rehearsal remain external/native gates.
