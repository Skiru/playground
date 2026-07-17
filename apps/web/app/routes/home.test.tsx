import { render, screen } from "@testing-library/react";
import axe from "axe-core";
import { describe, expect, it } from "vitest";
import { MemoryRouter } from "react-router";
import { HomeView } from "./home";

const cities = [{ id: "00000000-0000-7000-8000-000000000001", name: "Warszawa", slug: "warszawa", country_code: "PL", default_zoom: 10, default_radius_km: 10, timezone: "Europe/Warsaw" }];
const categories = [{ id: "00000000-0000-7000-8000-000000000002", name: "Bawialnie", slug: "bawialnie", icon_key: "play", display_order: 1 }];

describe("home route", () => {
  it("renders useful search controls without C3 features", () => {
    render(
      <MemoryRouter>
        <HomeView cities={cities} categories={categories} featuredPlaces={[]} />
      </MemoryRouter>
    );
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Miejsca dobrane do wieku");
    expect(screen.getByRole("button", { name: "Pokaż miejsca" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Warszawa" })).toBeInTheDocument();
    expect(screen.queryByText(/logowanie|ulubione|forum/i)).not.toBeInTheDocument();
  });

  it("has no automatic accessibility violations", async () => {
    const { container } = render(
      <MemoryRouter>
        <HomeView cities={cities} categories={categories} featuredPlaces={[]} />
      </MemoryRouter>
    );
    const result = await axe.run(container, { rules: { "color-contrast": { enabled: false } } });
    expect(result.violations.map((violation) => violation.id)).toEqual([]);
  });
});
