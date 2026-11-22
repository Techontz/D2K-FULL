"use client";

import { useEffect, useMemo, useState } from "react";
import shop from "@/lib/shop";
import { formatMoney } from "@/lib/format";
import type { Campus } from "@/lib/types";
import { useT } from "@/lib/i18n";

/** The active campus list, loaded once per page session. */
export function useCampuses() {
  const [campuses, setCampuses] = useState<Campus[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setFailed(false);

    shop
      .campuses()
      .then((list) => {
        if (!cancelled) setCampuses(list);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
    };
  }, [attempt]);

  return { campuses, failed, retry: () => setAttempt((n) => n + 1) };
}

/** "UDSM Mlimani" where there is a short name, the full name otherwise. */
export function campusLabel(campus: { name: string; short_name: string | null }): string {
  return campus.short_name || campus.name;
}

/**
 * Searchable campus list.
 *
 * A plain `<select>` of two dozen long institution names is unusable on a
 * phone, so this is a filter box over a short scrolling list. Matching covers
 * the full name, the short name and the area, so "ubungo", "udsm" and
 * "mlimani" all find the same campus.
 */
export function CampusPicker({
  value,
  onChange,
  error,
}: {
  value: number | null;
  onChange: (campus: Campus) => void;
  error?: string;
}) {
  const t = useT();
  const { campuses, failed, retry } = useCampuses();
  const [query, setQuery] = useState("");

  const filtered = useMemo(() => {
    if (!campuses) return [];
    const needle = query.trim().toLowerCase();
    if (!needle) return campuses;
    return campuses.filter((campus) =>
      [campus.name, campus.short_name, campus.area]
        .filter(Boolean)
        .some((text) => (text as string).toLowerCase().includes(needle)),
    );
  }, [campuses, query]);

  return (
    <div className="block">
      <span className="mb-1 block text-[12px] font-bold text-[color:var(--color-ink-muted)]">
        {t("campus.choose")}
      </span>

      <div
        className={`overflow-hidden rounded-[var(--radius-sm)] border bg-[color:var(--color-surface)] ${
          error ? "border-[color:var(--color-sale)]" : "border-[color:var(--color-line-strong)]"
        }`}
      >
        <input
          type="search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder={t("campus.search")}
          aria-label={t("campus.search")}
          className="h-11 w-full border-b border-[color:var(--color-line)] bg-transparent px-3 text-sm outline-none"
        />

        <div role="radiogroup" aria-label={t("campus.choose")} className="max-h-60 overflow-y-auto">
          {failed ? (
            <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-3 text-[13px] text-[color:var(--color-sale)]">
              <span>{t("campus.loadFailed")}</span>
              <button
                type="button"
                onClick={retry}
                className="font-bold text-[color:var(--color-action)] hover:underline"
              >
                {t("common.retry")}
              </button>
            </div>
          ) : campuses === null ? (
            <p className="px-3 py-3 text-[13px] text-[color:var(--color-ink-muted)]">{t("campus.loading")}</p>
          ) : filtered.length === 0 ? (
            <p className="px-3 py-3 text-[13px] text-[color:var(--color-ink-muted)]">
              {t("campus.noResults", { query: query.trim() })}
            </p>
          ) : (
            filtered.map((campus) => {
              const active = campus.id === value;
              return (
                <button
                  key={campus.id}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  onClick={() => onChange(campus)}
                  className={`flex w-full items-start gap-2 border-b border-[color:var(--color-line)] px-3 py-2.5 text-left last:border-0 ${
                    active ? "bg-[color:var(--color-action-soft)]" : "hover:bg-[color:var(--color-surface-alt)]"
                  }`}
                >
                  <span
                    aria-hidden="true"
                    className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-2 ${
                      active ? "border-[color:var(--color-action)]" : "border-[color:var(--color-line-strong)]"
                    }`}
                  >
                    {active ? <span className="h-2 w-2 rounded-full bg-[color:var(--color-action)]" /> : null}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-semibold leading-snug">{campus.name}</span>
                    {campus.area || campus.delivery_fee !== null ? (
                      <span className="block text-[11px] text-[color:var(--color-ink-muted)]">
                        {[campus.area, campus.delivery_fee !== null ? t("campus.fee", { amount: formatMoney(campus.delivery_fee) }) : null]
                          .filter(Boolean)
                          .join(" · ")}
                      </span>
                    ) : null}
                  </span>
                </button>
              );
            })
          )}
        </div>
      </div>

      {error ? (
        <span className="mt-1 block text-[11px] font-semibold text-[color:var(--color-sale)]">{error}</span>
      ) : null}
    </div>
  );
}

/**
 * "Home or other address" / "Deliver to my campus" — the choice that decides
 * which address fields are shown. Two big tap targets rather than a checkbox,
 * because on a phone this is the first decision on the form.
 */
export function DeliveryKindToggle({
  campus,
  onChange,
}: {
  campus: boolean;
  onChange: (campus: boolean) => void;
}) {
  const t = useT();

  const option = (active: boolean, label: string, hint: string | null, next: boolean) => (
    <button
      type="button"
      role="radio"
      aria-checked={active}
      onClick={() => onChange(next)}
      className={`min-w-0 flex-1 rounded-[var(--radius-sm)] px-3 py-2.5 text-left ${
        active
          ? "bg-[color:var(--color-action-soft)] ring-2 ring-[color:var(--color-action)]"
          : "ring-1 ring-[color:var(--color-line)] hover:bg-[color:var(--color-surface-alt)]"
      }`}
    >
      <span className="block text-[13px] font-extrabold">{label}</span>
      {hint ? <span className="block text-[11px] text-[color:var(--color-ink-muted)]">{hint}</span> : null}
    </button>
  );

  return (
    <div role="radiogroup" className="flex gap-2">
      {option(!campus, t("campus.otherAddress"), null, false)}
      {option(campus, `🎓 ${t("campus.deliverToCampus")}`, t("campus.deliverToCampusHint"), true)}
    </div>
  );
}
