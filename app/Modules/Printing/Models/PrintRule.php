<?php

namespace App\Modules\Printing\Models;

use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintRule extends AuditableModel
{
    protected $fillable = [
        'branch_id',
        'printer_profile_id',
        'print_document_template_id',
        'document_type',
        'area',
        'trigger',
        'copies',
        'auto_print',
        'conditions',
        'is_active',
    ];

    protected $casts = [
        'copies' => 'integer',
        'auto_print' => 'boolean',
        'conditions' => 'array',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function printerProfile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintDocumentTemplate::class, 'print_document_template_id');
    }
}
