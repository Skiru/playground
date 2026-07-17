# Module Boundaries

The backend is one deployable Symfony application split by business capability,
not Symfony bundles. Only Shared, Places, Discovery, and Administration receive
code in C0-C2.

| Module | Ownership |
| --- | --- |
| Shared | cross-cutting primitives, time/correlation infrastructure |
| Places | place aggregate, dictionaries, opening hours, publication rules |
| Discovery | public queries, filters, geospatial projections, map output |
| Administration | EasyAdmin adapters and admin security wiring |
| Identity | documented future users and sessions, implemented from C3 |
| Community | future favourites/visits/reviews/forum, not implemented |
| Moderation | future reports and moderation, not implemented |
| Media | future storage policy and metadata, not implemented |

Allowed dependencies are Infrastructure to Domain/Application, UI to
Application, and Administration to application services. Domain never imports
Infrastructure or UI; Application never imports UI. Controllers do not access
Doctrine's entity manager. Deptrac enforces these rules. Interfaces are reserved
for infrastructure boundaries or multiple genuine implementations, including
map, geocoder, travel-time, media-storage, and external-place provider ports.
