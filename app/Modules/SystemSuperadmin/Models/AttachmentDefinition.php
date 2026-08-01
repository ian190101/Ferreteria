<?php

namespace App\Modules\SystemSuperadmin\Models;

use App\Modules\Shared\Models\AuditableModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttachmentDefinition extends AuditableModel
{
    protected $fillable = [
        'entity_type',
        'code',
        'label',
        'purpose',
        'allowed_mime_types',
        'allowed_extensions',
        'max_file_mb',
        'storage_disk',
        'path_prefix',
        'is_required',
        'is_sensitive',
        'requires_signed_url',
        'audit_downloads',
        'visible_in_documents',
        'visible_in_reports',
        'permissions',
        'metadata',
        'retention_policy',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'allowed_mime_types' => 'array',
        'allowed_extensions' => 'array',
        'max_file_mb' => 'integer',
        'is_required' => 'boolean',
        'is_sensitive' => 'boolean',
        'requires_signed_url' => 'boolean',
        'audit_downloads' => 'boolean',
        'visible_in_documents' => 'boolean',
        'visible_in_reports' => 'boolean',
        'permissions' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(BusinessAttachment::class);
    }
}
