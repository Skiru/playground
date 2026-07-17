import type { GetPlaceBySlugResponse } from "@family-places/api-client";

import { SiteHeader } from "../components/SiteHeader";
import { loadPlace } from "../lib/api.server";
import { content } from "../content";
import type { Route } from "./+types/place-detail";

export async function loader({ params }: Route.LoaderArgs) {
  if (!params.slug) throw new Response("Not found", { status: 404 });
  return { place: await loadPlace(params.slug) };
}

export function meta({ loaderData }: Route.MetaArgs) {
  return [{ title: loaderData ? content.metadata.placeDetailTitleSuffix(loaderData.place.name) : `Miejsce | ${content.common.siteTitle}` }, { name: "description", content: loaderData?.place.short_description ?? content.metadata.placeDetailDescriptionFallback }];
}

export function PlaceDetailView({ place }: { place: GetPlaceBySlugResponse }) {
  return (
    <article className="place-detail">
      <header>
        <p className="eyebrow">{place.city_name}{content.places.placeMetaSeparator}{content.places.verifiedPlace}</p>
        <h1>{place.name}</h1>
        <p className="lede">{place.short_description}</p>
      </header>
      <div className="detail-grid">
        <section>
          <h2>{content.places.aboutPlace}</h2>
          <p>{place.description}</p>
          <h2>{content.places.amenitiesHeading}</h2>
          <ul className="amenity-list">
            {place.amenities?.map((amenity) => <li key={amenity.slug}>{amenity.name}</li>)}
          </ul>
        </section>
        <aside>
          <h2>{content.places.infoHeading}</h2>
          <dl>
            <dt>{content.places.addressLabel}</dt><dd>{place.address_line1}, {place.postal_code} {place.city_name}</dd>
            <dt>{content.places.spaceLabel}</dt><dd>{place.indoor ? content.places.indoor : ""}{place.indoor && place.outdoor ? content.places.spaceAnd : ""}{place.outdoor ? content.places.outdoor : ""}</dd>
            <dt>{content.places.entryLabel}</dt><dd>{place.free_entry ? content.places.freeEntryLabel : content.places.paidEntryLabel}</dd>
          </dl>
        </aside>
      </div>
      <p><a className="back-link" href="/miejsca">{content.common.backToCatalog}</a></p>
    </article>
  );
}

export default function PlaceDetail({ loaderData }: Route.ComponentProps) {
  return <main className="shell"><SiteHeader /><PlaceDetailView place={loaderData.place} /></main>;
}
