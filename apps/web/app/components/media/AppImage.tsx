import React, { useState, useEffect } from "react";

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
  const [status, setStatus] = useState<'PRIMARY' | 'FALLBACK' | 'FINAL'>(src ? 'PRIMARY' : 'FALLBACK');

  useEffect(() => {
    setStatus(src ? 'PRIMARY' : 'FALLBACK');
  }, [src, srcSet, fallback]);

  const handleError = () => {
    if (status === 'PRIMARY') {
      setStatus('FALLBACK');
    } else if (status === 'FALLBACK') {
      setStatus('FINAL');
    }
  };

  const imageStyle: React.CSSProperties = {
    objectFit: "cover",
    aspectRatio: aspectRatio,
    ...style,
  };

  if (status === 'FINAL') {
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

  return (
    <img
      src={status === 'PRIMARY' ? src : fallback}
      srcSet={status === 'PRIMARY' ? srcSet : undefined}
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
