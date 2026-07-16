import type { GetMapPlacesResponse } from "@family-places/api-client";
import { useEffect, useRef, useState } from "react";
import "maplibre-gl/dist/maplibre-gl.css";

type Feature = GetMapPlacesResponse["features"][number];

export function MapExplorer({
  initialFeatures,
  styleUrl,
  attribution,
  filterQuery,
}: {
  initialFeatures: Feature[];
  styleUrl: string;
  attribution: string;
  filterQuery: string;
}) {
  const container = useRef<HTMLDivElement>(null);
  const [features, setFeatures] = useState(initialFeatures);
  const [initialLongitude, initialLatitude] = initialFeatures[0]?.geometry.coordinates ?? [21.0122, 52.2297];

  useEffect(() => {
    if (!container.current || !styleUrl) return;
    let disposed = false;
    let destroy = () => {};

    void import("maplibre-gl").then(({ default: maplibregl }) => {
      if (disposed || !container.current) return;
      const map = new maplibregl.Map({
        container: container.current,
        style: styleUrl,
        center: [initialLongitude, initialLatitude],
        zoom: 10,
        attributionControl: false,
      });
      map.addControl(new maplibregl.NavigationControl(), "top-right");
      if (attribution) map.addControl(new maplibregl.AttributionControl({ customAttribution: attribution }));

      const markers: Array<{ remove(): void }> = [];
      const renderMarkers = (next: Feature[]) => {
        markers.splice(0).forEach((marker) => marker.remove());
        next.forEach((feature) => {
          const marker = new maplibregl.Marker({ color: "#d45132" })
            .setLngLat(feature.geometry.coordinates ?? [0, 0])
            .addTo(map);
          markers.push(marker);
        });
      };
      renderMarkers(initialFeatures);

      map.on("moveend", async () => {
        const bounds = map.getBounds();
        const params = new URLSearchParams(filterQuery);
        params.set("west", String(bounds.getWest()));
        params.set("south", String(bounds.getSouth()));
        params.set("east", String(bounds.getEast()));
        params.set("north", String(bounds.getNorth()));
        params.set("zoom", String(map.getZoom()));
        const response = await fetch(`/resources/map-places?${params}`);
        if (!response.ok) return;
        const data = (await response.json()) as GetMapPlacesResponse;
        setFeatures(data.features);
        renderMarkers(data.features);
      });
      destroy = () => {
        markers.forEach((marker) => marker.remove());
        map.remove();
      };
    });

    return () => {
      disposed = true;
      destroy();
    };
  }, [attribution, filterQuery, initialFeatures, initialLatitude, initialLongitude, styleUrl]);

  return (
    <section className="map-panel" aria-labelledby="map-heading">
      <div className="section-heading">
        <p className="eyebrow">Widok przestrzenny</p>
        <h2 id="map-heading">Mapa wyników</h2>
      </div>
      {styleUrl ? <div className="map-canvas" ref={container} role="img" aria-label="Interaktywna mapa znalezionych miejsc" /> : null}
      <details className="map-fallback" open={!styleUrl}>
        <summary>Miejsca widoczne na mapie ({features.length})</summary>
        {features.length ? (
          <ul>
            {features.map((feature) => (
              <li key={String(feature.properties.slug)}>
                <a href={`/miejsca/${String(feature.properties.slug)}`}>{String(feature.properties.name)}</a>
              </li>
            ))}
          </ul>
        ) : <p>Brak miejsc w tym obszarze.</p>}
      </details>
    </section>
  );
}
