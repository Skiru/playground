import type { GetMapPlacesResponse } from "@family-places/api-client";
import { useEffect, useRef, useState } from "react";
import "maplibre-gl/dist/maplibre-gl.css";
import { content } from "../content";

type Feature = GetMapPlacesResponse["features"][number];
type RequestState = "idle" | "loading" | "error";

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
  const retryRequest = useRef<() => void>(() => {});
  const [remoteFeatures, setRemoteFeatures] = useState<Feature[] | null>(null);
  const [requestState, setRequestState] = useState<RequestState>("idle");
  const [errorMessage, setErrorMessage] = useState<string | null>(styleUrl ? null : content.map.missingConfig);
  const features = remoteFeatures ?? initialFeatures;
  const [initialLongitude, initialLatitude] = initialFeatures[0]?.geometry.coordinates ?? [21.0122, 52.2297];

  useEffect(() => {
    if (!container.current || !styleUrl) return;
    if (!supportsWebGl()) {
      queueMicrotask(() => {
        setRequestState("error");
        setErrorMessage(content.map.noWebGl);
      });
      return;
    }

    let disposed = false;
    let debounceTimer: ReturnType<typeof setTimeout> | undefined;
    let activeController: AbortController | undefined;
    let requestId = 0;
    let destroy = () => {};

    void import("maplibre-gl")
      .then(({ default: maplibregl }) => {
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
            markers.push(new maplibregl.Marker({ color: "#d45132" }).setLngLat(feature.geometry.coordinates ?? [0, 0]).addTo(map));
          });
        };
        renderMarkers(initialFeatures);

        const loadBounds = async () => {
          const currentRequest = ++requestId;
          activeController?.abort();
          activeController = new AbortController();
          const bounds = map.getBounds();
          const params = new URLSearchParams(filterQuery);
          params.set("west", String(bounds.getWest()));
          params.set("south", String(bounds.getSouth()));
          params.set("east", String(bounds.getEast()));
          params.set("north", String(bounds.getNorth()));
          params.set("zoom", String(map.getZoom()));
          setRequestState("loading");
          setErrorMessage(null);
          try {
            const response = await fetch(`/resources/map-places?${params}`, { signal: activeController.signal });
            if (!response.ok) throw new Error(content.map.apiError(response.status));
            const data = (await response.json()) as GetMapPlacesResponse;
            if (disposed || currentRequest !== requestId) return;
            setRemoteFeatures(data.features);
            renderMarkers(data.features);
            setRequestState("idle");
          } catch (error) {
            if (disposed || currentRequest !== requestId || (error instanceof DOMException && error.name === "AbortError")) return;
            setRequestState("error");
            setErrorMessage(error instanceof Error ? error.message : content.map.refreshError);
          }
        };
        const scheduleLoad = () => {
          if (debounceTimer) clearTimeout(debounceTimer);
          debounceTimer = setTimeout(() => void loadBounds(), 250);
        };
        retryRequest.current = () => void loadBounds();
        map.on("moveend", scheduleLoad);
        map.on("error", () => {
          if (disposed) return;
          setRequestState("error");
          setErrorMessage(content.map.loadStyleError);
        });
        destroy = () => {
          if (debounceTimer) clearTimeout(debounceTimer);
          activeController?.abort();
          requestId += 1;
          markers.forEach((marker) => marker.remove());
          map.remove();
        };
      })
      .catch(() => {
        if (disposed) return;
        setRequestState("error");
        setErrorMessage(content.map.loadModuleError);
      });

    return () => {
      disposed = true;
      retryRequest.current = () => {};
      destroy();
    };
  }, [attribution, filterQuery, initialFeatures, initialLatitude, initialLongitude, styleUrl]);

  return (
    <section className="map-panel" aria-labelledby="map-heading" aria-busy={requestState === "loading"}>
      <div className="section-heading">
        <p className="eyebrow">{content.map.spatialViewEyebrow}</p>
        <h2 id="map-heading">{content.map.mapResultsHeading}</h2>
      </div>
      {styleUrl ? <div className="map-canvas" ref={container} role="region" aria-label={content.map.interactiveMapLabel} /> : null}
      {requestState === "loading" ? <p role="status">{content.map.refreshing}</p> : null}
      {errorMessage ? <div role="alert"><p>{errorMessage}</p>{styleUrl ? <button type="button" onClick={() => retryRequest.current()}>{content.map.retryButton}</button> : null}</div> : null}
      <details className="map-fallback" open={!styleUrl || requestState === "error" || features.length === 0}>
        <summary>{content.map.placesOnMap(features.length)}</summary>
        {features.length ? (
          <ul>
            {features.map((feature) => (
              <li key={String(feature.properties.slug)}>
                <a href={`/miejsca/${String(feature.properties.slug)}`}>{String(feature.properties.name)}</a>
              </li>
            ))}
          </ul>
        ) : <p>{content.map.noPlacesInArea}</p>}
      </details>
    </section>
  );
}

function supportsWebGl() {
  try {
    const canvas = document.createElement("canvas");
    return Boolean(window.WebGLRenderingContext && (canvas.getContext("webgl") || canvas.getContext("experimental-webgl")));
  } catch {
    return false;
  }
}
