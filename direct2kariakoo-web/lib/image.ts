/**
 * Image sizing for the storefront.
 *
 * The catalogue is full of originals straight off a seller's phone — a 5.6MB
 * PNG behind a 150px product card is normal here. Shipping those untouched
 * cost the homepage 11.2MB on a 390px screen, which on Tanzanian mobile data
 * is most of the page's load time and a real amount of the visitor's money.
 *
 * These helpers hand the URL to Next's optimiser, which resizes once, converts
 * to AVIF/WebP and caches the result at the edge. The markup and layout are
 * unchanged — only the bytes behind them shrink.
 *
 * Widths must come from `images.deviceSizes`/`imageSizes` in next.config.js;
 * the optimiser rejects anything else.
 */

/** Widths declared in next.config.js. Keep the two in step. */
const ALLOWED = [16, 32, 48, 64, 96, 128, 200, 256, 320, 420, 640, 750, 828, 1080, 1200, 1920] as const;

function nearestAllowed(width: number): number {
  return ALLOWED.find((w) => w >= width) ?? ALLOWED[ALLOWED.length - 1];
}

/**
 * A single resized URL.
 *
 * Returns the original untouched for data URIs, SVGs and relative paths —
 * either the optimiser cannot improve them or they are already ours.
 */
export function optimizedSrc(src: string | null | undefined, width: number, quality = 70): string {
  if (!src) return "";
  if (src.startsWith("data:") || src.endsWith(".svg") || !src.startsWith("http")) return src;

  return `/_next/image?url=${encodeURIComponent(src)}&w=${nearestAllowed(width)}&q=${quality}`;
}

/**
 * A srcSet so the browser picks the size it actually needs.
 *
 * A phone should not download the desktop rendition just because the markup is
 * shared between them.
 */
export function optimizedSrcSet(
  src: string | null | undefined,
  widths: number[],
  quality = 70,
): string | undefined {
  if (!src || src.startsWith("data:") || src.endsWith(".svg") || !src.startsWith("http")) {
    return undefined;
  }

  return widths
    .map((w) => nearestAllowed(w))
    .filter((w, index, all) => all.indexOf(w) === index)
    .map((w) => `${optimizedSrc(src, w, quality)} ${w}w`)
    .join(", ");
}
