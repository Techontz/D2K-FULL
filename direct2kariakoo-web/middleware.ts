import { NextResponse, type NextRequest } from "next/server";

/**
 * Keeps the site's trailing-slash URLs while letting Firebase's OAuth handler
 * keep its exact path.
 *
 * This only does anything when NEXT_PUBLIC_FIREBASE_AUTH_PROXY=1. In that mode
 * next.config.js sets `skipTrailingSlashRedirect`, because Next would otherwise
 * redirect /__/auth/handler to /__/auth/handler/ — and Firebase's handler loads
 * its scripts by relative path, so from the trailing-slash URL they resolve one
 * directory too deep and 404. The callback page then hangs silently.
 *
 * Turning the redirect off wholesale would change every URL on the site, so it
 * is reinstated here for everything except that one path.
 */
export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  // Firebase's handler and the scripts it pulls in, byte-for-byte as issued.
  if (pathname.startsWith("/__/auth")) {
    return NextResponse.next();
  }

  // Everything else keeps the trailing slash the site has always served.
  // Paths with a file extension are assets and never carried one.
  if (!pathname.endsWith("/") && !pathname.split("/").pop()?.includes(".")) {
    // A plain URL, deliberately not request.nextUrl.clone(): NextURL
    // re-normalises its pathname against `trailingSlash`, which strips the
    // slash straight back off and emits a redirect to the same path. That is
    // an infinite loop on every page of the site.
    const url = new URL(request.url);
    url.pathname = `${pathname}/`;
    return NextResponse.redirect(url, 308);
  }

  return NextResponse.next();
}

export const config = {
  // Next's own internals and static assets never need either behaviour.
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
