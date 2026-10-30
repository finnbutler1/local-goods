<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the demo activity generator did or tried (ADR 0032). Claimed
 * (status "claimed") before it runs; day_id + slot is unique, so no slot
 * ever runs twice. It points at what the normal services made: that link
 * is how a page marks a purchase or redemption as generated, not made by a
 * person.
 */
class DemoActivityAction extends Model
{
    public const KIND_PURCHASE = 'purchase';

    public const KIND_ALLOWANCE = 'allowance';

    public const KIND_RECOGNITION = 'recognition';

    public const KIND_REDEMPTION = 'redemption';

    public const KIND_REFUND_REQUEST = 'refund_request';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'day_id', 'slot', 'kind', 'status', 'planned_for', 'ran_at', 'amount_cents', 'unusual', 'channel',
        'employee_id', 'merchant_id', 'redemption_id', 'credit_purchase_id', 'correction_id', 'note', 'error',
    ];

    protected function casts(): array
    {
        return ['planned_for' => 'datetime', 'ran_at' => 'datetime', 'unusual' => 'boolean'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(DemoActivityDay::class, 'day_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(Redemption::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CreditPurchase::class, 'credit_purchase_id');
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(Correction::class);
    }
}
