<?php

namespace App\Modules\Printing\Models;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shared\Models\AuditableModel;
use App\Modules\SystemSuperadmin\Models\PrinterProfile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PrintJob extends AuditableModel
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PRINTED = 'printed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'branch_id',
        'printer_profile_id',
        'print_document_template_id',
        'user_id',
        'document_type',
        'printable_type',
        'printable_id',
        'area',
        'status',
        'copies',
        'payload',
        'rendered_preview',
        'error_message',
        'printed_at',
    ];

    protected $casts = [
        'copies' => 'integer',
        'payload' => 'array',
        'printed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function printable(): MorphTo
    {
        return $this->morphTo();
    }
}
