"use client";

import { forwardRef, useState } from "react";
import { optimizedSrc, optimizedSrcSet } from "@/lib/image";

type SmartImageProps = {
  src: string | null | undefined;
  alt: string;
  /** Rendered widths this image is used at, in CSS pixels. */
  widths: number[];
  sizes: string;
  quality?: number;
  eager?: boolean;
  className?: string;
} & Omit<React.ImgHTMLAttributes<HTMLImageElement>, "src" | "srcSet" | "sizes" | "ref">;

/**
 * An `<img>` that asks the optimiser for a size the layout can actually use.
 *
 * Deliberately a plain `<img>` rather than `next/image`: the storefront's
 * layout is already built around object-fit and aspect-ratio boxes, and
 * swapping in a component with its own wrapper and sizing rules would be a
 * redesign of every grid it appears in. This changes the bytes, not the page.
 *
 * If the optimiser cannot handle a source — a corrupt upload, or one large
 * enough to exceed its limits — the original URL is used instead. A slow image
 * is bad; a missing one is worse, and the sellers' photographs are the product.
 */
export const SmartImage = forwardRef<HTMLImageElement, SmartImageProps>(function SmartImage(
  { src, alt, widths, sizes, quality = 70, eager = false, className = "", ...rest },
  ref,
) {
  const [failed, setFailed] = useState(false);

  if (!src) return null;

  const widest = Math.max(...widths);

  return (
    <img
      src={failed ? src : optimizedSrc(src, widest, quality)}
      srcSet={failed ? undefined : optimizedSrcSet(src, widths, quality)}
      sizes={failed ? undefined : sizes}
      alt={alt}
      // Only what is on screen at first paint is worth blocking on.
      loading={eager ? "eager" : "lazy"}
      fetchPriority={eager ? "high" : "auto"}
      decoding="async"
      onError={() => setFailed(true)}
      // Forwarded because the hero carousel reads `complete` and
      // `naturalWidth` off the element to decide when a slide is safe to show.
      ref={ref}
      className={className}
      {...rest}
    />
  );
});

export default SmartImage;
