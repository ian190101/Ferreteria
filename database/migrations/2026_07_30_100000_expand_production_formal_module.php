<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_formulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('output_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('yield_quantity', 14, 4)->default(1);
            $table->decimal('expected_waste_percentage', 8, 4)->default(0);
            $table->decimal('standard_labor_cost', 14, 4)->default(0);
            $table->decimal('standard_overhead_cost', 14, 4)->default(0);
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'output_product_id', 'is_active'], 'production_formulas_branch_output_idx');
        });

        Schema::create('production_formula_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_formula_id')->constrained('production_formulas')->cascadeOnDelete();
            $table->foreignId('input_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->string('unit_label', 40)->nullable();
            $table->decimal('waste_percentage', 8, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['production_formula_id', 'sort_order'], 'production_formula_items_formula_order_idx');
        });

        Schema::create('production_formula_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_formula_id')->constrained('production_formulas')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('estimated_minutes')->default(0);
            $table->boolean('requires_confirmation')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['production_formula_id', 'sort_order'], 'production_formula_stages_formula_order_idx');
        });

        Schema::create('production_order_inputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_coil_id')->nullable()->constrained('product_coils')->nullOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->string('unit_label', 40)->nullable();
            $table->decimal('waste_percentage', 8, 4)->default(0);
            $table->timestamps();

            $table->index(['production_order_id', 'product_id'], 'production_order_inputs_order_product_idx');
        });

        Schema::create('production_order_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 40)->default('pending')->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('actual_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['production_order_id', 'sort_order'], 'production_order_stages_order_idx');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('production_formula_id')->nullable()->after('user_id')->constrained('production_formulas')->nullOnDelete();
            $table->decimal('planned_output_quantity', 14, 4)->default(0)->after('output_meters');
            $table->decimal('actual_output_quantity', 14, 4)->default(0)->after('planned_output_quantity');
            $table->decimal('labor_cost', 14, 4)->default(0)->after('waste_meters');
            $table->decimal('overhead_cost', 14, 4)->default(0)->after('labor_cost');
            $table->decimal('input_cost', 14, 4)->default(0)->after('overhead_cost');
            $table->decimal('total_cost', 14, 4)->default(0)->after('input_cost');
            $table->decimal('unit_cost', 14, 4)->default(0)->after('total_cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('production_formula_id');
            $table->dropColumn([
                'planned_output_quantity',
                'actual_output_quantity',
                'labor_cost',
                'overhead_cost',
                'input_cost',
                'total_cost',
                'unit_cost',
            ]);
        });

        Schema::dropIfExists('production_order_stages');
        Schema::dropIfExists('production_order_inputs');
        Schema::dropIfExists('production_formula_stages');
        Schema::dropIfExists('production_formula_items');
        Schema::dropIfExists('production_formulas');
    }
};
