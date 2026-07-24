<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_movements MODIFY type VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_movements MODIFY type VARCHAR(24) NOT NULL');
    }
};
