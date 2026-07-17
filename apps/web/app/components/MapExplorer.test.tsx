import { act, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { MapExplorer } from "./MapExplorer";

const mapState = vi.hoisted(() => ({ handlers: new Map<string, () => void>() }));

vi.mock("maplibre-gl", () => {
  class Map {
    addControl() {}
    getBounds() { return { getWest: () => 20, getSouth: () => 51, getEast: () => 22, getNorth: () => 53 }; }
    getZoom() { return 10; }
    on(event: string, handler: () => void) { mapState.handlers.set(event, handler); }
    remove() {}
  }
  class Marker {
    setLngLat() { return this; }
    addTo() { return this; }
    remove() {}
  }
  return { default: { Map, Marker, NavigationControl: class {}, AttributionControl: class {} } };
});

const initialFeature = feature("initial", "Początkowe miejsce");

describe("MapExplorer", () => {
  beforeEach(() => {
    mapState.handlers.clear();
    vi.stubGlobal("WebGLRenderingContext", class {});
    vi.spyOn(HTMLCanvasElement.prototype, "getContext").mockReturnValue({} as RenderingContext);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("ignores an older bbox response that completes after the current response", async () => {
    const requests: Array<(response: Response) => void> = [];
    vi.stubGlobal("fetch", vi.fn(() => new Promise<Response>((resolve) => requests.push(resolve))));
    render(<MapExplorer initialFeatures={[initialFeature]} styleUrl="/fixtures/map-style.json" attribution="Test" filterQuery="city=warszawa" />);
    await waitFor(() => expect(mapState.handlers.has("moveend")).toBe(true));

    act(() => mapState.handlers.get("moveend")?.());
    await act(async () => { await delay(300); });
    act(() => mapState.handlers.get("moveend")?.());
    await act(async () => { await delay(300); });
    expect(requests).toHaveLength(2);

    await act(async () => requests[1](jsonResponse([feature("current", "Aktualne miejsce")])));
    expect(await screen.findByRole("link", { name: "Aktualne miejsce" })).toBeInTheDocument();
    await act(async () => requests[0](jsonResponse([feature("stale", "Nieaktualne miejsce")])));
    expect(screen.queryByRole("link", { name: "Nieaktualne miejsce" })).not.toBeInTheDocument();
  });

  it("keeps the textual result list available when WebGL is missing", async () => {
    vi.stubGlobal("WebGLRenderingContext", undefined);
    render(<MapExplorer initialFeatures={[initialFeature]} styleUrl="/fixtures/map-style.json" attribution="Test" filterQuery="" />);
    expect(await screen.findByRole("alert")).toHaveTextContent("WebGL");
    expect(screen.getByRole("link", { name: "Początkowe miejsce" })).toBeInTheDocument();
  });
});

function feature(slug: string, name: string) {
  return { type: "Feature" as const, id: slug, geometry: { type: "Point", coordinates: [21, 52] as [number, number] }, properties: { slug, name, indoor: true, outdoor: false, freeEntry: true } };
}

function jsonResponse(features: ReturnType<typeof feature>[]) {
  return new Response(JSON.stringify({ type: "FeatureCollection", features, truncated: false }), { status: 200, headers: { "Content-Type": "application/json" } });
}

function delay(milliseconds: number) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}
