import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PlacesView } from "./places";

describe("places route", () => {
  it("renders published results as crawlable links", () => {
    render(<PlacesView places={{
      items: [{ id: "00000000-0000-7000-8000-000000000400", slug: "demo-bawialnia", name: "Demo Bawialnia", short_description: "Miejsce dla najmłodszych.", city: "Warszawa", longitude: 21.01, latitude: 52.23, indoor: true, free_entry: true, categories: [{ slug: "bawialnie", name: "Bawialnie" }] }],
      pagination: { page: 1, pageSize: 20, totalItems: 1, totalPages: 1 },
      meta: { sort: "relevance" },
    }} />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("1 propozycji");
    expect(screen.getByRole("link", { name: "Demo Bawialnia" })).toHaveAttribute("href", "/miejsca/demo-bawialnia");
    expect(screen.getByText("bezpłatnie")).toBeInTheDocument();
  });
});
