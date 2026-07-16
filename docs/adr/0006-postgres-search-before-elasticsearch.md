# ADR 0006: PostgreSQL search before Elasticsearch

Status: Accepted

## Decision

Use PostgreSQL full-text, trigram, normalized-name, and relational filters for
the initial catalogue. Do not operate Elasticsearch or OpenSearch.

## Consequences

One consistency model and backup path suffice. Reconsider only with production
measurements that PostgreSQL indexing and query tuning cannot meet.
