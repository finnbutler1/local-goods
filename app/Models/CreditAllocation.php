<?php

namespace App\Models;

use App\Enums\Occasion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The employer handed credits to an employee: one entry off the employer, one onto the employee. */
class CreditAllocation extends Model
{
    protected $fillable = ['employer_id', 'employee_id', 'amount_cents', 'allocated_at', 'occasion'];

    protected function casts(): array
    {
        return ['allocated_at' => 'datetime', 'occasion' => Occasion::class];
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
