# Deployment Evolution

C0-C2 target a single VPS-compatible Compose topology while retaining separate
stateless API, worker, and web images. PostgreSQL is stateful and backed up
independently. Images can be built immutably for GHCR, but CI performs no VPS
deployment in these checkpoints.

Scale-up order is vertical sizing, query/index tuning, CDN/static caching,
multiple stateless web/API instances, then managed PostgreSQL if operations
demand it. Module extraction is considered only after measured ownership or
scaling pressure; module boundaries do not imply microservices.

Database changes use Doctrine Migrations and expand/contract. Rollback means
deploying a compatible prior application while retaining additive schema; C0-C2
contain no destructive migration.
