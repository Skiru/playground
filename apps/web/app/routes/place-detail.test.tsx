import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { MemoryRouter } from "react-router";

import { PlaceDetailView } from "./place-detail";

describe("place detail route", () => {
  it("renders useful place facts in SSR content", () => {
    render(
      <MemoryRouter>
        <PlaceDetailView place={{
          id: "00000000-0000-7000-8000-000000000400",
          slug: "demo-bawialnia",
          name: "Demo Bawialnia",
          short_description: "Miejsce dla najmłodszych.",
          description: "Spokojna przestrzeń do rodzinnej zabawy.",
          city_name: "Warszawa",
          city_slug: "warszawa",
          address_line1: "Rodzinna 1",
          address_line2: null,
          postal_code: "00-001",
          country_code: "PL",
          longitude: 21.01,
          latitude: 52.23,
          indoor: true,
          outdoor: false,
          free_entry: true,
          categories: [{ slug: "bawialnie", name: "Bawialnie" }],
          amenities: [{ slug: "przewijak", name: "Przewijak" }],
          age_zones: [],
          weekly_opening: [],
          special_opening: [],
          price_description: null,
          website_url: null,
          phone: null,
          verification_status: "admin_verified",
        }} />
      </MemoryRouter>
    );

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Demo Bawialnia");
    expect(screen.getByText("Przewijak")).toBeInTheDocument();
    expect(screen.getByText(/Rodzinna 1/)).toBeInTheDocument();
  });
});
