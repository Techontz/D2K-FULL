<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Customer-chosen product options ("Size", "Colour") and their wire format.
 *
 * An attribute flagged `is_variant` is a choice the customer makes. The values
 * a product offers are the product's own value for that attribute, stored the
 * way every attribute value is stored: one `product_attribute_values` row per
 * product and attribute, whose `value` holds the seller's selection. A
 * multi-value selection is a comma-separated list ("M,L,XL"), so it is split
 * here into a list of strings.
 *
 * The chosen options travel everywhere as one flat object, attribute name to
 * value: {"Size": "M", "Color": "Red"}. Null / {} means "no options".
 */
final class ProductOptions
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * The options a product offers, ordered by the attribute's sort order.
     *
     * @return array<int, array{id: int, name: string, values: array<int, string>}>
     */
    public static function forProduct(Product $product): array
    {
        $product->loadMissing('attributeValues.attribute');

        $byAttribute = [];

        foreach ($product->attributeValues as $row) {
            $attribute = $row->attribute;

            if (! $attribute || ! $attribute->is_variant) {
                continue;
            }

            $byAttribute[$attribute->id] ??= [
                'attribute' => $attribute,
                'values'    => [],
            ];

            foreach (self::splitValues($row->value) as $value) {
                $byAttribute[$attribute->id]['values'][] = $value;
            }
        }

        $options = [];

        foreach ($byAttribute as $entry) {
            $values = self::unique($entry['values']);

            if ($values === []) {
                continue;
            }

            $options[] = [
                'id'         => (int) $entry['attribute']->id,
                'name'       => (string) $entry['attribute']->name,
                'values'     => $values,
                '_sort'      => (int) ($entry['attribute']->sort_order ?? 0),
            ];
        }

        usort($options, fn ($a, $b) => [$a['_sort'], $a['name']] <=> [$b['_sort'], $b['name']]);

        return array_map(function (array $option) {
            unset($option['_sort']);

            return $option;
        }, $options);
    }

    /** Attribute ids that are customer choices for this product. */
    public static function variantAttributeIds(Product $product): array
    {
        return array_column(self::forProduct($product), 'id');
    }

    /**
     * Turn one stored attribute value into a list of choices.
     *
     * Accepts a JSON array ('["M","L"]'), or text separated by commas,
     * semicolons, pipes or new lines ("M, L, XL"). A slash is *not* a
     * separator: "Grey/Black" is one two-tone colour, not two choices.
     *
     * @return array<int, string>
     */
    public static function splitValues(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $text = trim((string) $raw);

            if ($text === '') {
                return [];
            }

            $decoded = str_starts_with($text, '[') ? json_decode($text, true) : null;
            $parts   = is_array($decoded) ? $decoded : preg_split('/[,;|\r\n]+/', $text);
        }

        $out = [];

        foreach ($parts as $part) {
            if (is_scalar($part)) {
                $value = trim(preg_replace('/\s+/', ' ', (string) $part));

                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return self::unique($out);
    }

    /**
     * Validate a customer's choice against what the product offers, and return
     * it in canonical form (offered spelling, product's option order), or null
     * when the product has no options.
     *
     * Every option must be chosen, every value must be one the product offers
     * (matched case-insensitively) and no unknown names are accepted.
     *
     * @param  string  $errorPrefix  Error key prefix, e.g. "options" or "items.0.options".
     *
     * @throws ValidationException
     */
    public static function validate(Product $product, mixed $input, string $errorPrefix = 'options'): ?array
    {
        $offered = self::forProduct($product);
        $chosen  = self::normaliseInput($input);
        $errors  = [];
        $result  = [];
        $known   = [];

        if ($chosen === null) {
            $errors[$errorPrefix][] = 'Options must be an object of option name to value.';
            $chosen = [];
        }

        foreach ($offered as $option) {
            $known[mb_strtolower($option['name'])] = true;
            $value = self::lookup($chosen, $option['name']);
            $key   = "{$errorPrefix}.{$option['name']}";

            if ($value === null || $value === '') {
                $errors[$key][] = self::chooseMessage($option['name']);
                continue;
            }

            $match = null;
            foreach ($option['values'] as $candidate) {
                if (mb_strtolower($candidate) === mb_strtolower($value)) {
                    $match = $candidate;
                    break;
                }
            }

            if ($match === null) {
                $errors[$key][] = sprintf(
                    '"%s" is not available for %s. Choose one of: %s.',
                    $value,
                    $option['name'],
                    implode(', ', $option['values']),
                );
                continue;
            }

            $result[$option['name']] = $match;
        }

        foreach (array_keys($chosen) as $name) {
            if (! isset($known[mb_strtolower((string) $name)])) {
                $errors["{$errorPrefix}.{$name}"][] = sprintf('This product has no "%s" option.', $name);
            }
        }

        if ($errors !== []) {
            $first = reset($errors)[0];
            $exception = ValidationException::withMessages($errors);
            // Laravel's default summary is "... (and N more errors)"; the
            // first message on its own is what a shopper should read.
            throw new ValidationException($exception->validator, response()->json([
                'message' => $first,
                'errors'  => $errors,
            ], 422));
        }

        return $result === [] ? null : $result;
    }

    /**
     * Stable fingerprint of a choice, used to tell cart lines apart.
     * '' for no options, so lines from before this feature keep matching.
     */
    public static function key(?array $options): string
    {
        if (! $options) {
            return '';
        }

        $copy = $options;
        ksort($copy);

        return sha1(json_encode($copy, JSON_UNESCAPED_UNICODE));
    }

    /** "Size: M · Color: Red", for admin screens and plain-text receipts. */
    public static function format(mixed $options): string
    {
        if (is_string($options)) {
            $options = json_decode($options, true);
        }

        if (! is_array($options) || $options === []) {
            return '';
        }

        $parts = [];
        foreach ($options as $name => $value) {
            $parts[] = "{$name}: {$value}";
        }

        return implode(' · ', $parts);
    }

    /** Null-safe normalisation for presenting a stored value. */
    public static function present(mixed $options): ?array
    {
        if (is_string($options)) {
            $options = json_decode($options, true);
        }

        return is_array($options) && $options !== [] ? $options : null;
    }

    /** Whether the cart table has been migrated to hold options. */
    public static function cartSupportsOptions(): bool
    {
        return self::hasColumn('cart_items', 'options_key');
    }

    /** Whether the orders table has been migrated to hold options. */
    public static function ordersSupportOptions(): bool
    {
        return self::hasColumn('orders', 'options');
    }

    private static function hasColumn(string $table, string $column): bool
    {
        return self::$columnCache["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }

    /** @return array<string, string>|null  null when the input is not an object. */
    private static function normaliseInput(mixed $input): ?array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }

        if (is_string($input)) {
            $input = json_decode($input, true);
        }

        if (! is_array($input) || array_is_list($input)) {
            return null;
        }

        $out = [];
        foreach ($input as $name => $value) {
            if (! is_scalar($value) && $value !== null) {
                return null;
            }
            $out[(string) $name] = $value === null ? null : trim((string) $value);
        }

        return $out;
    }

    private static function lookup(array $chosen, string $name): ?string
    {
        if (array_key_exists($name, $chosen)) {
            return $chosen[$name];
        }

        foreach ($chosen as $key => $value) {
            if (mb_strtolower((string) $key) === mb_strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    private static function chooseMessage(string $name): string
    {
        $label = mb_strtolower($name);
        $article = preg_match('/^[aeiou]/', $label) ? 'an' : 'a';

        return "Please choose {$article} {$label}";
    }

    /** @param array<int, string> $values */
    private static function unique(array $values): array
    {
        $seen = [];
        $out  = [];

        foreach ($values as $value) {
            $lower = mb_strtolower($value);
            if (! isset($seen[$lower])) {
                $seen[$lower] = true;
                $out[] = $value;
            }
        }

        return $out;
    }
}
