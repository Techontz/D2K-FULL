const withBundleAnalyzer = require("@next/bundle-analyzer")({
  enabled: process.env.ANALYZE === "true",
});

/** @type {import('next').NextConfig} */
const nextConfig = {
  images: {
    unoptimized: true,
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
  skipTrailingSlashRedirect: process.env.NEXT_PUBLIC_FIREBASE_AUTH_PROXY === "1",

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

  compiler: {
    removeConsole: process.env.NODE_ENV === "production",
  },
};

module.exports = withBundleAnalyzer(nextConfig);
