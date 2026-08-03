<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('maps_reference', 255)->nullable()->after('longitude');
            $table->index(['latitude', 'longitude'], 'branches_coordinates_idx');
        });

        Schema::create('transport_route_caches', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 160)->unique();
            $table->decimal('origin_latitude', 10, 7);
            $table->decimal('origin_longitude', 10, 7);
            $table->decimal('destination_latitude', 10, 7);
            $table->decimal('destination_longitude', 10, 7);
            $table->unsignedInteger('distance_meters');
            $table->unsignedInteger('duration_seconds');
            $table->string('provider', 40)->default('osrm');
            $table->json('geometry')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['origin_latitude', 'origin_longitude'], 'transport_routes_origin_idx');
            $table->index(['destination_latitude', 'destination_longitude'], 'transport_routes_destination_idx');
        });

        Schema::table('delivery_trucks', function (Blueprint $table) {
            $table->string('vehicle_type', 40)->default('truck')->after('plate')->index();
        });

        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->string('origin_address')->nullable()->after('vehicle_plate');
            $table->decimal('origin_latitude', 10, 7)->nullable()->after('origin_address');
            $table->decimal('origin_longitude', 10, 7)->nullable()->after('origin_latitude');
            $table->string('destination_address')->nullable()->after('origin_longitude');
            $table->decimal('destination_latitude', 10, 7)->nullable()->after('destination_address');
            $table->decimal('destination_longitude', 10, 7)->nullable()->after('destination_latitude');
            $table->unsignedInteger('route_distance_meters')->nullable()->after('destination_longitude');
            $table->unsignedInteger('route_duration_seconds')->nullable()->after('route_distance_meters');
            $table->string('route_provider', 40)->nullable()->after('route_duration_seconds');
            $table->string('route_cache_key', 160)->nullable()->after('route_provider');
            $table->json('route_geometry')->nullable()->after('route_cache_key');
            $table->index(['destination_latitude', 'destination_longitude'], 'delivery_destination_idx');
            $table->index('route_cache_key', 'delivery_route_cache_key_idx');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->dropIndex('delivery_destination_idx');
            $table->dropIndex('delivery_route_cache_key_idx');
            $table->dropColumn([
                'origin_address',
                'origin_latitude',
                'origin_longitude',
                'destination_address',
                'destination_latitude',
                'destination_longitude',
                'route_distance_meters',
                'route_duration_seconds',
                'route_provider',
                'route_cache_key',
                'route_geometry',
            ]);
        });

        Schema::dropIfExists('transport_route_caches');

        Schema::table('delivery_trucks', function (Blueprint $table) {
            $table->dropColumn('vehicle_type');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropIndex('branches_coordinates_idx');
            $table->dropColumn(['latitude', 'longitude', 'maps_reference']);
        });
    }
};
