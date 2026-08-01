<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Models\User;
use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessAttachment extends AuditableModel
{
    protected $fillable = [
        'attachment_definition_id',
        'entity_type',
        'record_id',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum',
        'uploaded_by',
        'payload',
        'is_sensitive',
        'is_active',
    ];

    protected $casts = [
        'payload' => 'array',
        'size_bytes' => 'integer',
        'is_sensitive' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(AttachmentDefinition::class, 'attachment_definition_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
