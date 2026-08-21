import { type FirebaseApp, getApps, initializeApp } from "firebase/app";
import {
  GoogleAuthProvider,
  getAuth,
  getRedirectResult,
  signInWithRedirect,
  type Auth,
} from "firebase/auth";

/**
 * Firebase Authentication for the storefront.
 *
 * Only Authentication is used, and only as an identity provider: Firebase says
 * who the shopper is, Laravel decides what that means for D2K and issues the
 * Sanctum token the rest of the app already runs on. There is no second
 * session system.
 *
 * Every value here is a public client identifier. Firebase config is designed
 * to ship in a browser bundle — it identifies the project, it does not
 * authorise anything. Access is controlled by the Firebase console's authorised
 * domains and by our own server-side token verification. No service-account
 * key is ever present on the client.
 */
/**
 * Where Firebase's `/__/auth/handler` lives.
 *
 * By default this is the project's own `*.firebaseapp.com` domain, which is a
 * different origin from the storefront. That is fine in Chrome but not in
 * Safari: a browser that partitions third-party storage will not let the
 * handler hand the result back across origins, and `getRedirectResult()`
 * quietly resolves to null.
 *
 * Setting NEXT_PUBLIC_FIREBASE_AUTH_PROXY=1 serves the handler from this
 * site's own origin instead — next.config.js rewrites /__/auth/* through to
 * Firebase — which removes the cross-origin hop entirely and makes redirect
 * sign-in work in Safari and iOS.
 *
 * It is off by default because turning it on changes the OAuth redirect_uri,
 * and Google rejects a redirect_uri that is not registered. See
 * DEPLOY-GOOGLE-REDIRECT.txt for the one console entry it needs.
 */
function authDomain(): string | undefined {
  const configured = process.env.NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN;

  if (process.env.NEXT_PUBLIC_FIREBASE_AUTH_PROXY !== "1") return configured;
  // Server-side render has no host to borrow; the value is only ever used in
  // the browser, and the browser re-reads it on mount.
  if (typeof window === "undefined") return configured;

  return window.location.host;
}

const config = {
  apiKey: process.env.NEXT_PUBLIC_FIREBASE_API_KEY,
  authDomain: authDomain(),
  projectId: process.env.NEXT_PUBLIC_FIREBASE_PROJECT_ID,
  storageBucket: process.env.NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: process.env.NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID,
  appId: process.env.NEXT_PUBLIC_FIREBASE_APP_ID,
};

/** True once the deployment has been given its Firebase values. */
export const firebaseConfigured = Boolean(config.apiKey && config.projectId && config.appId);

let app: FirebaseApp | undefined;

function firebaseApp(): FirebaseApp | undefined {
  if (!firebaseConfigured) return undefined;
  if (app) return app;

  // Next's fast refresh can evaluate this module more than once.
  app = getApps().length ? getApps()[0] : initializeApp(config as Required<typeof config>);
  return app;
}

export function firebaseAuth(): Auth | undefined {
  const instance = firebaseApp();
  return instance ? getAuth(instance) : undefined;
}

/**
 * Hands the browser to Google.
 *
 * This is a full-page navigation, not a popup. A popup is a window the user
 * did not ask for, and Chrome blocks it often enough that the sign-in button
 * had grown an error message asking people to go and change their browser
 * settings — which is not a thing to ask of someone trying to buy a phone.
 *
 * Nothing is returned because nothing comes back: the page is gone by the time
 * this settles. The other half of the flow is completeGoogleRedirect(), which
 * runs when Google sends the browser back.
 */
export async function startGoogleRedirect(): Promise<void> {
  const auth = firebaseAuth();

  if (!auth) {
    throw new Error("Google sign-in is not configured for this site.");
  }

  const provider = new GoogleAuthProvider();
  // Always show the chooser: a shared machine should not silently reuse the
  // last person's Google account.
  provider.setCustomParameters({ prompt: "select_account" });

  await signInWithRedirect(auth, provider);
}

/**
 * Picks up the result after Google sends the browser back, and returns the
 * Firebase ID token — or null when this page load is not a return from Google,
 * which is the overwhelmingly common case.
 *
 * The token is all that leaves this module. The caller posts it to Laravel,
 * which verifies the signature before believing any claim inside it.
 */
export async function completeGoogleRedirect(): Promise<string | null> {
  const auth = firebaseAuth();
  if (!auth) return null;

  const credential = await getRedirectResult(auth);
  if (!credential) return null;

  return credential.user.getIdToken();
}

/**
 * Ends the Firebase half of the session.
 *
 * D2K's own session is the Sanctum token; this exists so that signing out of
 * D2K does not leave a Firebase session behind that would skip the account
 * chooser on the next sign-in.
 */
export async function signOutOfFirebase(): Promise<void> {
  const auth = firebaseAuth();
  if (!auth) return;

  const { signOut } = await import("firebase/auth");
  await signOut(auth).catch(() => undefined);
}
