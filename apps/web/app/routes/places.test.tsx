import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";
import { SessionProvider } from "~/lib/session-context";
import { LoginRequiredActionProvider } from "~/features/auth/LoginRequiredActionContext";

import { PlacesView } from "./places";

vi.mock("react-router", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router")>();
  return {
    ...actual,
    useRouteLoaderData: (id: string) => {
      if (id === "root") {
        return {
          googleIdentityEnabled: false,
          publicGoogleClientId: "",
          devAuthEnabled: false,
        };
      }
      return null;
    },
  };
});

describe("places route", () => {
  it("renders published results as crawlable links", () => {
    render(
      <MemoryRouter>
        <SessionProvider initialSession={{ authenticated: false, user: null, csrfToken: null }}>
          <LoginRequiredActionProvider>
            <PlacesView places={{
              items: [{ id: "00000000-0000-7000-8000-000000000400", slug: "demo-bawialnia", name: "Demo Bawialnia", short_description: "Miejsce dla najmłodszych.", city: "Warszawa", longitude: 21.01, latitude: 52.23, indoor: true, outdoor: false, free_entry: true, categories: [{ slug: "bawialnie", name: "Bawialnie" }], amenities: [], min_age_months: 0, max_age_months: 72, verification_status: "admin_verified", distance_meters: null, is_open_now: false, complete: true, relevance_score: 40, average_rating: 4.5, total_reviews: 1 }],
              pagination: { page: 1, pageSize: 20, totalItems: 1, totalPages: 1 },
              meta: { sort: "relevance" },
            }} />
          </LoginRequiredActionProvider>
        </SessionProvider>
      </MemoryRouter>
    );

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("1 propozycja dla rodziny");
    expect(screen.getByRole("link", { name: "Demo Bawialnia" })).toHaveAttribute("href", "/miejsca/demo-bawialnia");
    expect(screen.getByText("bezpłatnie")).toBeInTheDocument();
  });
});
