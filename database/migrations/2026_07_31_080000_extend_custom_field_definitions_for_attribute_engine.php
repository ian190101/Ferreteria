<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_field_definitions', function (Blueprint $table) {
            $table->boolean('visible_in_table')->default(false)->after('visible_in_forms');
            $table->boolean('is_exportable')->default(false)->after('visible_in_reports');
            $table->boolean('is_auditable')->default(true)->after('is_exportable');
            $table->boolean('is_sensitive')->default(false)->after('is_auditable')->index();
            $table->boolean('is_encrypted')->default(false)->after('is_sensitive');
            $table->boolean('is_read_only')->default(false)->after('is_encrypted');
            $table->string('group', 120)->nullable()->after('type')->index();
            $table->string('help_text', 500)->nullable()->after('label');
            $table->string('placeholder', 180)->nullable()->after('help_text');
            $table->json('default_value')->nullable()->after('validation_rules');
            $table->string('format', 120)->nullable()->after('default_value');
            $table->decimal('min_value', 18, 6)->nullable()->after('format');
            $table->decimal('max_value', 18, 6)->nullable()->after('min_value');
            $table->string('relation_entity_type', 80)->nullable()->after('max_value')->index();
            $table->json('metadata')->nullable()->after('relation_entity_type');

            $table->index(['entity_type', 'is_active', 'visible_in_forms'], 'custom_fields_entity_forms_idx');
            $table->index(['entity_type', 'is_active', 'visible_in_reports'], 'custom_fields_entity_reports_idx');
        });
    }

    public function down(): void
    {
        Schema::table('custom_field_definitions', function (Blueprint $table) {
            $table->dropIndex('custom_fields_entity_forms_idx');
            $table->dropIndex('custom_fields_entity_reports_idx');
            $table->dropColumn([
                'visible_in_table',
                'is_exportable',
                'is_auditable',
                'is_sensitive',
                'is_encrypted',
                'is_read_only',
                'group',
                'help_text',
                'placeholder',
                'default_value',
                'format',
                'min_value',
                'max_value',
                'relation_entity_type',
                'metadata',
            ]);
        });
    }
};
