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
     * Has this seller published a till number customers can pay into?
     *
     * Checkout asks this before it will take an order for the store. Showing
     * a blank number, or quietly falling back to another vendor's, would send
     * the customer's money to the wrong place.
     */
    public function hasLipaNamba(): bool
    {
        return \App\Support\LipaNamba::isValid($this->lipa_namba);
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
