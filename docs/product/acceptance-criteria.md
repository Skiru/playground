# Acceptance Criteria C0-C2

1. C0 documents scope, architecture, security, risks, and decisions without
   contradicting the authorized checkpoints.
2. C1 runs Symfony API, React SSR, PostGIS, generated OpenAPI client, health
   checks, quality tooling, and container topology.
3. C2 exposes only published places through bounded, validated `/api/v1`
   operations and renders home, results, and place detail as useful SSR HTML.
4. Geospatial queries use geography, `ST_DWithin`, GiST indexes, and verified
   longitude/latitude ordering; map queries require a bounded bbox and hard cap.
5. EasyAdmin routes require `ROLE_ADMIN`; publication uses an application
   service and rejects incomplete places.
6. Generated client drift, module dependency rules, migrations, accessibility,
   security headers, container builds, tests, and smoke scenarios are gated.
7. No known P0/P1 remains, the branch is pushed, and checkpoint evidence names
   exact commits before a final PASS can be declared.
