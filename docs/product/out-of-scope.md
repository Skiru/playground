# Out of Scope C0-C2

The following are intentionally absent, including inactive UI and mock APIs:

- Google sign-in, public accounts, favourites, visits, reviews, comments,
  forums, child accounts, private messages, and user uploads;
- automated Google Places import, travel-time estimates, paid providers, and
  arbitrary backend URL fetching;
- production VPS rollout, Kubernetes, object storage, search clusters,
  microservices, GraphQL, event sourcing, and a shared design-system package.

Ports for geocoding, routing, media, maps, and external places are documented
extension seams. Only map configuration and the local catalogue are implemented.
