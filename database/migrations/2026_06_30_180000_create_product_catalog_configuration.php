<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('symbol', 24)->unique();
            $table->string('kind', 40)->default('cantidad')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('default_unit_id')->nullable()->constrained('product_units')->nullOnDelete();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->string('default_tracking_mode', 24)->default('global')->index();
            $table->boolean('requires_thickness')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('product_category_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 120);
            $table->string('type', 24)->default('text')->index();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->timestamps();
            $table->unique(['product_category_id', 'code'], 'category_attribute_code_unique');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_category_id')->nullable()->after('thickness_id')->constrained()->nullOnDelete();
            $table->foreignId('product_unit_id')->nullable()->after('product_category_id')->constrained()->nullOnDelete();
            $table->json('attributes')->nullable()->after('base_unit');
            $table->index(['product_category_id', 'is_active']);
            $table->index(['product_unit_id', 'is_active']);
        });

        $this->seedDefaults();
        $this->syncExistingProducts();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_category_id');
            $table->dropConstrainedForeignId('product_unit_id');
            $table->dropColumn('attributes');
        });

        Schema::dropIfExists('product_category_attributes');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('product_units');
    }

    private function seedDefaults(): void
    {
        $now = now();

        $units = [
            ['name' => 'Metro', 'symbol' => 'm', 'kind' => 'longitud'],
            ['name' => 'Unidad', 'symbol' => 'unidad', 'kind' => 'cantidad'],
            ['name' => 'Caja', 'symbol' => 'caja', 'kind' => 'cantidad'],
            ['name' => 'Paquete', 'symbol' => 'paquete', 'kind' => 'cantidad'],
            ['name' => 'Kilogramo', 'symbol' => 'kg', 'kind' => 'peso'],
            ['name' => 'Tonelada', 'symbol' => 'ton', 'kind' => 'peso'],
            ['name' => 'Litro', 'symbol' => 'lt', 'kind' => 'volumen'],
            ['name' => 'Galon', 'symbol' => 'galon', 'kind' => 'volumen'],
            ['name' => 'Rollo', 'symbol' => 'rollo', 'kind' => 'cantidad'],
        ];

        foreach ($units as $unit) {
            DB::table('product_units')->updateOrInsert(
                ['symbol' => $unit['symbol']],
                [...$unit, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $meterId = DB::table('product_units')->where('symbol', 'm')->value('id');
        $unitId = DB::table('product_units')->where('symbol', 'unidad')->value('id');

        $categories = [
            ['name' => 'Calaminas', 'default_unit_id' => $meterId, 'default_tracking_mode' => 'coil', 'requires_thickness' => true],
            ['name' => 'Bobinas', 'default_unit_id' => $meterId, 'default_tracking_mode' => 'coil', 'requires_thickness' => true],
            ['name' => 'Ferreteria general', 'default_unit_id' => $unitId, 'default_tracking_mode' => 'global', 'requires_thickness' => false],
            ['name' => 'Herramientas', 'default_unit_id' => $unitId, 'default_tracking_mode' => 'global', 'requires_thickness' => false],
            ['name' => 'Tornilleria', 'default_unit_id' => $unitId, 'default_tracking_mode' => 'global', 'requires_thickness' => false],
            ['name' => 'Pinturas', 'default_unit_id' => DB::table('product_units')->where('symbol', 'lt')->value('id'), 'default_tracking_mode' => 'global', 'requires_thickness' => false],
            ['name' => 'Electricidad', 'default_unit_id' => $unitId, 'default_tracking_mode' => 'global', 'requires_thickness' => false],
            ['name' => 'Plomeria', 'default_unit_id' => $unitId, 'default_tracking_mode' => 'global', 'requires_thickness' => false],
            ['name' => 'Accesorios', 'default_unit_id' => $unitId, 'default_tracking_mode' => 'global', 'requires_thickness' => false],
        ];

        foreach ($categories as $category) {
            DB::table('product_categories')->updateOrInsert(
                ['name' => $category['name']],
                [
                    ...$category,
                    'slug' => Str::slug($category['name']),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $calaminaId = DB::table('product_categories')->where('name', 'Calaminas')->value('id');

        foreach ([
            ['name' => 'Color', 'code' => 'color', 'type' => 'text', 'sort_order' => 10],
            ['name' => 'Ancho util', 'code' => 'ancho_util', 'type' => 'number', 'unit' => 'm', 'sort_order' => 20],
            ['name' => 'Largo', 'code' => 'largo', 'type' => 'number', 'unit' => 'm', 'sort_order' => 30],
            ['name' => 'Acabado', 'code' => 'acabado', 'type' => 'text', 'sort_order' => 40],
        ] as $attribute) {
            DB::table('product_category_attributes')->updateOrInsert(
                ['product_category_id' => $calaminaId, 'code' => $attribute['code']],
                [
                    'product_category_id' => $calaminaId,
                    'product_unit_id' => isset($attribute['unit']) ? DB::table('product_units')->where('symbol', $attribute['unit'])->value('id') : null,
                    'name' => $attribute['name'],
                    'code' => $attribute['code'],
                    'type' => $attribute['type'],
                    'options' => null,
                    'is_required' => false,
                    'is_active' => true,
                    'sort_order' => $attribute['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function syncExistingProducts(): void
    {
        DB::table('products')
            ->select(['id', 'category', 'base_unit'])
            ->orderBy('id')
            ->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $categoryId = DB::table('product_categories')->where('name', $product->category)->value('id')
                        ?? DB::table('product_categories')->where('name', 'Ferreteria general')->value('id');
                    $unitId = DB::table('product_units')->where('symbol', $product->base_unit)->value('id')
                        ?? DB::table('product_units')->where('symbol', 'unidad')->value('id');

                    DB::table('products')->where('id', $product->id)->update([
                        'product_category_id' => $categoryId,
                        'product_unit_id' => $unitId,
                        'attributes' => json_encode([]),
                    ]);
                }
            });
    }
};
