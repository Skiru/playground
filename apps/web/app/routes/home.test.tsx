import { render, screen } from "@testing-library/react";
import axe from "axe-core";
import { describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";
import { SessionProvider } from "~/lib/session-context";
import { HomeView } from "./home";

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

const cities = [{ id: "00000000-0000-7000-8000-000000000001", name: "Warszawa", slug: "warszawa", country_code: "PL", default_zoom: 10, default_radius_km: 10, timezone: "Europe/Warsaw" }];
const categories = [{ id: "00000000-0000-7000-8000-000000000002", name: "Bawialnie", slug: "bawialnie", icon_key: "play", display_order: 1 }];

describe("home route", () => {
  it("renders useful search controls without C3 features", () => {
    render(
      <MemoryRouter>
        <SessionProvider initialSession={{ authenticated: false, user: null, csrfToken: null }}>
          <HomeView cities={cities} categories={categories} featuredPlaces={[]} />
        </SessionProvider>
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
        <SessionProvider initialSession={{ authenticated: false, user: null, csrfToken: null }}>
          <HomeView cities={cities} categories={categories} featuredPlaces={[]} />
        </SessionProvider>
      </MemoryRouter>
    );
    const result = await axe.run(container, { rules: { "color-contrast": { enabled: false } } });
    expect(result.violations.map((violation) => violation.id)).toEqual([]);
  });
});
