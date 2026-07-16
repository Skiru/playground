import type { GetCategoriesResponse, GetCitiesResponse } from "@family-places/api-client";

import { SiteHeader } from "../components/SiteHeader";
import { loadCategories, loadCities } from "../lib/api.server";
import type { Route } from "./+types/home";

export function meta() {
  return [
    { title: "FamilyPlaces | Miejsca przyjazne rodzinom" },
    {
      name: "description",
      content: "Znajduj miejsca dopasowane do wieku dziecka i potrzeb rodziny.",
    },
  ];
}

export async function loader() {
  const [cities, categories] = await Promise.all([loadCities(), loadCategories()]);
  return { cities: cities.items, categories: categories.items };
}

export function HomeView({ cities, categories }: { cities: GetCitiesResponse["items"]; categories: GetCategoriesResponse["items"] }) {
  return (
    <main className="shell">
      <SiteHeader />
      <section className="hero">
        <p className="eyebrow">Rodzinne odkrywanie miasta</p>
        <h1>Miejsca dobrane do wieku, nie do przypadku.</h1>
        <p className="lede">
          Sprawdź zweryfikowane miejsca i dopasuj je do wieku dziecka, miasta
          oraz potrzeb całej rodziny.
        </p>
        <form className="search-card" action="/miejsca" method="get">
          <label>
            Czego szukasz?
            <input name="q" placeholder="Bawialnia, park, kawiarnia" />
          </label>
          <label>
            Miasto
            <select name="city" defaultValue="warszawa">
              {cities.map((city) => <option key={city.id} value={city.slug}>{city.name}</option>)}
            </select>
          </label>
          <label>
            Wiek dziecka
            <select name="ageMonths" defaultValue="">
              <option value="">Dowolny</option>
              <option value="12">do 2 lat</option>
              <option value="36">3-5 lat</option>
              <option value="84">6-9 lat</option>
              <option value="120">10+ lat</option>
            </select>
          </label>
          <button type="submit">Pokaż miejsca</button>
        </form>
      </section>
      <section className="category-strip" aria-labelledby="categories-heading">
        <p className="eyebrow">Popularne kierunki</p>
        <h2 id="categories-heading">Wybierz rodzaj miejsca</h2>
        <div className="category-grid">
          {categories.map((category, index) => (
            <a key={category.id} href={`/miejsca?city=warszawa&category=${category.slug}`}>
              <span>0{index + 1}</span>{category.name}
            </a>
          ))}
        </div>
      </section>
    </main>
  );
}

export default function Home({ loaderData }: Route.ComponentProps) {
  return <HomeView cities={loaderData.cities} categories={loaderData.categories} />;
}
