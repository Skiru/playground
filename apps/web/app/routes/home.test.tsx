import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import Home from "./home";

describe("home route", () => {
  it("renders the FamilyPlaces walking skeleton", () => {
    render(<Home />);
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Miejsca dobrane do wieku");
    expect(screen.queryByText(/logowanie|ulubione|forum/i)).not.toBeInTheDocument();
  });
});
