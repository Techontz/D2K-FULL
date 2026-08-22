<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use App\Support\LipaNamba;

/**
 * Direct2Kariakoo's own payment details — one row, set by an administrator.
 *
 * Deliberately a singleton. There is exactly one till for the marketplace, and
 * modelling it as "the first row" rather than as a per-vendor relation is what
 * stops a multi-vendor basket turning back into several payments.
 */
class PaymentSetting extends Model
{
    protected $fillable = ['lipa_namba', 'account_name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * The current settings, wherever this installation keeps them.
     *
     * Falls back to config/payment.php when the table has not been created —
     * a server can have these files before its migration has run, and a
     * checkout that is dead for that reason alone is not acceptable.
     *
     * Returns an unsaved model rather than null so every caller can read the
     * same properties without checking first.
     */
    public static function current(): self
    {
        if (Schema::hasTable('payment_settings')) {
            $row = static::query()->orderBy('id')->first();

            if ($row) {
                return $row;
            }
        }

        return new static([
            'lipa_namba'   => config('payment.lipa_namba'),
            'account_name' => config('payment.account_name'),
            'is_active'    => (bool) config('payment.active', true),
        ]);
    }

    /** The till as bare digits, or null when it is unusable or switched off. */
    public function payableNumber(): ?string
    {
        if (! $this->is_active) {
            return null;
        }

        return LipaNamba::normalise($this->lipa_namba);
    }

    /** Whether the marketplace can currently take a payment at all. */
    public function isPayable(): bool
    {
        return $this->payableNumber() !== null;
    }

    /** The name the customer should see the money going to. */
    public function payeeName(): string
    {
        $name = trim((string) $this->account_name);

        return $name !== '' ? $name : 'Direct2Kariakoo';
    }

    /** The shape checkout and the order snapshot both read. */
    public function toRecipientArray(): array
    {
        $number = $this->payableNumber();

        return [
            'lipa_namba'         => $number,
            'lipa_namba_display' => LipaNamba::pretty($number),
            'account_name'       => $this->payeeName(),
            'is_payable'         => $number !== null,
        ];
    }
}
