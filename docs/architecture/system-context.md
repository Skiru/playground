# System Context

FamilyPlaces serves visitors and administrators. Visitors use an SSR web
application that calls a versioned JSON API. Administrators use server-rendered
EasyAdmin behind session authentication and role checks. The API persists local
catalogue data in PostgreSQL/PostGIS and may use optional Redis for disposable
cache, locks, or rate-limit state.

MapLibre runs only in the browser and receives an operator-configured style URL
and attribution. A map outage cannot block the result list. Future geocoder,
routing, media storage, and external-place providers sit behind outbound ports;
they are unavailable rather than faked when credentials are absent.

Trust boundaries are browser-to-edge, edge-to-applications, and applications-to-
internal data services. PostgreSQL and Redis are never attached to the edge
network in production.
