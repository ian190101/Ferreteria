<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessCommercialFlowRule extends AuditableModel
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'business_type',
        'sales_workflow',
        'pos_mode',
        'channel',
        'document_type',
        'customer_mode',
        'cash_policy',
        'inventory_timing',
        'payment_policy',
        'requires_resource',
        'requires_responsible',
        'requires_reservation',
        'requires_service_order',
        'requires_guarantee',
        'allows_discount',
        'allows_price_override',
        'allows_credit',
        'allows_advance',
        'allows_split_payment',
        'allows_mixed_payment',
        'validations',
        'permissions',
        'settings',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'requires_resource' => 'boolean',
        'requires_responsible' => 'boolean',
        'requires_reservation' => 'boolean',
        'requires_service_order' => 'boolean',
        'requires_guarantee' => 'boolean',
        'allows_discount' => 'boolean',
        'allows_price_override' => 'boolean',
        'allows_credit' => 'boolean',
        'allows_advance' => 'boolean',
        'allows_split_payment' => 'boolean',
        'allows_mixed_payment' => 'boolean',
        'validations' => 'array',
        'permissions' => 'array',
        'settings' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
