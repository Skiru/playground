# FamilyPlaces Web

React 19.2 and React Router Framework Mode application with SSR enabled. The
workspace is managed only from the repository root with pnpm 11 and Node 24.

Use `pnpm dev`, `pnpm check`, and `pnpm test` from the repository root. Container
builds use `infra/docker/web.Dockerfile` with the monorepo as build context.

Public routes must retain useful server-rendered HTML. MapLibre is loaded only
on the client as a progressive enhancement with an equivalent text list.
