<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_barriers', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
        });
        DB::table('maintenance_barriers')->insert(['name' => 'database-purge']);
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_barriers');
    }
};
