"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import type { CartLine, ProductCard, SelectedOptions } from "../types";
import { lineKey, normaliseOptions } from "../options";

export type { CartLine } from "../types";

/**
 * Shopping cart.
 *
 * A visitor can fill a cart before they have an account — that is the whole
 * point of browsing without a forced login. The cart therefore lives in
 * browser storage and is only sent to the server at checkout, when the
 * shopper has authenticated. Nothing here requires a session.
 *
 * A line is a product *plus the options chosen for it*: the same shoe in
 * size 40 and size 42 is two lines. Lines are addressed by `lineKey()`, which
 * for a product without options is just its id — so carts saved before
 * options existed keep working unchanged.
 */

interface CartContextValue {
  lines: CartLine[];
  count: number;
  subtotal: number;
  ready: boolean;
  add(product: ProductCard, quantity?: number, options?: SelectedOptions | null): void;
  /** `key` is `lineKey(product.id, line.options)`. */
  setQuantity(key: string, quantity: number): void;
  remove(key: string): void;
  clear(): void;
  /** Any line of this product, whatever options it carries. */
  has(productId: number): boolean;
  /** Units of this product across all of its option combinations. */
  quantityOf(productId: number): number;
}

const STORAGE_KEY = "d2k.cart.v1";
const CartContext = createContext<CartContextValue | null>(null);

export function CartProvider({ children }: { children: React.ReactNode }) {
  const [lines, setLines] = useState<CartLine[]>([]);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (raw) {
        // Older saved carts have no `options`; normalising here means every
        // line in memory has the same shape whatever version stored it.
        const stored = JSON.parse(raw) as CartLine[];
        setLines(
          Array.isArray(stored)
            ? stored
                .filter((line) => line && line.product && typeof line.product.id === "number")
                .map((line) => ({ ...line, options: normaliseOptions(line.options) }))
            : [],
        );
      }
    } catch {
      window.localStorage.removeItem(STORAGE_KEY);
    }
    setReady(true);
  }, []);

  // Persist after hydration only, so an empty initial state cannot clobber a
  // cart saved in a previous session.
  useEffect(() => {
    if (!ready) return;
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(lines));
  }, [lines, ready]);

  const add = useCallback(
    (product: ProductCard, quantity = 1, options: SelectedOptions | null = null) => {
      const chosen = normaliseOptions(options);
      const key = lineKey(product.id, chosen);

      setLines((current) => {
        const existing = current.find((line) => keyOf(line) === key);

        if (!existing) {
          // Never let a shopper add more than the vendor actually has.
          return [...current, { product, quantity: clamp(quantity, product.stock), options: chosen }];
        }

        return current.map((line) =>
          keyOf(line) === key
            ? { ...line, quantity: clamp(line.quantity + quantity, product.stock) }
            : line
        );
      });
    },
    []
  );

  const setQuantity = useCallback((key: string, quantity: number) => {
    setLines((current) =>
      quantity <= 0
        ? current.filter((line) => keyOf(line) !== key)
        : current.map((line) =>
            keyOf(line) === key
              ? { ...line, quantity: clamp(quantity, line.product.stock) }
              : line
          )
    );
  }, []);

  const remove = useCallback((key: string) => {
    setLines((current) => current.filter((line) => keyOf(line) !== key));
  }, []);

  const clear = useCallback(() => setLines([]), []);

  const value = useMemo<CartContextValue>(() => {
    const count = lines.reduce((sum, line) => sum + line.quantity, 0);
    const subtotal = lines.reduce(
      (sum, line) => sum + line.product.price.current * line.quantity,
      0
    );

    return {
      lines,
      count,
      subtotal,
      ready,
      add,
      setQuantity,
      remove,
      clear,
      has: (id) => lines.some((line) => line.product.id === id),
      quantityOf: (id) =>
        lines.reduce((sum, line) => (line.product.id === id ? sum + line.quantity : sum), 0),
    };
  }, [lines, ready, add, setQuantity, remove, clear]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartContextValue {
  const context = useContext(CartContext);
  if (!context) throw new Error("useCart must be used inside <CartProvider>");
  return context;
}

/** The identity a line is addressed by. */
export function keyOf(line: CartLine): string {
  return lineKey(line.product.id, line.options);
}

function clamp(quantity: number, stock: number): number {
  const ceiling = stock > 0 ? stock : 1;
  return Math.max(1, Math.min(quantity, ceiling));
}
