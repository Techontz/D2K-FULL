"use client";

import type { ProductOption, SelectedOptions } from "@/lib/types";
import { useT } from "@/lib/i18n";

/** DOM id of an option's card, so the page can scroll a missing choice into view. */
export function optionCardId(option: ProductOption): string {
  return `pdp-option-${option.id}`;
}

/** The first option the shopper has not chosen yet, or null when all are set. */
export function firstMissingOption(
  options: ProductOption[],
  selected: SelectedOptions,
): ProductOption | null {
  return options.find((option) => !selected[option.name]) ?? null;
}

/**
 * Size / colour pickers on the product page.
 *
 * One light-grey card per option with its values as pill chips. Nothing is
 * pre-selected: a default size is a guess, and a wrong guess is a return.
 * `missing` names the option the shopper tried to skip; `flashKey` changes on
 * every failed attempt so the highlight replays even for the same option.
 */
export function ProductOptions({
  options,
  selected,
  onSelect,
  missing,
  flashKey = 0,
}: {
  options: ProductOption[];
  selected: SelectedOptions;
  onSelect: (name: string, value: string) => void;
  missing: string | null;
  flashKey?: number;
}) {
  const t = useT();

  if (options.length === 0) return null;

  return (
    <div className="mt-4 space-y-3">
      {options.map((option) => {
        const isMissing = missing === option.name;
        const chosen = selected[option.name] ?? null;

        return (
          <fieldset
            // Re-keyed on each failed attempt so the flash animation restarts.
            key={isMissing ? `${option.id}-${flashKey}` : option.id}
            id={optionCardId(option)}
            aria-invalid={isMissing || undefined}
            aria-describedby={isMissing ? `${optionCardId(option)}-error` : undefined}
            className={`scroll-mt-[calc(var(--header-height,64px)+16px)] rounded-[var(--radius-md)] bg-[color:var(--color-canvas)] p-3 transition-shadow ${
              isMissing
                ? "ring-2 ring-[color:var(--color-sale)] animate-[pulse_0.5s_ease-in-out_2]"
                : ""
            }`}
          >
            <legend className="float-left mb-2 w-full text-[14px] font-extrabold">
              {option.name}
              {chosen ? (
                <span className="ml-1.5 font-semibold text-[color:var(--color-ink-muted)]">: {chosen}</span>
              ) : null}
            </legend>

            <div className="clear-both flex flex-wrap gap-2" role="radiogroup" aria-label={option.name}>
              {option.values.map((value) => {
                const active = chosen === value;
                return (
                  <button
                    key={value}
                    type="button"
                    role="radio"
                    aria-checked={active}
                    onClick={() => onSelect(option.name, value)}
                    className={`min-h-[38px] min-w-[48px] rounded-[var(--radius-pill)] border px-4 text-[13px] font-bold transition-colors ${
                      active
                        ? "border-[color:var(--color-action)] bg-[color:var(--color-action)] text-white"
                        : "border-[color:var(--color-line-strong)] bg-[color:var(--color-surface)] text-[color:var(--color-ink)] hover:border-[color:var(--color-action)]"
                    }`}
                  >
                    {value}
                  </button>
                );
              })}
            </div>

            {isMissing ? (
              <p
                id={`${optionCardId(option)}-error`}
                role="alert"
                className="mt-2 text-[12px] font-bold text-[color:var(--color-sale)]"
              >
                {t("options.choose", { name: option.name })}
              </p>
            ) : null}
          </fieldset>
        );
      })}
    </div>
  );
}
