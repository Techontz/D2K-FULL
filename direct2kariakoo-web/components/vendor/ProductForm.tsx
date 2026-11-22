"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiError } from "@/lib/api";
import shop from "@/lib/shop";
import vendorApi from "@/lib/vendor";
import type { Category, ProductDetail } from "@/lib/types";
import { Button } from "@/components/ui/Primitives";
import { useT } from "@/lib/i18n";
import { ProductImagePicker, type PickedImage } from "./ProductImagePicker";
import type { AttributeValue, SellerAttribute } from "@/lib/vendor";

/**
 * Shared create/edit form for a vendor's product.
 *
 * On edit, newly chosen photos are *added* to the gallery. Existing photos are
 * only removed when the seller ticks the explicit "replace" option — losing a
 * product's photography by accident is not an acceptable outcome.
 */
export function ProductForm({ product }: { product?: ProductDetail }) {
  const router = useRouter();
  const editing = Boolean(product);

  const [categories, setCategories] = useState<Category[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const t = useT();

  const [form, setForm] = useState({
    name: product?.name ?? "",
    short_description: product?.short_description ?? "",
    category_id: product?.category?.id ?? 0,
    subcategory_id: product?.subcategory?.id ?? 0,
    description: product?.description ?? "",
    new_price: product?.price.current ?? 0,
    old_price: product?.price.was ?? 0,
    stock: product?.stock ?? 0,
  });

  const [images, setImages] = useState<PickedImage[]>([]);
  const [replaceExisting, setReplaceExisting] = useState(false);

  // Admin-defined properties for the chosen category, and the seller's values.
  const [attributes, setAttributes] = useState<SellerAttribute[]>([]);
  const [attributeValues, setAttributeValues] = useState<Record<number, AttributeValue>>({});
  // Variant attributes the seller has interacted with. Only those must end up
  // with at least one value; an untouched one is simply not offered.
  const [touchedVariants, setTouchedVariants] = useState<Set<number>>(() => new Set());

  useEffect(() => {
    shop.categories().then(setCategories).catch(() => setCategories([]));
  }, []);

  // Re-fetch whenever the category changes: a fashion seller should not be
  // asked for RAM, and the option lists differ per category.
  useEffect(() => {
    if (!form.category_id) { setAttributes([]); return; }
    let cancelled = false;
    vendorApi
      .attributes(form.category_id)
      .then((list) => {
        if (cancelled) return;
        setAttributes(list);
        // On edit, start from what the product already has. Values the seller
        // has already changed in this session are left alone.
        if (product) {
          setAttributeValues((current) => {
            const next = { ...current };
            for (const attribute of list) {
              if (next[attribute.id] !== undefined) continue;
              const stored = storedValue(product, attribute);
              if (stored !== undefined) next[attribute.id] = stored;
            }
            return next;
          });
        }
      })
      .catch(() => { if (!cancelled) setAttributes([]); });
    return () => { cancelled = true; };
  }, [form.category_id, product]);

  const subcategories =
    categories.find((category) => category.id === form.category_id)?.subcategories ?? [];

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);

    if (!editing && images.length === 0) {
      setError(t("productForm.noPhotos"));
      return;
    }

    if (!form.category_id) {
      setError(t("productForm.selectCategory"));
      return;
    }

    const emptyVariant = attributes.find(
      (attribute) =>
        attribute.is_variant &&
        touchedVariants.has(attribute.id) &&
        asList(attributeValues[attribute.id]).length === 0,
    );
    if (emptyVariant) {
      setError(t("productForm.variantRequired", { name: emptyVariant.name }));
      return;
    }

    // Only attributes of the current category are sent, so switching
    // category does not carry over values that no longer apply.
    const attributesToSend: Record<number, AttributeValue> = {};
    for (const attribute of attributes) {
      const value = attributeValues[attribute.id];
      if (value === undefined) continue;
      attributesToSend[attribute.id] = attribute.is_variant ? asList(value) : asText(value);
    }

    setSaving(true);

    try {
      if (editing && product) {
        await vendorApi.updateProduct(product.id, {
          name: form.name,
          category_id: form.category_id,
          subcategory_id: form.subcategory_id || undefined,
          description: form.description,
          new_price: form.new_price,
          old_price: form.old_price || undefined,
          stock: form.stock,
          images: images.length ? images.map((image) => image.file) : undefined,
          remove_images: replaceExisting,
          // The backend rewrites every attribute on update, so the full set
          // is always sent — omitting it used to wipe the product's specs.
          attributes: attributesToSend,
        });
      } else {
        await vendorApi.createProduct({
          name: form.name,
          category_id: form.category_id,
          subcategory_id: form.subcategory_id || undefined,
          short_description: form.short_description || undefined,
          description: form.description,
          new_price: form.new_price,
          old_price: form.old_price || undefined,
          stock: form.stock,
          images: images.map((image) => image.file),
          attributes: attributesToSend,
        });
      }

      router.push("/vendor/products");
    } catch (err) {
      setError(apiError(err, t("common.somethingWrong")));
      setSaving(false);
    }
  }

  function update<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  return (
    <form onSubmit={submit} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
      <div className="space-y-3">
        <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
          <h2 className="mb-3 text-[15px] font-extrabold">{t("productForm.basics")}</h2>

          <div className="space-y-3">
            <Field label={t("productForm.name")} required value={form.name}
              onChange={(event) => update("name", event.target.value)}
              placeholder="e.g. Samsung Galaxy A55 128GB" maxLength={100} />

            <div>
              <Field
                label={t("productForm.shortDescription")}
                value={form.short_description}
                onChange={(event) => update("short_description", event.target.value)}
                maxLength={300}
              />
              <p className="mt-1 text-[11px] text-[color:var(--color-ink-faint)]">
                {t("productForm.shortHint")}
              </p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
              <SelectField
                label={t("productForm.category")}
                required
                value={form.category_id}
                onChange={(event) => {
                  update("category_id", Number(event.target.value));
                  // The old subcategory belongs to the previous category, so
                  // clear it rather than submit a mismatched pair.
                  update("subcategory_id", 0);
                }}
              >
                <option value={0}>Choose a category</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>{category.name.trim()}</option>
                ))}
              </SelectField>

              <SelectField
                label={t("productForm.subcategory")}
                value={form.subcategory_id}
                disabled={subcategories.length === 0}
                onChange={(event) => update("subcategory_id", Number(event.target.value))}
              >
                <option value={0}>
                  {subcategories.length === 0 ? "No subcategories" : "Optional"}
                </option>
                {subcategories.map((sub) => (
                  <option key={sub.id} value={sub.id}>{sub.name}</option>
                ))}
              </SelectField>
            </div>

            <label className="block">
              <span className="mb-1 block text-[12px] font-semibold text-[color:var(--color-ink-muted)]">
                Description
              </span>
              <textarea
                value={form.description}
                onChange={(event) => update("description", event.target.value)}
                rows={5}
                placeholder="Size, colour, condition, what's included…"
                className="w-full resize-y rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] px-3 py-2.5 text-sm outline-none focus:border-[color:var(--color-action)]"
              />
            </label>
          </div>
        </section>

        <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
          <h2 className="mb-3 text-[15px] font-extrabold">{t("productForm.media")}</h2>

          {editing && product && product.images.length > 0 ? (
            <div className="mb-3">
              <p className="mb-2 text-[12px] text-[color:var(--color-ink-muted)]">
                Current photos ({product.images.length})
              </p>
              <div className="rail gap-2">
                {product.images.map((image) => (
                  <span key={image} className="h-16 w-16 shrink-0 overflow-hidden rounded-[var(--radius-sm)] bg-white ring-1 ring-[color:var(--color-line)]">
                    <img src={image} alt="" loading="lazy" className="h-full w-full object-contain p-1" />
                  </span>
                ))}
              </div>

              <label className="mt-3 flex cursor-pointer items-start gap-2 rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] p-2.5 text-[12px]">
                <input
                  type="checkbox"
                  checked={replaceExisting}
                  onChange={(event) => setReplaceExisting(event.target.checked)}
                  className="mt-0.5 h-4 w-4 accent-[color:var(--color-sale)]"
                />
                <span>
                  <span className="block font-semibold">Replace all current photos</span>
                  <span className="text-[color:var(--color-ink-muted)]">
                    Leave unticked and new photos are added alongside the existing ones.
                  </span>
                </span>
              </label>
            </div>
          ) : null}

          <ProductImagePicker images={images} onChange={setImages} />

        </section>

        {/* Structured properties, kept out of the description so the
            catalogue can be filtered on them. Only rendered once a category
            is chosen, because the list depends on it. */}
        {attributes.length > 0 ? (
          <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
            <h2 className="text-[15px] font-extrabold">{t("productForm.attributes")}</h2>
            <p className="mb-3 text-[11px] text-[color:var(--color-ink-muted)]">
              {t("productForm.attributesHint")}
            </p>

            <div className="grid gap-3 sm:grid-cols-2">
              {attributes.map((attribute) =>
                attribute.is_variant && attribute.options.length > 0 ? (
                  // The seller ticks every value the product comes in; the
                  // customer then picks one of them.
                  <fieldset key={attribute.id} className="block sm:col-span-2">
                    <legend className="mb-1 block text-[12px] font-semibold text-[color:var(--color-ink-muted)]">
                      {attribute.name}
                      {attribute.unit ? ` (${attribute.unit})` : ""}
                    </legend>
                    <div className="flex flex-wrap gap-2">
                      {attribute.options.map((option) => {
                        const chosen = asList(attributeValues[attribute.id]);
                        const active = chosen.includes(option);
                        return (
                          <button
                            key={option}
                            type="button"
                            aria-pressed={active}
                            onClick={() => {
                              setTouchedVariants((current) => new Set(current).add(attribute.id));
                              setAttributeValues((current) => {
                                const list = asList(current[attribute.id]);
                                return {
                                  ...current,
                                  [attribute.id]: active
                                    ? list.filter((value) => value !== option)
                                    // Kept in the administrator's order, not click order.
                                    : attribute.options.filter((value) => value === option || list.includes(value)),
                                };
                              });
                            }}
                            className={`min-h-[36px] min-w-[44px] rounded-[var(--radius-pill)] border px-3.5 text-[13px] font-bold transition-colors ${
                              active
                                ? "border-[color:var(--color-action)] bg-[color:var(--color-action)] text-white"
                                : "border-[color:var(--color-line-strong)] bg-[color:var(--color-surface)] text-[color:var(--color-ink)] hover:border-[color:var(--color-action)]"
                            }`}
                          >
                            {active ? "✓ " : ""}{option}
                          </button>
                        );
                      })}
                    </div>
                    <span className="mt-1 block text-[11px] font-semibold text-[color:var(--color-action)]">
                      {t("productForm.variantHint")}
                    </span>
                  </fieldset>
                ) : (
                <label key={attribute.id} className="block">
                  <span className="mb-1 block text-[12px] font-semibold text-[color:var(--color-ink-muted)]">
                    {attribute.name}
                    {attribute.unit ? ` (${attribute.unit})` : ""}
                  </span>

                  {attribute.options.length > 0 ? (
                    <select
                      value={asText(attributeValues[attribute.id])}
                      onChange={(event) =>
                        setAttributeValues((current) => ({ ...current, [attribute.id]: event.target.value }))
                      }
                      className="h-11 w-full rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] bg-white px-3 text-sm outline-none focus:border-[color:var(--color-action)]"
                    >
                      <option value="">—</option>
                      {attribute.options.map((option) => (
                        <option key={option} value={option}>{option}</option>
                      ))}
                      {/* A stored value no longer in the list is kept, not silently dropped. */}
                      {asText(attributeValues[attribute.id]) &&
                      !attribute.options.includes(asText(attributeValues[attribute.id])) ? (
                        <option value={asText(attributeValues[attribute.id])}>
                          {asText(attributeValues[attribute.id])}
                        </option>
                      ) : null}
                    </select>
                  ) : (
                    <input
                      type={attribute.type === "number" ? "number" : "text"}
                      value={asText(attributeValues[attribute.id])}
                      onChange={(event) =>
                        setAttributeValues((current) => ({ ...current, [attribute.id]: event.target.value }))
                      }
                      maxLength={255}
                      className="h-11 w-full rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] px-3 text-sm outline-none focus:border-[color:var(--color-action)]"
                    />
                  )}

                  {attribute.is_variant ? (
                    <span className="mt-1 block text-[11px] font-semibold text-[color:var(--color-action)]">
                      {t("productForm.variantHint")}
                    </span>
                  ) : null}
                </label>
                ),
              )}
            </div>
          </section>
        ) : null}
      </div>

      <aside className="space-y-3 lg:sticky lg:top-4 lg:self-start">
        <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
          <h2 className="mb-3 text-[15px] font-extrabold">{t("productForm.pricing")}</h2>

          <div className="space-y-3">
            <Field label={t("productForm.price")} required type="number" min={0} step={1}
              value={form.new_price || ""}
              onChange={(event) => update("new_price", Number(event.target.value))} />

            <Field label={t("productForm.wasPrice")} type="number" min={0} step={1}
              value={form.old_price || ""}
              onChange={(event) => update("old_price", Number(event.target.value))} />

            <Field label={t("productForm.stock")} required type="number" min={0}
              value={form.stock}
              onChange={(event) => update("stock", Number(event.target.value))} />
          </div>

          {form.old_price > 0 && form.new_price > 0 && form.old_price > form.new_price ? (
            <p className="mt-3 rounded-[var(--radius-sm)] bg-[color:var(--color-success-soft)] px-3 py-2 text-[12px] font-semibold text-[color:var(--color-success)]">
              Shoppers see {Math.round(((form.old_price - form.new_price) / form.old_price) * 100)}% off
            </p>
          ) : null}
        </section>

        <section className="rounded-[var(--radius-md)] bg-[color:var(--color-surface)] p-4">
          {error ? (
            <p role="alert" className="mb-3 rounded-[var(--radius-sm)] bg-red-50 px-3 py-2 text-[13px] text-[color:var(--color-sale)]">
              {error}
            </p>
          ) : null}

          <Button type="submit" size="lg" className="w-full" disabled={saving}>
            {saving ? "Saving…" : editing ? "Save changes" : "Publish product"}
          </Button>

          <Button
            type="button"
            variant="ghost"
            className="mt-2 w-full"
            onClick={() => router.push("/vendor/products")}
          >
            Cancel
          </Button>
        </section>
      </aside>
    </form>
  );
}

function Field({
  label,
  ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-semibold text-[color:var(--color-ink-muted)]">
        {label}
      </span>
      <input
        {...props}
        className="h-11 w-full rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] px-3 text-sm outline-none focus:border-[color:var(--color-action)]"
      />
    </label>
  );
}

function SelectField({
  label,
  children,
  ...props
}: React.SelectHTMLAttributes<HTMLSelectElement> & { label: string }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[12px] font-semibold text-[color:var(--color-ink-muted)]">
        {label}
      </span>
      <select
        {...props}
        className="h-11 w-full rounded-[var(--radius-sm)] border border-[color:var(--color-line-strong)] bg-white px-2 text-sm outline-none focus:border-[color:var(--color-action)] disabled:opacity-50"
      >
        {children}
      </select>
    </label>
  );
}

export default ProductForm;

/* ---- attribute value helpers ---- */

/** "M, L" / '["M","L"]' / ["M","L"] → ["M","L"]. Mirrors ProductOptions::splitValues. */
function splitStored(raw: unknown): string[] {
  if (Array.isArray(raw)) return raw.map((v) => String(v).trim()).filter(Boolean);
  const text = typeof raw === "string" ? raw.trim() : "";
  if (!text) return [];
  if (text.startsWith("[")) {
    try {
      const decoded: unknown = JSON.parse(text);
      if (Array.isArray(decoded)) return splitStored(decoded);
    } catch {
      /* not JSON — fall through to the separators */
    }
  }
  return text.split(/[,;|\r\n]+/).map((v) => v.trim()).filter(Boolean);
}

function asList(value: AttributeValue | undefined): string[] {
  return splitStored(value ?? "");
}

function asText(value: AttributeValue | undefined): string {
  return Array.isArray(value) ? value.join(", ") : value ?? "";
}

/**
 * What the product being edited already holds for an attribute.
 *
 * Variant attributes come back as `options[]` (an array, matched by id); for
 * older data, or a variant attribute the API still lists as a specification,
 * the comma-separated spec value is split instead. Plain attributes come from
 * the specification with the same label.
 */
function storedValue(product: ProductDetail, attribute: SellerAttribute): AttributeValue | undefined {
  const option = product.options?.find((item) => item.id === attribute.id);
  const spec = product.specifications.find((item) => item.label === attribute.name);

  if (attribute.is_variant) {
    if (option) return splitStored(option.values);
    if (spec) return splitStored(spec.value);
    return undefined;
  }

  if (spec) return spec.value;
  if (option) return option.values.join(", ");
  return undefined;
}
