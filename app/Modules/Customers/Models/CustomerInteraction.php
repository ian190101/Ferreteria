<?php

namespace App\Modules\Customers\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInteraction extends AuditableModel
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'customer_id',
        'user_id',
        'type',
        'status',
        'contact_at',
        'follow_up_at',
        'subject',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'contact_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
