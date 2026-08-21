"use client";

import Link from "next/link";
import { Suspense, useCallback, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { formatDate, formatMoney } from "@/lib/format";
import shop from "@/lib/shop";
import { useAuth } from "@/lib/store/auth";
import type { Order } from "@/lib/types";
import { SiteChrome } from "@/components/layout/SiteChrome";
import { Button, ButtonLink, EmptyState, Skeleton, Tag } from "@/components/ui/Primitives";
import { useT } from "@/lib/i18n";

/** Colour per fulfilment stage, so status is readable at a glance. */
const STATUS_TONE: Record<string, "success" | "warn" | "action" | "neutral" | "sale"> = {
  completed: "success",
  shipped: "action",
  processing: "action",
  pending: "warn",
  cancelled: "sale",
};

export default function OrdersPage() {
  return (
    <SiteChrome>
      <Suspense fallback={<div className="shell py-6"><Skeleton className="h-32 w-full" /></div>}>
        <OrdersContent />
      </Suspense>
    </SiteChrome>
  );
}

function OrdersContent() {
  const t = useT();
  const params = useSearchParams();
  const justPlaced = params.get("placed");
  const { isAuthenticated, ready, requireAuth } = useAuth();

  const [orders, setOrders] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [cancelling, setCancelling] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setOrders(await shop.orders());
    } catch {
      setOrders([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!ready) return;
    if (!isAuthenticated) {
      setLoading(false);
      void requireAuth();
      return;
    }
    void load();
  }, [ready, isAuthenticated, requireAuth, load]);

  async function cancel(reference: string) {
    setCancelling(reference);
    try {
      await shop.cancelOrder(reference);
      await load();
    } finally {
      setCancelling(null);
    }
  }

  if (ready && !isAuthenticated) {
    return (
      <EmptyState
        title={t("orders.signInTitle")}
        message={t("orders.signInHint")}
        action={<Button size="lg" onClick={() => void requireAuth()}>{t("auth.login")}</Button>}
      />
    );
  }

  return (
    <div className="shell py-4">
      {justPlaced ? (
        <div className="mb-4 flex flex-wrap items-center gap-3 rounded-[var(--radius-md)] border border-[color:var(--color-success)] bg-[color:var(--color-success-soft)] p-4">
          <span className="text-2xl" aria-hidden="true">✅</span>
          <div>
            <p className="text-[15px] font-extrabold text-[color:var(--color-success)]">
              Order placed successfully
            </p>
            <p className="text-[13px] text-[color:var(--color-ink-muted)]">
              Your reference is <span className="font-bold">{justPlaced}</span> — quote it if you contact support.
            </p>
          </div>
        </div>
      ) : null}

      <h1 className="mb-4 text-[22px] font-extrabold tracking-tight md:text-[26px]">{t("orders.title")}</h1>

      {loading ? (
        <div className="space-y-3">
          {[0, 1].map((index) => <Skeleton key={index} className="h-40 w-full" />)}
        </div>
      ) : orders.length === 0 ? (
        <EmptyState
          title={t("orders.empty")}
          message={t("orders.emptyHintLong")}
          action={<ButtonLink href="/" size="lg">{t("orders.startShopping")}</ButtonLink>}
        />
      ) : (
        <div className="space-y-3">
          {orders.map((order) => (
            <article key={order.reference} className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
              {/* The reference is what a shopper quotes to support, so it keeps
                  its intrinsic width and never wraps or truncates — the status
                  badge moves to its own run instead. */}
              <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                <div>
                  <p className="text-[15px] font-extrabold">{order.reference}</p>
                  <p className="text-[12px] text-[color:var(--color-ink-muted)]">
                    {t("orders.placedOn", { date: formatDate(order.placed_at) })} ·{" "}
                    {order.item_count === 1
                      ? t("orders.itemCountOne")
                      : t("orders.itemCount", { count: order.item_count })}
                  </p>
                </div>
                <Tag tone={STATUS_TONE[order.status] ?? "neutral"}>{order.status}</Tag>
              </div>

              <div className="rail mt-3 gap-2">
                {order.items.map((item) => (
                  <div key={item.id} className="w-14 shrink-0">
                    <span className="block h-14 w-14 overflow-hidden rounded-[var(--radius-sm)] bg-white ring-1 ring-[color:var(--color-line)]">
                      {item.product?.image ? (
                        <img src={item.product.image} alt={item.product.name} loading="lazy"
                          className="h-full w-full object-contain p-1" />
                      ) : null}
                    </span>
                  </div>
                ))}
              </div>

              {order.delivery_address ? (
                <p className="clamp-2 mt-3 text-[12px] text-[color:var(--color-ink-muted)]">
                  {t("orders.deliveringTo", { address: order.delivery_address })}
                </p>
              ) : null}

              {/* ---- how to pay ----

                  Every number here is the one that was on the screen when this
                  order was placed: the backend snapshots each seller's Lipa
                  Namba onto the order line. A seller who changes their till
                  next month does not get to rewrite what a past customer was
                  told to pay, so a receipt stays answerable months later.

                  Orders placed before Lipa Namba existed carry no snapshot;
                  those rows are skipped rather than back-filled from the
                  seller's current profile, which would be a guess. */}
              {order.vendor_payments.some((payment) => payment.lipa_namba) ? (
                <div className="mt-3 border-t border-[color:var(--color-line)] pt-3">
                  <p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-[color:var(--color-ink-muted)]">
                    {t("orders.howToPay")}
                  </p>
                  <ul className="space-y-2">
                    {order.vendor_payments
                      .filter((payment) => payment.lipa_namba)
                      .map((payment) => (
                        <li
                          key={payment.vendor_id}
                          className="rounded-[var(--radius-sm)] bg-[color:var(--color-surface-alt)] p-2.5"
                        >
                          <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <span className="clamp-1 min-w-0 text-[13px] font-extrabold">
                              {payment.vendor_name}
                            </span>
                            <span className="shrink-0 text-[13px] font-bold">
                              {formatMoney(payment.subtotal)}
                            </span>
                          </div>
                          <span className="mt-1 block text-[11px] font-bold uppercase tracking-wide text-[color:var(--color-ink-muted)]">
                            {t("payment.lipaNamba")}
                          </span>
                          {/* break-all so a long till wraps inside a 320px
                              card instead of widening the page. */}
                          <span className="block break-all font-mono text-[16px] font-black leading-tight tracking-wide">
                            {payment.lipa_namba_display ?? payment.lipa_namba}
                          </span>
                        </li>
                      ))}
                  </ul>
                </div>
              ) : null}

              <div className="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-[color:var(--color-line)] pt-3">
                <div>
                  <span className="text-[12px] text-[color:var(--color-ink-muted)]">{t("orders.total")}</span>
                  <p className="text-[18px] font-black">{formatMoney(order.total)}</p>
                  {/* Named rather than shown as zero: the fee has not been
                      worked out yet, which is not the same as free. */}
                  {order.delivery_status === "pending" ? (
                    <p className="text-[11px] text-[color:var(--color-ink-muted)]">
                      {t("orders.deliveryPending")}
                    </p>
                  ) : null}
                </div>

                {["pending", "processing"].includes(order.status) ? (
                  <Button
                    variant="secondary"
                    size="sm"
                    disabled={cancelling === order.reference}
                    onClick={() => cancel(order.reference)}
                  >
                    {cancelling === order.reference ? t("orders.cancelling") : t("orders.cancel")}
                  </Button>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  );
}
