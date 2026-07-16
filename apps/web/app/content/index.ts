import type { ContentCatalog } from "./schema";
import { common } from "./pl/common";
import { navigation } from "./pl/navigation";
import { home } from "./pl/home";
import { places } from "./pl/places";
import { map } from "./pl/map";
import { errors } from "./pl/errors";
import { metadata } from "./pl/metadata";

export const plContent: ContentCatalog = {
  common,
  navigation,
  home,
  places,
  map,
  errors,
  metadata,
};

export const content = plContent;
export type { ContentCatalog } from "./schema";
