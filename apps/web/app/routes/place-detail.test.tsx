import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";
import { SessionProvider } from "~/lib/session-context";
import { LoginRequiredActionProvider } from "~/features/auth/LoginRequiredActionContext";

import { PlaceDetailView } from "./place-detail";

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

describe("place detail route", () => {
  it("renders useful place facts in SSR content", () => {
    render(
      <MemoryRouter>
        <SessionProvider initialSession={{ authenticated: false, user: null, csrfToken: null }}>
          <LoginRequiredActionProvider>
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
          </LoginRequiredActionProvider>
        </SessionProvider>
      </MemoryRouter>
    );

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Demo Bawialnia");
    expect(screen.getByText("Przewijak")).toBeInTheDocument();
    expect(screen.getByText(/Rodzinna 1/)).toBeInTheDocument();
  });
});
