# Place ranking v1

The C2 catalogue ranks only published places and never invents review scores.

`relevance` is a deterministic sum with UUID as the final tie-breaker:

* 20 points for `ADMIN_VERIFIED` data;
* 10 points when verification is newer than 180 days;
* 10 baseline completeness points, because publication enforces the completeness invariant;
* up to 10 proximity points when an origin is supplied, decreasing by one point per kilometre.

Category and age are hard filters when supplied, so every retained row has the same match weight. `distance`, `name`, and `recentlyVerified` are explicit alternative sorts; each ends with the place UUID to keep pagination stable. C2 has no ratings or review-based signal.
