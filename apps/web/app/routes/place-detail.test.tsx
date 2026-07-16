import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PlaceDetailView } from "./place-detail";

describe("place detail route", () => {
  it("renders useful place facts in SSR content", () => {
    render(<PlaceDetailView place={{
      id: "00000000-0000-7000-8000-000000000400",
      slug: "demo-bawialnia",
      name: "Demo Bawialnia",
      short_description: "Miejsce dla najmłodszych.",
      description: "Spokojna przestrzeń do rodzinnej zabawy.",
      city_name: "Warszawa",
      address_line1: "Rodzinna 1",
      postal_code: "00-001",
      longitude: 21.01,
      latitude: 52.23,
      indoor: true,
      free_entry: true,
      amenities: [{ slug: "przewijak", name: "Przewijak" }],
    }} />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Demo Bawialnia");
    expect(screen.getByText("Przewijak")).toBeInTheDocument();
    expect(screen.getByText(/Rodzinna 1/)).toBeInTheDocument();
  });
});
