<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_name',
        'logo',
        'phone',
        'lipa_namba',
        'business_address',
        'email',
        'website',
        'description',
        'is_approved',
        'nida_number',
        'nida_document',
        'business_license',
        'seller_status', 'approved_at', 'admin_note',
        'is_verified', 'verification_status', 'verification_submitted_at',
        'verified_at', 'verification_note',
    ];

    protected $casts = [
        'is_approved'               => 'boolean',
        'is_verified'               => 'boolean',
        'approved_at'               => 'datetime',
        'verified_at'               => 'datetime',
        'verification_submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }
    
    public function paymentOptions()
    {
        return $this->hasMany(\App\Models\VendorPaymentOption::class, 'vendor_id');
    }

    /** Everything this vendor lists on the marketplace. */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /** Order lines fulfilled by this vendor. */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * May this seller make products publicly visible?
     *
     * Approval is the gate, not verification: a shop can trade before it has
     * earned the checkmark. `is_approved` stays authoritative because the
     * existing catalogue and admin already turn on it.
     */
    public function canPublish(): bool
    {
        return (bool) $this->is_approved && $this->seller_status !== 'suspended';
    }

    /**
     * Names a seller may already have filed their till number under.
     *
     * `vendor_payment_options` predates the dedicated column and is where the
     * older seller profile screen writes. A store that entered its number
     * there should not have to type it again, so it is read as a fallback
     * rather than being ignored in favour of an empty new field.
     */
    private const TILL_ALIASES = ['lipa namba', 'lipa number', 'till', 'till number', 'lipa'];

    /**
     * The till number customers pay this store on, wherever it is filed.
     *
     * Two sources, in a deliberate order:
     *
     *   1. `vendors.lipa_namba` — the dedicated column, written by the seller
     *      console. Authoritative when present.
     *   2. `vendor_payment_options.account` — the pre-existing table, for
     *      stores that configured a payment option before the column existed.
     *
     * The column is checked for existence rather than assumed, so this class
     * behaves correctly on a database where the migration has not run yet: it
     * falls through to the older table instead of throwing.
     *
     * Returns null when the store has no usable number anywhere. Never another
     * vendor's, and never an empty string dressed up as a number.
     */
    public function resolveLipaNamba(): ?string
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('vendors', 'lipa_namba')) {
            $own = \App\Support\LipaNamba::normalise($this->lipa_namba);

            if ($own !== null) {
                return $own;
            }
        }

        return $this->lipaNambaFromPaymentOptions();
    }

    /**
     * A till number filed under the older payment-options table.
     *
     * Only options whose method or type actually names a till are considered —
     * a bank account or a card number filed there is not something a customer
     * can pay with Lipa kwa M-Pesa, and showing one as a Lipa Namba would send
     * them to a dead end.
     */
    private function lipaNambaFromPaymentOptions(): ?string
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('vendor_payment_options')) {
            return null;
        }

        $options = $this->relationLoaded('paymentOptions')
            ? $this->paymentOptions
            : $this->paymentOptions()->with(['paymentMethod', 'paymentType'])->get();

        foreach ($options as $option) {
            $labels = strtolower(trim(
                ($option->paymentMethod->name ?? '') . ' ' . ($option->paymentType->name ?? '')
            ));

            $namesATill = false;
            foreach (self::TILL_ALIASES as $alias) {
                if (str_contains($labels, $alias)) {
                    $namesATill = true;
                    break;
                }
            }

            if (! $namesATill) {
                continue;
            }

            $account = \App\Support\LipaNamba::normalise($option->account);

            if ($account !== null) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Save this store's till number, wherever this database can hold it.
     *
     * Prefers the dedicated column. Where it does not exist — a server whose
     * files have been updated but whose migration has not run — the number
     * goes into `vendor_payment_options` instead, which is the table the older
     * seller screen already writes to and which resolveLipaNamba() already
     * reads. The seller's console therefore works either way, and the two
     * halves of a deployment stop having to land in the same minute.
     *
     * `$digits` must already be normalised, or null to take the number down.
     */
    public function storeLipaNamba(?string $digits): void
    {
        // Whatever happens below changes what resolveLipaNamba() should now
        // return, and an already-loaded relation would keep answering with the
        // state from before the write. The seller then sees their own number
        // come back blank and reasonably concludes the save failed.
        $this->unsetRelation('paymentOptions');

        if (\Illuminate\Support\Facades\Schema::hasColumn('vendors', 'lipa_namba')) {
            $this->forceFill(['lipa_namba' => $digits])->save();
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('vendor_payment_options')) {
            return;
        }

        $existing = $this->paymentOptions()->with(['paymentMethod', 'paymentType'])->get()  // fresh query, not the cached relation
            ->first(function ($option) {
                $labels = strtolower(trim(
                    ($option->paymentMethod->name ?? '') . ' ' . ($option->paymentType->name ?? '')
                ));

                foreach (self::TILL_ALIASES as $alias) {
                    if (str_contains($labels, $alias)) {
                        return true;
                    }
                }

                return false;
            });

        if ($digits === null) {
            $existing?->delete();
            return;
        }

        if ($existing) {
            $existing->update(['account' => $digits]);
            return;
        }

        // The type and method rows are reference data, created once and shared
        // by every seller — firstOrCreate rather than create, so two sellers
        // saving at the same time cannot produce two "Lipa Namba" methods.
        $type = \App\Models\PaymentType::firstOrCreate(['name' => 'Mobile Money']);
        $method = \App\Models\PaymentMethod::firstOrCreate([
            'payment_type_id' => $type->id,
            'name'            => 'Lipa Namba',
        ]);

        $this->paymentOptions()->create([
            'payment_type_id'   => $type->id,
            'payment_method_id' => $method->id,
            'account'           => $digits,
        ]);
    }

    /**
     * Has this seller published a till number customers can pay into?
     *
     * Checkout asks this before it will take an order for the store. Showing
     * a blank number, or quietly falling back to another vendor's, would send
     * the customer's money to the wrong place.
     */
    public function hasLipaNamba(): bool
    {
        return $this->resolveLipaNamba() !== null;
    }

    /**
     * May this seller take an order right now?
     *
     * Two independent gates: an administrator has to have approved the store,
     * and the store has to have somewhere for the money to land.
     */
    public function canAcceptOrders(): bool
    {
        return $this->canPublish() && $this->hasLipaNamba();
    }

    public function documents()
    {
        return $this->hasMany(VendorDocument::class);
    }
}
