"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { apiError } from "@/lib/api";
import { BRAND } from "@/lib/brand";
import { formatMoney } from "@/lib/format";
import shop from "@/lib/shop";
import type { Address as AddressType, CheckoutPreview } from "@/lib/types";
import { useT } from "@/lib/i18n";
import { useLocation } from "@/lib/store/location";
import { LocationPicker } from "@/components/location/LocationPicker";
import { useAuth } from "@/lib/store/auth";
import { useCart } from "@/lib/store/cart";
import { SiteChrome } from "@/components/layout/SiteChrome";
import { Button, ButtonLink, EmptyState } from "@/components/ui/Primitives";

/**
 * Checkout — the point at which the storefront asks who the shopper is.
 *
 * Everything before this is open. Here `requireAuth()` opens the login sheet;
 * dismissing it leaves the cart untouched so nothing is lost by declining.
 */
export default function CheckoutPage() {
  return (
    <SiteChrome>
      <CheckoutContent />
    </SiteChrome>
  );
}

function CheckoutContent() {
  const t = useT();
  const { user, isAuthenticated, ready, requireAuth } = useAuth();
  const cart = useCart();
  const router = useRouter();
  const { location: pinned, setLocation } = useLocation();

  const [address, setAddress] = useState("");
  const [phone, setPhone] = useState("");
  const [saved, setSaved] = useState<AddressType[]>([]);
  // `null` means "type a different address", so an explicit choice to enter a
  // one-off destination is not overwritten when the saved list arrives.
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [mapOpen, setMapOpen] = useState(false);
  // The shopper pays each seller on that seller's own Lipa Namba. Mobile money
  // is shown but not selectable: pretending an unintegrated provider took the
  // money would create an order nobody has actually paid for.
  const payment = "lipa_namba" as const;
  const [placing, setPlacing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Everything priced — subtotal, delivery, per-seller totals and each
  // seller's till number — comes from the server. The storefront deliberately
  // holds no copy of the pricing rules to fall out of step with the backend.
  const [preview, setPreview] = useState<CheckoutPreview | null>(null);
  const [previewFailed, setPreviewFailed] = useState(false);

  // The destination pin, when the shopper has one. Delivery is priced from it;
  // an address typed without a pin leaves the fee pending rather than free.
  const [coords, setCoords] = useState<{ latitude: number; longitude: number } | null>(null);

  // Ask for credentials as soon as the shopper lands here, not before.
  useEffect(() => {
    if (ready && !isAuthenticated) void requireAuth();
  }, [ready, isAuthenticated, requireAuth]);

  useEffect(() => {
    if (user?.phone && !phone) setPhone(user.phone);
  }, [user, phone]);

  // Pull the address book once signed in and preselect the default, so the
  // common case is one click rather than retyping a known address.
  useEffect(() => {
    if (!ready || !isAuthenticated) return;

    let cancelled = false;

    shop
      .addresses()
      .then((list) => {
        if (cancelled || list.length === 0) return;
        setSaved(list);
        const preferred = list.find((item) => item.is_default) ?? list[0];
        setSelectedId(preferred.id);
        setAddress(preferred.formatted);
        if (preferred.phone) setPhone(preferred.phone);
      })
      .catch(() => {
        // A failure here is not fatal: the manual fields still work.
      });

    return () => {
      cancelled = true;
    };
  }, [ready, isAuthenticated]);

  // A location chosen in the header should already be filled in when the
  // shopper reaches checkout, rather than being asked for twice.
  useEffect(() => {
    if (pinned && !address && selectedId === null) {
      setAddress(pinned.formatted);
    }
  }, [pinned, address, selectedId]);

  function chooseAddress(item: AddressType) {
    setSelectedId(item.id);
    setAddress(item.formatted);
    if (item.phone) setPhone(item.phone);
    // A saved address may carry the pin it was created with, which is what
    // lets delivery be priced without asking for the map again.
    setCoords(
      item.latitude !== null && item.longitude !== null
        ? { latitude: item.latitude, longitude: item.longitude }
        : null,
    );
  }

  // A pin chosen in the header is a destination too.
  useEffect(() => {
    if (pinned && !coords) {
      setCoords({ latitude: pinned.latitude, longitude: pinned.longitude });
    }
  }, [pinned, coords]);

  // Re-price whenever the basket or the destination changes. The request is
  // cancelled on the way out so a slow reply for an old basket cannot land
  // after a newer one and show the shopper stale figures.
  const cartKey = cart.lines.map((line) => `${line.product.id}x${line.quantity}`).join(",");

  useEffect(() => {
    if (!cart.ready || cart.lines.length === 0) {
      setPreview(null);
      return;
    }

    let cancelled = false;

    shop
      .checkoutPreview({
        items: cart.lines.map((line) => ({
          product_id: line.product.id,
          quantity: line.quantity,
        })),
        delivery_latitude: coords?.latitude ?? null,
        delivery_longitude: coords?.longitude ?? null,
      })
      .then((result) => {
        if (cancelled) return;
        setPreview(result);
        setPreviewFailed(false);
      })
      .catch(() => {
        if (cancelled) return;
        // Without the server's answer we do not know who to pay, so the page
        // says so rather than inventing a total or a number.
        setPreview(null);
        setPreviewFailed(true);
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cartKey, cart.ready, coords?.latitude, coords?.longitude, isAuthenticated]);

  const groups = preview?.vendor_groups ?? [];
  const blocked = groups.filter((group) => !group.can_accept_orders);

  // At least one seller can be paid. A basket also holding an unconfigured
  // store still checks out — that store's items simply stay behind.
  const canPlaceOrder = preview?.can_place_order === true;
  const deliveryFee = preview?.delivery.status === "quoted" ? preview.delivery.amount : null;

  // Exactly the products this order will cover, decided by the server.
  const payableIds = preview?.payable_product_ids ?? [];

  // The figures describe what is actually being ordered, not the whole basket.
  // Showing the full subtotal above a part-order would overstate what the
  // shopper is about to commit to.
  const orderSubtotal = preview?.payable_subtotal ?? cart.subtotal;
  const total = preview?.payable_total ?? cart.subtotal;

  if (cart.ready && cart.lines.length === 0) {
    return (
      <EmptyState
        title={t("checkout.nothingToCheckout")}
        message={t("checkout.cartEmpty")}
        action={<ButtonLink href="/" size="lg">{t("checkout.browseProducts")}</ButtonLink>}
      />
    );
  }

  if (ready && !isAuthenticated) {
    return (
      <EmptyState
        title={t("checkout.signInTitle")}
        message={t("checkout.signInHint")}
        action={<Button size="lg" onClick={() => void requireAuth()}>{t("checkout.signInAction")}</Button>}
      />
    );
  }

  async function placeOrder(event: React.FormEvent) {
    event.preventDefault();

    // Guard the action itself as well as the page: a session can expire while
    // the shopper is filling the form in.
    if (!(await requireAuth())) return;

    // Only what the server said can be paid for. Items from a seller with no
    // till stay in the basket rather than being ordered with nowhere to send
    // the money, or silently thrown away.
    const ordering = new Set(payableIds);
    const items = cart.lines
      .filter((line) => ordering.has(line.product.id))
      .map((line) => ({ product_id: line.product.id, quantity: line.quantity }));

    if (items.length === 0) {
      setError(t("checkout.nothingPayable"));
      return;
    }

    setPlacing(true);
    setError(null);

    try {
      const result = await shop.placeOrder({
        items,
        delivery_address: address.trim(),
        customer_phone: phone.trim(),
        payment_method: payment,
        delivery_latitude: coords?.latitude ?? null,
        delivery_longitude: coords?.longitude ?? null,
      });

      // Remove only what was ordered. cart.clear() would take the unpayable
      // items with it, and the shopper would lose them without being told.
      items.forEach((item) => cart.remove(item.product_id));

      router.push(`/account/orders?placed=${encodeURIComponent(result.reference)}`);
    } catch (err) {
      setError(apiError(err, t("checkout.failed")));
      setPlacing(false);
    }
  }

  return (
    <div className="shell py-4">
      <h1 className="mb-4 text-[22px] font-extrabold tracking-tight md:text-[26px]">{t("checkout.title")}</h1>

      <form onSubmit={placeOrder} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="space-y-3">
          <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-[15px] font-extrabold">{t("checkout.deliveryDetails")}</h2>
              <Link
                href="/account/addresses"
                className="text-[12px] font-bold text-[color:var(--color-action)] hover:underline"
              >
                {t("checkout.manageAddresses")}
              </Link>
            </div>

            {saved.length > 0 ? (
              <fieldset className="mb-3">
                <legend className="mb-2 text-[12px] font-bold text-[color:var(--color-ink-muted)]">
                  {t("checkout.deliverTo")}
                </legend>

                <div className="grid gap-2 sm:grid-cols-2">
                  {saved.map((item) => (
                    <label
                      key={item.id}
                      className={`flex cursor-pointer gap-2 rounded-[var(--radius-sm)] p-3 text-left ${
                        selectedId === item.id
                          ? "ring-2 ring-[color:var(--color-action)]"
                          : "ring-1 ring-[color:var(--color-line)]"
                      }`}
                    >
                      <input
                        type="radio"
                        name="saved-address"
                        className="mt-1 h-4 w-4 shrink-0 accent-[color:var(--color-action)]"
                        checked={selectedId === item.id}
                        onChange={() => chooseAddress(item)}
                      />
                      <span className="min-w-0">
                        <span className="flex flex-wrap items-center gap-1">
                          <span className="text-[13px] font-extrabold">{item.full_name}</span>
                          {item.is_default ? (
                            <span className="rounded-full bg-[color:var(--color-action-soft)] px-[6px] py-[1px] text-[9px] font-black uppercase text-[color:var(--color-action)]">
                              {t("checkout.default")}
                            </span>
                          ) : null}
                        </span>
                        <span className="clamp-2 block text-[12px] text-[color:var(--color-ink-muted)]">
                          {item.formatted}
                        </span>
                      </span>
                    </label>
                  ))}

                  <label
                    className={`flex cursor-pointer items-center gap-2 rounded-[var(--radius-sm)] p-3 ${
                      selectedId === null
                        ? "ring-2 ring-[color:var(--color-action)]"
                        : "ring-1 ring-[color:var(--color-line)]"
                    }`}
                  >
                    <input
                      type="radio"
                      name="saved-address"
                      className="h-4 w-4 shrink-0 accent-[color:var(--color-action)]"
                      checked={selectedId === null}
                      onChange={() => {
                        setSelectedId(null);
                        setAddress("");
                      }}
                    />
                    <span className="text-[13px] font-semibold">{t("checkout.deliverElsewhere")}</span>
                  </label>
                </div>
              </fieldset>
            ) : null}

            <div className="space-y-3">
              <Field
                label={t("checkout.deliveryAddress")}
                value={address}
                onChange={(event) => setAddress(event.target.value)}
                required
                placeholder={t("checkout.addressPlaceholder")}
                multiline
              />
              <button
                type="button"
                onClick={() => setMapOpen(true)}
                className="inline-flex items-center gap-2 rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] px-3 py-2 text-[13px] font-bold text-[color:var(--color-action)] hover:bg-[color:var(--color-action-soft)]"
              >
                <MapPinIcon className="h-4 w-4 shrink-0" />
                {address.trim() ? t("checkout.changeLocation") : t("checkout.pickOnMap")}
              </button>

              <Field
                label={t("checkout.phone")}
                value={phone}
                onChange={(event) => setPhone(event.target.value)}
                required
                inputMode="tel"
                placeholder={t("checkout.phonePlaceholder")}
              />
            </div>
          </section>

          <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
            <h2 className="mb-3 text-[15px] font-extrabold">{t("checkout.payment")}</h2>

            <div className="space-y-2">
              <PaymentOption
                checked
                title={t("payment.lipaNamba")}
                body={t("payment.payDirectly")}
              />

              {/* Shown so shoppers know it is coming, but not selectable —
                  there is no integration behind it yet. */}
              <PaymentOption
                unavailable
                title={t("payment.mobileMoney")}
                body={t("payment.mobileMoneyHint")}
                badge={t("payment.comingSoon")}
              >
                <div className="mt-2 flex flex-wrap gap-2">
                  {[t("payment.mpesa"), t("payment.tigo"), t("payment.airtel"), t("payment.halopesa")].map(
                    (option) => (
                      <span
                        key={option}
                        className="rounded-[var(--radius-sm)] border border-[color:var(--color-line)] px-2.5 py-1 text-[12px] font-semibold text-[color:var(--color-ink-faint)]"
                      >
                        {option}
                      </span>
                    ),
                  )}
                </div>
              </PaymentOption>
            </div>

            <p className="mt-3 text-[11px] leading-relaxed text-[color:var(--color-ink-muted)]">
              {t("payment.unavailableNote")}
            </p>
          </section>

          {/* ---- who to pay ----

              A D2K basket routinely spans several stores and each one has its
              own till, so this is a list rather than a single number. Merging
              them would be wrong in the most expensive way available: the
              shopper pays one seller for everybody's goods and the rest are
              never paid at all. Every number here comes from the server, for
              the seller it is printed under. */}
          {groups.length > 0 ? (
            <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
              <h2 className="mb-1 text-[15px] font-extrabold">{t("checkout.paymentInstructions")}</h2>
              <p className="mb-3 text-[12px] leading-relaxed text-[color:var(--color-ink-muted)]">
                {t("payment.instructions")}
              </p>

              <ul className="space-y-2">
                {groups.map((group) => (
                  <li
                    key={group.vendor_id ?? group.vendor_name}
                    className={`rounded-[var(--radius-sm)] border p-3 ${
                      group.can_accept_orders
                        ? "border-[color:var(--color-line-strong)]"
                        : "border-[color:var(--color-sale)] bg-red-50"
                    }`}
                  >
                    <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                      <span className="min-w-0 text-[11px] font-bold uppercase tracking-wide text-[color:var(--color-ink-muted)]">
                        {t("payment.payTo")}
                      </span>
                      <span className="text-[13px] font-bold">{formatMoney(group.subtotal)}</span>
                    </div>

                    <p className="clamp-2 text-[14px] font-extrabold">{group.vendor_name}</p>

                    {group.can_accept_orders && group.lipa_namba ? (
                      <div className="mt-2">
                        <span className="block text-[11px] font-bold uppercase tracking-wide text-[color:var(--color-ink-muted)]">
                          {t("payment.lipaNamba")}
                        </span>

                        {/* The number is the single thing on this page the
                            shopper has to get into their phone's dialler
                            correctly, so it is the largest thing on the card
                            and it can be copied rather than transcribed.

                            break-all, not nowrap: a twelve-digit till has to
                            wrap inside a 320px column rather than push the
                            page sideways. min-w-0 lets it. */}
                        <div className="mt-1 flex items-center gap-2">
                          <span className="min-w-0 flex-1 break-all font-mono text-[22px] font-black leading-tight tracking-wide">
                            {group.lipa_namba_display ?? group.lipa_namba}
                          </span>
                          <CopyButton value={group.lipa_namba} />
                        </div>
                      </div>
                    ) : (
                      <div className="mt-2">
                        <p className="text-[12px] font-bold text-[color:var(--color-sale)]">
                          {t("payment.notSetUp")}
                        </p>
                        <p className="mt-1 text-[11px] leading-relaxed text-[color:var(--color-ink-muted)]">
                          {t("payment.notSetUpHint")}
                        </p>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
            <h2 className="mb-3 text-[15px] font-extrabold">
              {t("checkout.yourItems", { count: cart.count })}
            </h2>
            <ul className="divide-y divide-[color:var(--color-line)]">
              {cart.lines.map(({ product, quantity }) => (
                <li key={product.id} className="flex items-center gap-3 py-2.5">
                  <span className="h-14 w-14 shrink-0 overflow-hidden rounded-[var(--radius-sm)] bg-white ring-1 ring-[color:var(--color-line)]">
                    {product.image ? (
                      <img src={product.image} alt="" loading="lazy" className="h-full w-full object-contain p-1" />
                    ) : null}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="clamp-1 block text-[13px] font-semibold">{product.name}</span>
                    <span className="text-[11px] text-[color:var(--color-ink-muted)]">{t("cart.quantity")} {quantity}</span>
                  </span>
                  <span className="shrink-0 text-[14px] font-bold">
                    {formatMoney(product.price.current * quantity)}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        </div>

        <aside className="lg:sticky lg:top-[calc(var(--header-height)+16px)] lg:self-start">
          <div className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
            <h2 className="mb-3 text-[15px] font-extrabold">{t("checkout.summary")}</h2>

            <dl className="space-y-2 text-[13px]">
              <div className="flex justify-between gap-3">
                <dt className="text-[color:var(--color-ink-muted)]">{t("cart.subtotal")}</dt>
                <dd className="font-semibold">{formatMoney(orderSubtotal)}</dd>
              </div>
              <div className="flex justify-between gap-3">
                <dt className="min-w-0 text-[color:var(--color-ink-muted)]">{t("cart.delivery")}</dt>
                {/* There is no flat charge any more. Until the fee has actually
                    been worked out from the distance, this says so — printing
                    a placeholder figure only commits the shopper to a total the
                    rider would then have to argue with. */}
                <dd className="shrink-0 text-right font-semibold">
                  {deliveryFee !== null
                    ? formatMoney(deliveryFee)
                    : (
                      <span className="text-[12px] font-bold text-[color:var(--color-ink-muted)]">
                        {t("cart.deliveryPending")}
                      </span>
                    )}
                </dd>
              </div>
            </dl>

            {deliveryFee === null ? (
              <p className="mt-2 text-[11px] leading-relaxed text-[color:var(--color-ink-muted)]">
                {t("checkout.deliveryPendingNote")}
              </p>
            ) : null}

            <div className="mt-3 flex items-baseline justify-between gap-3 border-t border-[color:var(--color-line)] pt-3">
              <span className="text-[15px] font-extrabold">{t("cart.total")}</span>
              <span className="text-[20px] font-black">{formatMoney(total)}</span>
            </div>

            {previewFailed ? (
              <p
                role="alert"
                className="mt-3 rounded-[var(--radius-sm)] bg-red-50 px-3 py-2 text-[13px] text-[color:var(--color-sale)]"
              >
                {t("checkout.pricingUnavailable")}
              </p>
            ) : null}

            {/* A notice, not a blocker. These items are staying in the
                basket; everything else is still ordered. */}
            {blocked.length > 0 ? (
              <div
                role="status"
                className="mt-3 rounded-[var(--radius-sm)] border border-[color:var(--color-warn)] bg-[color:var(--color-warn-soft)] px-3 py-2"
              >
                <p className="text-[12px] font-extrabold text-[color:var(--color-warn)]">
                  {t("checkout.blockedTitle")}
                </p>
                <ul className="mt-1 space-y-1">
                  {blocked.map((group) => (
                    <li
                      key={group.vendor_id ?? group.vendor_name}
                      className="text-[11px] leading-relaxed text-[color:var(--color-ink-muted)]"
                    >
                      {t("payment.partialBlocked", { vendor: group.vendor_name })}{" "}
                      {t("payment.notSetUpHint")}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}

            {error ? (
              <p role="alert" className="mt-3 rounded-[var(--radius-sm)] bg-red-50 px-3 py-2 text-[13px] text-[color:var(--color-sale)]">
                {error}
              </p>
            ) : null}

            <Button
              type="submit"
              size="lg"
              className="mt-4 w-full"
              // Held until the server has confirmed every seller in the basket
              // can actually be paid. Letting it through would take an order
              // whose money has nowhere to go.
              disabled={placing || !canPlaceOrder}
            >
              {placing ? t("checkout.placing") : t("checkout.placeOrder")}
            </Button>

            <p className="mt-2 text-center text-[11px] text-[color:var(--color-ink-faint)]">
              {t("checkout.terms", { brand: BRAND.name })}
            </p>
          </div>
        </aside>
      </form>

      <LocationPicker
        open={mapOpen}
        onClose={() => setMapOpen(false)}
        onConfirm={(picked) => {
          // Writing to the shared store as well keeps the header's "Deliver to"
          // and the checkout address from drifting apart.
          setLocation(picked);
          setAddress(picked.formatted);
          setSelectedId(null);
          // The pin is what delivery is priced from, so it goes to the server
          // on the next preview rather than only into the address line.
          setCoords({ latitude: picked.latitude, longitude: picked.longitude });
        }}
      />
    </div>
  );
}

/**
 * Copies a till number to the clipboard.
 *
 * Worth a control of its own because the alternative is a shopper reading a
 * nine-digit number off one app and typing it into another, which is exactly
 * the moment a payment goes to the wrong till.
 *
 * Copies the raw digits rather than the spaced display form — a dialler will
 * not accept the spaces. Falls back to selecting nothing and saying nothing if
 * the Clipboard API is unavailable or refused: the number is still on screen
 * and readable, so a failed copy is not worth an error message.
 */
function CopyButton({ value }: { value: string }) {
  const t = useT();
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;
    const timer = window.setTimeout(() => setCopied(false), 2000);
    return () => window.clearTimeout(timer);
  }, [copied]);

  return (
    <button
      type="button"
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(value);
          setCopied(true);
        } catch {
          /* nothing to do — the number is on screen either way */
        }
      }}
      // h-11 and shrink-0: a thumb target that survives a 320px column.
      className="flex h-11 shrink-0 items-center gap-1.5 rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] px-3 text-[12px] font-bold transition-colors hover:bg-[color:var(--color-surface-alt)]"
      aria-live="polite"
    >
      {copied ? (
        <>
          <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M20 6L9 17l-5-5" />
          </svg>
          {t("payment.copied")}
        </>
      ) : (
        <>
          <svg viewBox="0 0 24 24" className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="9" y="9" width="11" height="11" rx="2" />
            <path d="M5 15V5a2 2 0 0 1 2-2h10" />
          </svg>
          {t("payment.copy")}
        </>
      )}
    </button>
  );
}

/**
 * A payment method row.
 *
 * `unavailable` renders the method as visibly present but plainly inactive —
 * dimmed, not selectable, and labelled with why. The requirement was that
 * shoppers can see what is coming without the UI implying they can pay with it.
 */
function PaymentOption({
  checked = false,
  onSelect,
  title,
  body,
  badge,
  unavailable = false,
  children,
}: {
  checked?: boolean;
  onSelect?(): void;
  title: string;
  body: string;
  badge?: string;
  unavailable?: boolean;
  children?: React.ReactNode;
}) {
  return (
    <label
      aria-disabled={unavailable}
      className={`flex gap-3 rounded-[var(--radius-sm)] border p-3 transition-colors ${
        unavailable
          ? "cursor-not-allowed border-[color:var(--color-line)] bg-[color:var(--color-surface-alt)]"
          : checked
            ? "cursor-pointer border-[color:var(--color-action)] bg-[color:var(--color-action-soft)]"
            : "cursor-pointer border-[color:var(--color-line-strong)] hover:border-[color:var(--color-ink-faint)]"
      }`}
    >
      <input
        type="radio"
        name="payment"
        checked={checked}
        disabled={unavailable}
        onChange={onSelect}
        className="mt-0.5 h-4 w-4 shrink-0 accent-[color:var(--color-action)]"
      />
      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-2">
          <span
            className={`text-[14px] font-bold ${
              unavailable ? "text-[color:var(--color-ink-muted)]" : ""
            }`}
          >
            {title}
          </span>
          {badge ? (
            <span className="rounded-full bg-[color:var(--color-warn-soft)] px-2 py-[2px] text-[10px] font-black uppercase tracking-wide text-[color:var(--color-warn)]">
              {badge}
            </span>
          ) : null}
        </span>
        <span className="block text-[12px] text-[color:var(--color-ink-muted)]">{body}</span>
        {children}
      </span>
    </label>
  );
}

function Field({
  label,
  multiline,
  ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { label: string; multiline?: boolean }) {
  const className =
    "w-full rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] px-3 py-2.5 text-sm outline-none transition-colors focus:border-[color:var(--color-action)]";

  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-semibold text-[color:var(--color-ink-muted)]">
        {label}
      </span>
      {multiline ? (
        <textarea
          {...(props as unknown as React.TextareaHTMLAttributes<HTMLTextAreaElement>)}
          rows={3}
          className={`${className} resize-y`}
        />
      ) : (
        <input {...props} className={`${className} h-11`} />
      )}
    </label>
  );
}

function MapPinIcon({ className = "" }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth="2"
      strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 21s7-5.6 7-11a7 7 0 10-14 0c0 5.4 7 11 7 11z" />
      <circle cx="12" cy="10" r="2.6" />
    </svg>
  );
}
