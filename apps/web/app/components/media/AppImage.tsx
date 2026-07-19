import React, { useState, useEffect, useLayoutEffect, useRef } from "react";

export interface AppImageProps extends React.ImgHTMLAttributes<HTMLImageElement> {
  src?: string;
  srcSet?: string;
  fallback: string;
  aspectRatio?: string;
  fetchPriority?: "high" | "low" | "auto";
}

export const AppImage: React.FC<AppImageProps> = ({
  src,
  srcSet,
  sizes,
  fallback,
  width,
  height,
  aspectRatio,
  loading = "lazy",
  decoding = "async",
  fetchPriority,
  alt = "",
  className,
  style,
  ...props
}) => {
  // Use a simple error count to manage the deterministic state transitions:
  // - If src is provided:
  //   - errorCount 0: PRIMARY (tries src)
  //   - errorCount 1: FALLBACK (tries fallback)
  //   - errorCount >= 2: FINAL (renders custom styled div fallback)
  // - If src is empty:
  //   - errorCount 0: FALLBACK (tries fallback)
  //   - errorCount >= 1: FINAL (renders custom styled div fallback)
  const [errorCount, setErrorCount] = useState(0);
  const imgRef = useRef<HTMLImageElement>(null);
  const lastErrorSrcRef = useRef<string | undefined>(undefined);

  // Reset error count when any source or fallback prop changes
  useEffect(() => {
    setErrorCount(0);
    lastErrorSrcRef.current = undefined;
  }, [src, srcSet, fallback]);

  const handleError = () => {
    if (imgRef.current) {
      lastErrorSrcRef.current = imgRef.current.src;
    }
    setErrorCount((prev) => prev + 1);
  };

  const isFinalState = src ? (errorCount >= 2) : (errorCount >= 1);
  const isFallbackState = src ? (errorCount === 1) : (errorCount === 0);

  const currentExpectedSrc = isFallbackState ? fallback : src;

  // React 19 Hydration workaround:
  // We use useLayoutEffect to perform a synchronous complete check on mount/hydration
  // to catch any broken images that failed before React finished hydrating.
  useLayoutEffect(() => {
    const img = imgRef.current;
    if (!img) return;

    if (img.complete && img.naturalWidth === 0) {
      const isJsdom = typeof navigator !== "undefined" && navigator.userAgent.includes("jsdom");
      
      if (errorCount === 0 && img.src !== lastErrorSrcRef.current) {
        handleError();
      } else if (errorCount === 1 && !isJsdom && img.src !== lastErrorSrcRef.current) {
        handleError();
      }
    }
  }, [errorCount, src, fallback, currentExpectedSrc]);

  const imageStyle: React.CSSProperties = {
    objectFit: "cover",
    aspectRatio: aspectRatio,
    ...style,
  };

  if (isFinalState) {
    return (
      <div
        className={`flex items-center justify-center bg-muted text-muted-foreground ${className || ""}`}
        style={{
          width: width || "100%",
          height: height || "100%",
          aspectRatio: aspectRatio,
          minHeight: height ? undefined : "120px",
          borderRadius: "inherit",
          ...style,
        }}
        role="img"
        aria-label={alt || "Image fallback placeholder"}
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40" fill="currentColor">
          <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5.04-6.71l-2.75 3.54-1.96-2.36L6.5 17h11l-3.54-4.71z" />
        </svg>
      </div>
    );
  }

  const currentSrc = isFallbackState ? fallback : src;
  const currentSrcSet = isFallbackState ? undefined : srcSet;

  return (
    <img
      ref={imgRef}
      src={currentSrc}
      srcSet={currentSrcSet}
      sizes={sizes}
      width={width}
      height={height}
      loading={loading}
      decoding={decoding}
      alt={alt}
      style={imageStyle}
      className={className}
      onError={handleError}
      fetchPriority={fetchPriority}
      {...props}
    />
  );
};
