import type { GetCategoriesResponse, GetCitiesResponse } from "@family-places/api-client";

import { SiteHeader } from "../components/SiteHeader";
import { loadCategories, loadCities } from "../lib/api.server";
import { content } from "../content";
import type { Route } from "./+types/home";

export function meta() {
  return [
    { title: content.metadata.homeTitle },
    {
      name: "description",
      content: content.metadata.homeDescription,
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
        <p className="eyebrow">{content.home.eyebrow}</p>
        <h1>{content.home.heading}</h1>
        <p className="lede">
          {content.home.lede}
        </p>
        <form className="search-card" action="/miejsca" method="get">
          <label>
            {content.home.queryLabel}
            <input name="q" placeholder={content.home.queryPlaceholder} />
          </label>
          <label>
            {content.home.cityLabel}
            <select name="city" defaultValue="warszawa">
              {cities.map((city) => <option key={city.id} value={city.slug}>{city.name}</option>)}
            </select>
          </label>
          <label>
            {content.home.ageLabel}
            <select name="ageMonths" defaultValue="">
              <option value="">{content.home.anyOption}</option>
              <option value="12">{content.home.ageOptionUnder2}</option>
              <option value="36">{content.home.ageOption3to5}</option>
              <option value="84">{content.home.ageOption6to9}</option>
              <option value="120">{content.home.ageOption10Plus}</option>
            </select>
          </label>
          <button type="submit">{content.home.showPlacesButton}</button>
        </form>
      </section>
      <section className="category-strip" aria-labelledby="categories-heading">
        <p className="eyebrow">{content.home.popularHeading}</p>
        <h2 id="categories-heading">{content.home.selectCategoryType}</h2>
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
