import type { GetPlaceBySlugResponse } from "@family-places/api-client";

import { SiteHeader } from "../components/SiteHeader";
import { loadPlace } from "../lib/api.server";
import type { Route } from "./+types/place-detail";

export async function loader({ params }: Route.LoaderArgs) {
  if (!params.slug) throw new Response("Not found", { status: 404 });
  return { place: await loadPlace(params.slug) };
}

export function meta({ loaderData }: Route.MetaArgs) {
  return [{ title: loaderData ? `${loaderData.place.name} | FamilyPlaces` : "Miejsce | FamilyPlaces" }, { name: "description", content: loaderData?.place.short_description ?? "Szczegóły rodzinnego miejsca." }];
}

export function PlaceDetailView({ place }: { place: GetPlaceBySlugResponse }) {
  return (
    <article className="place-detail">
      <header>
        <p className="eyebrow">{place.city_name} · miejsce zweryfikowane</p>
        <h1>{place.name}</h1>
        <p className="lede">{place.short_description}</p>
      </header>
      <div className="detail-grid">
        <section>
          <h2>O miejscu</h2>
          <p>{place.description}</p>
          <h2>Udogodnienia</h2>
          <ul className="amenity-list">
            {place.amenities?.map((amenity) => <li key={amenity.slug}>{amenity.name}</li>)}
          </ul>
        </section>
        <aside>
          <h2>Informacje</h2>
          <dl>
            <dt>Adres</dt><dd>{place.address_line1}, {place.postal_code} {place.city_name}</dd>
            <dt>Przestrzeń</dt><dd>{place.indoor ? "wewnątrz" : ""}{place.indoor && place.outdoor ? " i " : ""}{place.outdoor ? "na zewnątrz" : ""}</dd>
            <dt>Wstęp</dt><dd>{place.free_entry ? "bezpłatny" : "sprawdź cennik na miejscu"}</dd>
          </dl>
        </aside>
      </div>
      <p><a className="back-link" href="/miejsca">← Wróć do katalogu</a></p>
    </article>
  );
}

export default function PlaceDetail({ loaderData }: Route.ComponentProps) {
  return <main className="shell"><SiteHeader /><PlaceDetailView place={loaderData.place} /></main>;
}
