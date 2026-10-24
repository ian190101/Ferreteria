<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class OperationalCacheOutbox
{
    public function schedule(string $scope = 'operational'): void
    {
        $id = (string) Str::orderedUuid();
        // El pendiente se confirma o revierte junto con los cambios del negocio.
        DB::table('cache_invalidation_outbox')->insert(['id' => $id, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
        DB::afterCommit(fn () => $this->process($id));
    }

    public function process(string $id): bool
    {
        try {
            $entry = DB::table('cache_invalidation_outbox')->where('id', $id)->first();
            if (! $entry) {
                return true;
            }
            if (in_array($entry->scope, ['operational', 'recovery', 'business_profile', 'lot_inventory'], true)) {
                UiCatalogCache::forgetProductCatalogs();
                UiCatalogCache::forgetSalesCatalogs();
                SystemCacheInvalidator::bumpOperational();
            }
            if (in_array($entry->scope, ['business_profile', 'lot_inventory'], true)) {
                AuthSessionCache::bump();
            }
            if ($entry->scope === 'recovery') {
                AuthSessionCache::bump();
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                if (Artisan::call('queue:restart') !== 0) {
                    throw new \RuntimeException('No se pudo solicitar el reinicio de los trabajadores después de recuperar datos.');
                }
            }
            UiCatalogCache::forgetFinancialCatalogs();
            foreach (['banks', 'expenses', 'cash'] as $channel) {
                $key = $channel.':summary_version';
                Cache::forever($key, ((int) Cache::get($key, 1)) + 1);
            }
            DB::table('cache_invalidation_outbox')->where('id', $id)->delete();

            return true;
        } catch (Throwable $exception) {
            try {
                DB::table('cache_invalidation_outbox')->where('id', $id)->increment('attempts', 1, ['last_error' => $exception::class, 'updated_at' => now()]);
                report($exception);
            } catch (Throwable) {
                // El pendiente ya persistido permite recuperar la invalidación al volver los servicios.
            }

            return false;
        }
    }

    public function flushPending(int $limit = 100): int
    {
        $completed = 0;
        foreach (DB::table('cache_invalidation_outbox')->orderBy('created_at')->limit($limit)->pluck('id') as $id) {
            $completed += (int) $this->process($id);
        }

        return $completed;
    }
}
