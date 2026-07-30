<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('group', 80)->index();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('recommended_business_types')->nullable();
            $table->json('dependencies')->nullable();
            $table->json('conflicts')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('business_profile_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_profile_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('business_capability_id')->constrained('business_capabilities')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false)->index();
            $table->string('source', 40)->default('manual')->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['business_profile_id', 'business_capability_id'], 'bp_capabilities_unique');
            $table->index(['business_profile_id', 'is_enabled'], 'bp_capabilities_profile_enabled_idx');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('url', 2048)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('alt_text', 180)->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'is_primary'], 'product_images_product_primary_idx');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 160)->nullable()->unique();
            $table->string('barcode', 160)->nullable()->unique();
            $table->string('name', 180);
            $table->json('attributes');
            $table->decimal('cost_price', 14, 4)->nullable();
            $table->decimal('sale_price', 14, 4)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['product_id', 'is_active'], 'product_variants_product_active_idx');
        });

        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80)->index();
            $table->string('code', 120);
            $table->string('label', 160);
            $table->string('type', 40);
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('visible_in_forms')->default(true);
            $table->boolean('visible_in_documents')->default(false);
            $table->boolean('visible_in_reports')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['entity_type', 'code'], 'custom_fields_entity_code_unique');
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id'], 'custom_field_values_entity_idx');
        });

        Schema::create('business_state_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 80)->index();
            $table->string('code', 120);
            $table->string('label', 160);
            $table->string('color', 30)->nullable();
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_final')->default(false);
            $table->json('allowed_transitions')->nullable();
            $table->string('required_permission', 120)->nullable();
            $table->json('actions')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['entity_type', 'code'], 'business_states_entity_code_unique');
        });

        Schema::create('reservable_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('type', 80)->index();
            $table->string('code', 120);
            $table->string('name', 180);
            $table->unsignedInteger('capacity')->nullable();
            $table->json('availability_rules')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['branch_id', 'code'], 'reservable_resources_branch_code_unique');
        });

        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('channel', 80)->default('counter')->index();
            $table->string('currency_code', 10)->default('BOB');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('price', 14, 4);
            $table->decimal('min_quantity', 14, 4)->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'product_id', 'product_variant_id'], 'price_list_items_unique');
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('role_name', 120)->nullable()->index();
            $table->string('responsible_type', 80)->nullable()->index();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('calculation_base', 60)->default('subtotal');
            $table->string('type', 40)->default('percentage');
            $table->decimal('value', 14, 4);
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('trigger', 120)->index();
            $table->json('channels')->nullable();
            $table->json('recipients')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('business_module_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('module', 120)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedInteger('max_branches')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_pos_terminals')->nullable();
            $table->date('support_until')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('import_profile_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 120)->unique();
            $table->string('name', 180);
            $table->string('entity_type', 80)->index();
            $table->json('required_columns');
            $table->json('optional_columns')->nullable();
            $table->json('mapping_rules')->nullable();
            $table->json('validation_rules')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('printer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('code', 120);
            $table->string('name', 160);
            $table->string('area', 80)->default('cashier')->index();
            $table->string('paper_type', 80)->default('letter');
            $table->unsignedSmallInteger('thermal_width_mm')->nullable();
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('auto_print')->default(false);
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['branch_id', 'code'], 'printer_profiles_branch_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_profiles');
        Schema::dropIfExists('import_profile_templates');
        Schema::dropIfExists('business_module_licenses');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('reservable_resources');
        Schema::dropIfExists('business_state_definitions');
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('business_profile_capabilities');
        Schema::dropIfExists('business_capabilities');
    }
};
