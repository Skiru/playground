import React, { useState, useEffect, useRef } from "react";

export interface AppImageProps extends Omit<React.ImgHTMLAttributes<HTMLImageElement>, 'fetchPriority'> {
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
  const isMounted = useRef(true);
  const [prevSrc, setPrevSrc] = useState<string | undefined>(src);
  const [currentSrc, setCurrentSrc] = useState<string | undefined>(src || fallback);
  const [currentSrcSet, setCurrentSrcSet] = useState<string | undefined>(src ? srcSet : undefined);
  const [status, setStatus] = useState<'idle' | 'primary' | 'fallback' | 'final'>(src ? 'primary' : 'fallback');

  useEffect(() => {
    isMounted.current = true;
    return () => {
      isMounted.current = false;
    };
  }, []);

  // Sync state if src changes during render
  if (src !== prevSrc) {
    setPrevSrc(src);
    if (src) {
      setCurrentSrc(src);
      setCurrentSrcSet(srcSet);
      setStatus('primary');
    } else {
      setCurrentSrc(fallback);
      setCurrentSrcSet(undefined);
      setStatus('fallback');
    }
  }

  const handleError = () => {
    if (!isMounted.current) return;

    if (status === 'primary') {
      setStatus('fallback');
      setCurrentSrc(fallback);
      setCurrentSrcSet(undefined);
    } else if (status === 'fallback') {
      setStatus('final');
    }
  };

  const imageStyle: React.CSSProperties = {
    objectFit: "cover",
    aspectRatio: aspectRatio,
    ...style,
  };

  if (status === 'final') {
    return (
      <div
        className={`d-flex align-items-center justify-content-center ${className || ""}`}
        style={{
          width: width || "100%",
          height: height || "100%",
          aspectRatio: aspectRatio,
          minHeight: height ? undefined : "120px",
          backgroundColor: "#edf2f7",
          color: "#a0aec0",
          borderRadius: "inherit",
          ...style,
        }}
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="40" height="40" fill="currentColor">
          <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5.04-6.71l-2.75 3.54-1.96-2.36L6.5 17h11l-3.54-4.71z" />
        </svg>
      </div>
    );
  }

  // Handle fetchPriority attribute safely
  const fetchPriorityProp = fetchPriority ? { fetchpriority: fetchPriority } : {};

  return (
    <img
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
      {...fetchPriorityProp}
      {...props}
    />
  );
};
