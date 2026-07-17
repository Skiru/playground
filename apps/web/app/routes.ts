import { type RouteConfig, index, route } from "@react-router/dev/routes";

export default [
  index("routes/home.tsx"),
  route("miejsca", "routes/places.tsx"),
  route("miejsca/:slug", "routes/place-detail.tsx"),
  route("resources/map-places", "routes/map-places-resource.ts"),
  route("resources/session", "routes/resources/session.ts"),
  route("resources/auth/google", "routes/resources/auth-google.ts"),
  route("resources/auth/logout", "routes/resources/auth-logout.ts"),
] satisfies RouteConfig;
