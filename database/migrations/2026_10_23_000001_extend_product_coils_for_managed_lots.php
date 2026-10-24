<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_coils', function (Blueprint $table): void {
            $table->string('lot_code', 80)->nullable()->charset('ascii')->collation('ascii_bin');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->unique(['product_id', 'branch_id', 'lot_code'], 'coils_product_branch_lot_code_uq');
            $table->index(['branch_id', 'product_id', 'voided_at', 'id'], 'coils_branch_product_void_id_idx');
        });

        foreach (['product_id', 'branch_id'] as $column) {
            $this->replaceForeignKey($column, 'RESTRICT');
        }
    }

    public function down(): void
    {
        foreach (['product_id', 'branch_id'] as $column) {
            $this->replaceForeignKey($column, 'CASCADE');
        }

        Schema::table('product_coils', function (Blueprint $table): void {
            $table->dropForeign(['voided_by']);
            $table->dropUnique('coils_product_branch_lot_code_uq');
            $table->dropIndex('coils_branch_product_void_id_idx');
            $table->dropColumn(['lot_code', 'notes', 'voided_at', 'voided_by', 'void_reason']);
        });
    }

    private function replaceForeignKey(string $column, string $onDelete): void
    {
        $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'product_coils')
            ->where('COLUMN_NAME', $column)
            ->where('REFERENCED_TABLE_NAME', $column === 'product_id' ? 'products' : 'branches')
            ->value('CONSTRAINT_NAME');

        if ($constraint === null) {
            throw new RuntimeException("Falta la FK product_coils.{$column}.");
        }

        Schema::table('product_coils', function (Blueprint $table) use ($constraint, $column, $onDelete): void {
            $table->dropForeign($constraint);
            $table->foreign($column)->references('id')
                ->on($column === 'product_id' ? 'products' : 'branches')
                ->onDelete($onDelete);
        });
    }
};
