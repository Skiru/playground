import { type RouteConfig, index, route } from "@react-router/dev/routes";

export default [
  index("routes/home.tsx"),
  route("miejsca", "routes/places.tsx"),
  route("miejsca/:slug", "routes/place-detail.tsx"),
  route("resources/map-places", "routes/map-places-resource.ts"),
] satisfies RouteConfig;
