"use client";

import { SmartImage } from "@/components/ui/SmartImage";
import { optimizedSrcSet } from "@/lib/image";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useT } from "@/lib/i18n";
import type { HeroBanner as HeroBannerModel } from "@/lib/types";

/**
 * The hero: a wide rotating carousel with a fixed promotional card beside it.
 *
 * Both halves are database rows, not markup, so an administrator changes a
 * campaign by editing a banner rather than by asking for a deploy.
 *
 * The strip is deliberately shallow — a fixed 3:1 on desktop — so the first
 * product row is visible without scrolling. A hero that fills the viewport
 * pushes the actual shop below the fold.
 */
export function HeroBanner({
  slides,
  side,
  loading = false,
}: {
  slides: HeroBannerModel[];
  side: HeroBannerModel | null;
  loading?: boolean;
}) {
  if (loading) {
    return (
      <section className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,300px)]">
        <div className="skeleton aspect-[3/1] w-full rounded-[var(--radius-md)]" />
        <div className="skeleton aspect-[3/1] rounded-[var(--radius-md)] lg:aspect-auto" />
      </section>
    );
  }

  if (slides.length === 0 && !side) return null;

  return (
    // On a phone the side card drops beneath the carousel rather than being
    // squeezed into a column too narrow to read.
    <section className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,300px)]">
      <Carousel slides={slides} />
      {side ? <SideCard banner={side} /> : null}
    </section>
  );
}

function Carousel({ slides }: { slides: HeroBannerModel[] }) {
  const t = useT();
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const timer = useRef<number | null>(null);

  // The rendered <img> for each slide, read directly when the timer fires.
  const artwork = useRef<(HTMLImageElement | null)[]>([]);

  // Slides after the first are held back until the page has finished loading.
  //
  // They cannot use native lazy loading — a translate-based carousel keeps them
  // inside the viewport as far as the browser is concerned, so `loading="lazy"`
  // never fires and a banner that is never fetched is a panel that is never
  // shown. But fetching them all up front means the first paint competes with
  // whatever the admin uploaded, and one live banner is a 3.2MB PNG that
  // Vercel's optimiser refuses and passes through untouched.
  //
  // Waiting for `load` costs nothing visible: the first slide is the only one
  // on screen for the first six seconds anyway.
  const [loadRest, setLoadRest] = useState(false);

  useEffect(() => {
    if (document.readyState === "complete") {
      setLoadRest(true);
      return;
    }

    const onLoad = () => setLoadRest(true);
    window.addEventListener("load", onLoad, { once: true });

    // A stalled sub-resource must not strand the carousel on one slide.
    const fallback = window.setTimeout(() => setLoadRest(true), 4000);

    return () => {
      window.removeEventListener("load", onLoad);
      window.clearTimeout(fallback);
    };
  }, []);

  const count = slides.length;
  const go = useCallback((next: number) => setIndex((next + count) % count), [count]);

  useEffect(() => {
    if (count <= 1 || paused) return;

    // Rotate only onto artwork that has actually arrived.
    //
    // Banner files come from the admin at whatever size was uploaded, and the
    // API host serves them slowly — a 5.7 MB banner takes the better part of a
    // minute on a Tanzanian connection. Advancing on a fixed timer regardless
    // meant the hero spent most of its cycle on empty panels, which is what
    // made a freshly published banner look like it had never been published.
    // A broken or unreachable image is skipped for the same reason.
    //
    // Readiness is read off the elements rather than tracked from `load`
    // events: a cached banner can finish before React attaches a handler, and
    // the event is not replayed for a late listener. `complete` is always true
    // by then, so asking the DOM at the moment it matters cannot race.
    timer.current = window.setInterval(() => {
      setIndex((current) => {
        for (let step = 1; step <= count; step += 1) {
          const candidate = (current + step) % count;
          const image = artwork.current[candidate];
          if (image?.complete && image.naturalWidth > 0) return candidate;
        }
        return current;
      });
    }, 6000);

    return () => {
      if (timer.current) window.clearInterval(timer.current);
    };
  }, [count, paused]);

  // A hidden tab must not keep advancing; it wastes work and the shopper
  // returns to a slide they never saw start.
  useEffect(() => {
    const onVisibility = () => setPaused(document.hidden);
    document.addEventListener("visibilitychange", onVisibility);
    return () => document.removeEventListener("visibilitychange", onVisibility);
  }, []);

  if (count === 0) return null;

  return (
    <div
      className="relative overflow-hidden rounded-[var(--radius-md)] bg-[color:var(--color-surface-alt)]"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      aria-roledescription="carousel"
    >
      <div
        className="flex transition-transform duration-500 ease-out"
        style={{ transform: `translateX(-${index * 100}%)` }}
      >
        {slides.map((slide, position) => (
          <Slide
            key={slide.id}
            banner={slide}
            active={position === index}
            eager={position === 0}
            // The first slide always renders; the rest join once the page has
            // loaded. The rotation timer only advances onto artwork that has
            // actually arrived, so a slide that is not there yet is simply
            // skipped until it is.
            load={position === 0 || loadRest}
            imageRef={(node) => {
              artwork.current[position] = node;
            }}
          />
        ))}
      </div>

      {count > 1 ? (
        <>
          <Arrow side="left" label={t("listing.previous")} onClick={() => go(index - 1)} />
          <Arrow side="right" label={t("listing.next")} onClick={() => go(index + 1)} />

          <div className="absolute inset-x-0 bottom-2.5 flex justify-center gap-1.5">
            {slides.map((slide, position) => (
              <button
                key={slide.id}
                type="button"
                onClick={() => go(position)}
                aria-label={`${position + 1} / ${count}`}
                aria-current={position === index}
                className={`h-1.5 rounded-full transition-all ${
                  position === index
                    ? "w-5 bg-[color:var(--color-ink)]"
                    : "w-1.5 bg-[color:var(--color-ink)]/35 hover:bg-[color:var(--color-ink)]/60"
                }`}
              />
            ))}
          </div>
        </>
      ) : null}
    </div>
  );
}

function Slide({
  banner,
  active,
  eager,
  load,
  imageRef,
}: {
  banner: HeroBannerModel;
  active: boolean;
  eager: boolean;
  load: boolean;
  imageRef(node: HTMLImageElement | null): void;
}) {
  const image = load ? (
    <BannerArtwork
      banner={banner}
      eager={eager}
      className="aspect-[3/1] w-full object-cover"
      imageRef={imageRef}
    />
  ) : (
    // Holds the slot at the same ratio so nothing moves when the artwork
    // arrives.
    <div className="aspect-[3/1] w-full bg-[color:var(--color-surface-alt)]" />
  );

  return (
    <div className="w-full shrink-0" aria-hidden={!active}>
      {banner.link ? (
        <Link href={banner.link} prefetch={false} tabIndex={active ? 0 : -1} className="block">
          {image}
        </Link>
      ) : (
        image
      )}
    </div>
  );
}

/**
 * A banner's artwork.
 *
 * Served straight from the API host rather than through Next's image
 * optimiser. That is a measured decision, not an oversight: the optimiser has
 * a short upstream fetch budget, and the API host currently delivers banner
 * files at roughly 140 KB/s — a 5.7 MB upload takes forty seconds to read, so
 * the optimiser times out and returns nothing at all. A slow banner beats a
 * broken one. The lasting fix is on the other side of the wire, where banner
 * uploads are now bounded so artwork of that size cannot be stored again.
 *
 * Every slide loads eagerly, including the ones off-screen. Lazy loading is
 * wrong for a translate-based carousel: the later slides sit outside the
 * clipped strip, so the browser never considers them near the viewport and
 * never fetches them — yet the timer slides them into view. Priority still
 * differs, because the first slide is the page's largest contentful paint and
 * must not queue behind the rest.
 */
function BannerArtwork({
  banner,
  eager,
  className,
  cropUntil = "sm",
  imageRef,
}: {
  banner: HeroBannerModel;
  eager: boolean;
  className: string;
  /** The breakpoint above which the wide artwork takes over. */
  cropUntil?: "sm" | "lg";
  imageRef?(node: HTMLImageElement | null): void;
}) {
  const media = cropUntil === "lg" ? "(max-width: 1023px)" : "(max-width: 640px)";

  // The phone crop when an administrator uploaded one, the wide artwork
  // otherwise. `mobile_image` falls back to `image` server-side, so for most
  // banners these are the same file.
  const phoneArt = banner.mobile_image && banner.mobile_image !== banner.image
    ? banner.mobile_image
    : banner.image;

  return (
    <picture>
      {phoneArt !== banner.image ? (
        <source
          media={media}
          srcSet={optimizedSrcSet(phoneArt, [828], 65)}
          sizes="100vw"
        />
      ) : null}

      {/* These are the heaviest images on the site — the two live banners are
          5.6MB and 3.2MB PNGs. Resized and re-encoded they are a rounding
          error, and the strip is the first thing a visitor sees. */}
      <SmartImage
        src={banner.image}
        alt={banner.alt ?? banner.title ?? ""}
        // Two widths, not four. Each distinct width is a separate fetch of
        // the original from the origin, and the live banners are 3-5MB PNGs
        // that the origin needs ~10s to serve — longer than the optimiser
        // waits. Fewer widths means fewer chances to hit that timeout, and
        // the fallback in SmartImage covers the ones that do.
        widths={[828, 1200]}
        sizes="(max-width: 1023px) 100vw, 66vw"
        quality={65}
        eager={eager}
        ref={imageRef}
        className={className}
      />
    </picture>
  );
}

/** The fixed card. It never rotates — it stays until an admin changes it. */
function SideCard({ banner }: { banner: HeroBannerModel }) {
  // Below lg the card stacks under the carousel and spans the full width. The
  // square artwork would then stand ~640px tall, so a wide crop is used there
  // and the strip is pinned to the carousel's 3:1.
  const image = (
    <BannerArtwork
      banner={banner}
      eager={false}
      cropUntil="lg"
      className="h-full w-full object-cover"
    />
  );

  return (
    <div className="aspect-[3/1] overflow-hidden rounded-[var(--radius-md)] bg-[color:var(--color-surface-alt)] lg:aspect-auto">
      {banner.link ? (
        <Link href={banner.link} prefetch={false} className="block h-full">
          {image}
        </Link>
      ) : (
        image
      )}
    </div>
  );
}

function Arrow({
  side,
  label,
  onClick,
}: {
  side: "left" | "right";
  label: string;
  onClick(): void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className={`absolute top-1/2 hidden h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow-[var(--shadow-card)] transition-opacity hover:bg-white sm:flex ${
        side === "left" ? "left-2" : "right-2"
      }`}
    >
      <svg viewBox="0 0 24 24" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth="2.5"
        strokeLinecap="round" strokeLinejoin="round">
        <path d={side === "left" ? "M15 18l-6-6 6-6" : "M9 18l6-6-6-6"} />
      </svg>
    </button>
  );
}
