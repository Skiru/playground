import { describe, expect, test } from "vitest"
import { render, screen } from "@testing-library/react"
import * as React from "react"
import { MemoryRouter } from "react-router"
import { PlaceCard } from "~/components/places/PlaceCard"
import type { SearchPlacesResponse } from "@family-places/api-client"

const XSS_PAYLOADS = [
  "<script>alert(1)</script>",
  "<img src=x onerror=alert(1)>",
  '"><script>alert(1)</script>',
  "javascript:alert(1)",
]

describe("XSS Payload Escaping and Safe Text Rendering", () => {
  test.each(XSS_PAYLOADS)("renders XSS payload %s as plain text without HTML execution", (payload) => {
    const mockPlace: SearchPlacesResponse["items"][number] = {
      id: "place-1",
      slug: "place-slug",
      name: payload,
      short_description: payload,
      city: "Warszawa",
      categories: [{ slug: "cat", name: "Cat" }],
      min_age_months: 0,
      max_age_months: 120,
      indoor: true,
      outdoor: false,
      free_entry: false,
      verification_status: "verified",
      amenities: [],
      distance_meters: null,
      longitude: 21.0122,
      latitude: 52.2297,
      is_open_now: true,
      complete: true,
      relevance_score: 1.0,
      main_photo: null,
      average_rating: 4.5,
      total_reviews: 1,
    }

    const { container } = render(
      <MemoryRouter>
        <PlaceCard place={mockPlace} />
      </MemoryRouter>
    )

    // Script tags or image onerror handlers must NOT be present as active HTML nodes
    expect(container.querySelectorAll("script")).toHaveLength(0)

    const images = container.querySelectorAll("img")
    for (const img of Array.from(images)) {
      expect(img.getAttribute("onerror")).toBeNull()
    }

    // The payload text should exist in textContent as escaped string
    const heading = screen.getByRole("heading", { level: 2 })
    expect(heading.textContent).toContain(payload)
  })
})
