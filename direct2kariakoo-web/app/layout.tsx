import type { Metadata, Viewport } from "next";
import { Manrope } from "next/font/google";
import { BRAND } from "@/lib/brand";
import { Providers } from "@/components/layout/Providers";
import "./globals.css";

/**
 * Root layout.
 *
 * Deliberately thin: it establishes the document, the typeface and the
 * metadata only. Every storefront route composes <SiteChrome> itself so
 * standalone surfaces (vendor portal, auth pages) can opt out of the shop
 * header and footer.
 */

const brandFont = Manrope({
  subsets: ["latin"],
  weight: ["400", "500", "600", "700", "800"],
  variable: "--font-brand",
  display: "swap",
});

export const metadata: Metadata = {
  title: {
    default: `${BRAND.name} — ${BRAND.tagline}`,
    template: `%s | ${BRAND.name}`,
  },
  description: `Shop electronics, fashion, home, beauty and more from trusted ${BRAND.country} sellers. Fast delivery across ${BRAND.city}.`,
  applicationName: BRAND.name,
  icons: {
    // Square brand mark (yellow d2k). Google Search picks a favicon that is a
    // multiple of 48px, so the 192px PNG is the one it will show in results.
    icon: [
      { url: "/favicon.ico", sizes: "16x16 32x32 48x48" },
      { url: "/icon-192.png", type: "image/png", sizes: "192x192" },
      { url: "/logo.png", type: "image/png", sizes: "512x512" },
    ],
    apple: { url: "/apple-touch-icon.png", sizes: "180x180" },
  },
  openGraph: {
    title: BRAND.name,
    description: BRAND.tagline,
    type: "website",
    locale: "en_TZ",
  },
};

export const viewport: Viewport = {
  themeColor: "#fee500",
  width: "device-width",
  initialScale: 1,
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className={brandFont.variable}>
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
