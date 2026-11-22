const withBundleAnalyzer = require("@next/bundle-analyzer")({
  enabled: process.env.ANALYZE === "true",
});

/** @type {import('next').NextConfig} */
const nextConfig = {
  images: {
    // Optimisation is ON. It was disabled while the origin was serving
    // multi-megabyte banners so slowly that the optimiser timed out; the cost
    // of leaving it off was every visitor downloading those originals in full
    // — 11.2MB on a 390px homepage. The optimiser fetches each source once,
    // caches the result, and every visitor after that gets a resized AVIF.
    unoptimized: false,

    // AVIF first, WebP as the fallback; both are a fraction of the PNGs
    // sellers upload.
    formats: ["image/avif", "image/webp"],

    // Must match ALLOWED in lib/image.ts — the optimiser rejects any width
    // that is not declared here.
    deviceSizes: [420, 640, 750, 828, 1080, 1200, 1920],
    imageSizes: [16, 32, 48, 64, 96, 128, 200, 256, 320],

    // Sellers replace a photograph by uploading a new file, which changes the
    // URL, so a long cache cannot serve a stale picture.
    minimumCacheTTL: 60 * 60 * 24 * 30,
    remotePatterns: [
      {
        protocol: "https",
        hostname: "api.direct2kariakoo.com",
        pathname: "/**",
      },
    ],
  },

  eslint: { ignoreDuringBuilds: true },
  typescript: { ignoreBuildErrors: true },

  trailingSlash: true,

  /**
   * When the Firebase auth proxy is on, Next's own trailing-slash redirect has
   * to get out of the way.
   *
   * It rewrites /__/auth/handler to /__/auth/handler/, and Firebase's handler
   * page loads `handler.js` and `experiments.js` by *relative* path — from the
   * trailing-slash URL those resolve to /__/auth/handler/handler.js, which is a
   * 404. The page then sits there doing nothing, which is the worst possible
   * failure for a sign-in callback.
   *
   * middleware.ts puts the redirect back for every other route, so the site's
   * URLs are unchanged. With the proxy off, none of this applies and Next's
   * built-in behaviour is used as before.
   */
  // Next's own trailing-slash redirect is turned off and reinstated in
  // middleware.ts instead, which lets two paths opt out of it:
  //
  //   /_next/image  — otherwise every optimised image costs a 308 round trip
  //                   before the bytes even start, on every image on the page
  //   /__/auth      — Firebase's OAuth handler loads its scripts by relative
  //                   path, so an added slash resolves them one level too deep
  //
  // Every real route still redirects to its trailing-slash form exactly as
  // before; the site's URLs are unchanged.
  skipTrailingSlashRedirect: true,

  /**
   * Serve Firebase's auth handler from our own origin.
   *
   * Redirect sign-in normally bounces through
   * `<project>.firebaseapp.com/__/auth/handler`, which is a different origin
   * from the storefront. Safari and iOS partition third-party storage, so the
   * handler cannot hand the result back and `getRedirectResult()` resolves to
   * null with no error — sign-in simply appears to do nothing.
   *
   * Proxying the handler here makes it same-origin and the problem disappears.
   * `beforeFiles` matters: it runs ahead of the trailingSlash redirect, which
   * would otherwise rewrite /__/auth/handler to /__/auth/handler/ and break
   * the OAuth callback.
   *
   * Only takes effect when NEXT_PUBLIC_FIREBASE_AUTH_PROXY=1, because it
   * changes the OAuth redirect_uri and Google rejects unregistered ones.
   */
  async rewrites() {
    const handler = process.env.NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN;

    if (process.env.NEXT_PUBLIC_FIREBASE_AUTH_PROXY !== "1" || !handler) {
      return { beforeFiles: [], afterFiles: [], fallback: [] };
    }

    return {
      beforeFiles: [
        {
          source: "/__/auth/:path*",
          destination: `https://${handler}/__/auth/:path*`,
        },
      ],
      afterFiles: [],
      fallback: [],
    };
  },

  // 🚫 DO NOT USE output: "export" ON VERCEL
  //
  // "standalone" is what the DirectAdmin host runs: `next build` emits
  // .next/standalone/server.js plus only the node_modules it actually needs,
  // which is what CloudLinux's "Setup Node.js App" starts. Vercel ignores it.
  output: "standalone",

  compiler: {
    removeConsole: process.env.NODE_ENV === "production",
  },
};

module.exports = withBundleAnalyzer(nextConfig);
