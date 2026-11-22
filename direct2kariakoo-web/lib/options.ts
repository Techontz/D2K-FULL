import type { SelectedOptions } from "./types";

/**
 * Helpers for a product's chosen options (size, colour…).
 *
 * The wire format is a flat `{ "Size": "M", "Color": "Red" }` object, and
 * `null`, absent and `{}` all mean the same thing: no options. Everything that
 * compares or prints options goes through here so those three never disagree.
 */

/** `{}` / `null` / `undefined` → `null`; otherwise a copy with blank values dropped. */
export function normaliseOptions(options: SelectedOptions | null | undefined): SelectedOptions | null {
  if (!options || typeof options !== "object") return null;

  const clean: SelectedOptions = {};
  for (const [name, value] of Object.entries(options)) {
    if (typeof value === "string" && value !== "") clean[name] = value;
  }

  return Object.keys(clean).length > 0 ? clean : null;
}

/**
 * A stable identity for a set of options, independent of key order — so
 * `{Size, Color}` and `{Color, Size}` are the same cart line.
 */
export function optionsKey(options: SelectedOptions | null | undefined): string {
  const clean = normaliseOptions(options);
  if (!clean) return "";
  return Object.keys(clean)
    .sort()
    .map((name) => `${name}=${clean[name]}`)
    .join("|");
}

/** The cart-line identity: product plus whatever was chosen for it. */
export function lineKey(productId: number, options: SelectedOptions | null | undefined): string {
  const key = optionsKey(options);
  return key ? `${productId}:${key}` : String(productId);
}

/** `"Size: M · Color: Red"`, or `""` when there is nothing to show. */
export function formatOptions(options: SelectedOptions | null | undefined): string {
  const clean = normaliseOptions(options);
  if (!clean) return "";
  return Object.entries(clean)
    .map(([name, value]) => `${name}: ${value}`)
    .join(" · ");
}
