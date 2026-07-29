# Security review

Production overlay drops capabilities, enables no-new-privileges, uses read-only application filesystems and tmpfs, non-root API/web, local log rotation, digest deployment identity, and no application host ports. Secrets are runtime environment only. Residual P1: a full production security scan and policy check were not run locally.
