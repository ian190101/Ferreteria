<?php

namespace App\Modules\SystemSuperadmin\Services;

use App\Models\User;
use App\Modules\SystemSuperadmin\Models\AttachmentDefinition;
use App\Modules\SystemSuperadmin\Models\BusinessAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessAttachmentService
{
    /**
     * @return array<int, AttachmentDefinition>
     */
    public function definitionsFor(string $entityType, bool $onlyRuntimeEnabled = true): array
    {
        if ($onlyRuntimeEnabled && ! $this->runtimeEnabled()) {
            return [];
        }

        if (! app(DynamicEntityRegistry::class)->isUsable($entityType)) {
            return [];
        }

        return AttachmentDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->all();
    }

    public function findUsable(string $entityType, string $code): ?AttachmentDefinition
    {
        return collect($this->definitionsFor($entityType))
            ->first(fn (AttachmentDefinition $definition) => $definition->code === $code);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(AttachmentDefinition $definition, string|int $recordId, UploadedFile $file, ?User $user = null, array $payload = []): BusinessAttachment
    {
        $this->ensureRuntimeAndDefinition($definition);
        $this->validateFile($definition, $file);

        $disk = $definition->storage_disk ?: config('filesystems.default', 'local');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $pathPrefix = trim($definition->path_prefix ?: 'business-attachments', '/');
        $path = $file->storeAs(
            "{$pathPrefix}/{$definition->entity_type}/".now()->format('Y/m'),
            Str::uuid()->toString().'.'.$extension,
            $disk
        );

        if (! $path) {
            throw ValidationException::withMessages([
                'file' => 'No se pudo guardar el archivo adjunto.',
            ]);
        }

        return BusinessAttachment::query()->create([
            'attachment_definition_id' => $definition->id,
            'entity_type' => $definition->entity_type,
            'record_id' => (string) $recordId,
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'size_bytes' => $file->getSize() ?: 0,
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user?->id,
            'payload' => $payload,
            'is_sensitive' => $definition->is_sensitive,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<int, BusinessAttachment>
     */
    public function attachmentsFor(string $entityType, string|int $recordId, bool $includeSensitive = false): array
    {
        if (! $this->runtimeEnabled()) {
            return [];
        }

        return BusinessAttachment::query()
            ->with('definition:id,code,label,entity_type,purpose,is_sensitive,requires_signed_url,audit_downloads')
            ->where('entity_type', $entityType)
            ->where('record_id', (string) $recordId)
            ->where('is_active', true)
            ->when(! $includeSensitive, fn ($query) => $query->where('is_sensitive', false))
            ->latest('id')
            ->get()
            ->all();
    }

    public function deactivate(BusinessAttachment $attachment): void
    {
        $attachment->update(['is_active' => false]);
    }

    public function runtimeEnabled(): bool
    {
        return ActiveBusinessProfile::featureEnabled('attachments_engine')
            && ActiveBusinessProfile::capable('uses_attachments');
    }

    private function ensureRuntimeAndDefinition(AttachmentDefinition $definition): void
    {
        if (! $this->runtimeEnabled()) {
            throw ValidationException::withMessages([
                'file' => 'El motor de adjuntos no esta activo para el perfil actual.',
            ]);
        }

        if (! $definition->is_active || ! app(DynamicEntityRegistry::class)->isUsable($definition->entity_type)) {
            throw ValidationException::withMessages([
                'file' => 'La definicion de adjunto no esta activa o usa una entidad inactiva.',
            ]);
        }
    }

    private function validateFile(AttachmentDefinition $definition, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $mime = (string) $file->getMimeType();
        $maxBytes = max($definition->max_file_mb, 1) * 1024 * 1024;

        if (($file->getSize() ?: 0) > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "El archivo supera el maximo permitido de {$definition->max_file_mb} MB.",
            ]);
        }

        $allowedExtensions = array_map('strtolower', $definition->allowed_extensions ?? []);
        if ($allowedExtensions !== [] && ! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => 'La extension del archivo no esta permitida para este adjunto.',
            ]);
        }

        $allowedMimes = $definition->allowed_mime_types ?? [];
        if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'El tipo de archivo no esta permitido para este adjunto.',
            ]);
        }
    }
}
