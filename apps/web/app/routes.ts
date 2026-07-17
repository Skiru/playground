import { type RouteConfig, index, route } from "@react-router/dev/routes";

export default [
  index("routes/home.tsx"),
  route("miejsca", "routes/places.tsx"),
  route("miejsca/:slug", "routes/place-detail.tsx"),
  route("resources/map-places", "routes/map-places-resource.ts"),
  route("resources/session", "routes/resources/session.ts"),
  route("resources/auth/google", "routes/resources/auth-google.ts"),
  route("resources/auth/dev-login", "routes/resources/auth-dev-login.ts"),
  route("resources/auth/logout", "routes/resources/auth-logout.ts"),
  route("resources/favorites", "routes/resources/favorites.ts"),
  route("resources/visits", "routes/resources/visits.ts"),
  route("resources/visits/:visitId", "routes/resources/visits-by-id.ts"),
  route("resources/place-state", "routes/resources/place-state.ts"),
  route("konto", "routes/account/index.tsx"),
  route("konto/ulubione", "routes/account/favorites.tsx"),
  route("konto/odwiedzone", "routes/account/visits.tsx"),
] satisfies RouteConfig;
