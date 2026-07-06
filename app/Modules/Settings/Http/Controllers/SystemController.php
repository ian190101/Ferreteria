<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Settings\Http\Requests\UpdateSystemSettingRequest;
use App\Modules\Settings\Models\MaintenanceBackup;
use App\Modules\Settings\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SystemController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/System/Index', [
            'settings' => SystemSetting::query()->orderBy('group')->orderBy('key')->get(),
            'backups' => MaintenanceBackup::query()
                ->with('user:id,name')
                ->latest()
                ->paginate(10)
                ->withQueryString(),
        ]);
    }

    public function update(UpdateSystemSettingRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('settings') as $item) {
                SystemSetting::query()
                    ->where('key', $item['key'])
                    ->update(['value' => $this->normalizeValue($item['value'] ?? null)]);
            }
        });

        return redirect()->route('settings.system.index')->with('success', 'Configuracion general actualizada correctamente.');
    }

    public function backup(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $validated = $request->validate([
            'format' => ['nullable', 'in:json,sql'],
        ]);
        $format = $validated['format'] ?? 'json';
        $content = $format === 'sql'
            ? $this->buildSqlDump()
            : $this->buildJsonBackup();
        $path = 'backups/backup-'.now()->format('Ymd-His').'.'.$format;

        Storage::disk('local')->put($path, $content);

        MaintenanceBackup::query()->create([
            'user_id' => $request->user()->id,
            'disk' => 'local',
            'path' => $path,
            'status' => 'created',
            'size_bytes' => strlen($content),
            'metadata' => [
                'format' => $format,
                'database' => config('database.connections.mysql.database'),
            ],
        ]);

        return redirect()->route('settings.system.index')->with('success', 'Backup '.$format.' generado correctamente.');
    }

    public function downloadBackup(Request $request, MaintenanceBackup $backup): BinaryFileResponse
    {
        abort_unless($request->user()?->can('settings.manage'), 403);

        $path = Storage::disk($backup->disk)->path($backup->path);

        abort_unless(is_file($path), 404);

        return response()->download($path, basename($backup->path), [
            'Content-Type' => str_ends_with($backup->path, '.sql')
                ? 'application/sql; charset=UTF-8'
                : 'application/json; charset=UTF-8',
        ]);
    }

    private function buildJsonBackup(): string
    {
        $payload = [
            'generated_at' => now()->toISOString(),
            'tables' => [
                'system_settings' => SystemSetting::query()->orderBy('id')->get()->toArray(),
                'products' => Product::query()->orderBy('id')->get()->toArray(),
                'customers' => Customer::query()->orderBy('id')->get()->toArray(),
            ],
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function buildSqlDump(): string
    {
        $connection = DB::connection();
        $database = (string) config('database.connections.mysql.database');
        $tables = collect($connection->select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']))
            ->map(fn (object $row) => array_values((array) $row)[0])
            ->values();

        $sql = [
            '-- Backup SQL generado por el sistema',
            '-- Base de datos: '.$database,
            '-- Fecha: '.now()->format('Y-m-d H:i:s'),
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
            '',
        ];

        foreach ($tables as $table) {
            $quotedTable = str_replace('`', '``', $table);
            $create = $connection->selectOne('SHOW CREATE TABLE `'.$quotedTable.'`');
            $createSql = $create->{'Create Table'} ?? array_values((array) $create)[1] ?? null;

            if (! $createSql) {
                continue;
            }

            $sql[] = '-- Tabla: '.$table;
            $sql[] = 'DROP TABLE IF EXISTS `'.$quotedTable.'`;';
            $sql[] = $createSql.';';
            $sql[] = '';

            $connection->table($table)
                ->orderByRaw('1')
                ->chunk(500, function ($rows) use (&$sql, $table, $quotedTable) {
                    foreach ($rows as $row) {
                        $data = (array) $row;
                        $columns = collect(array_keys($data))
                            ->map(fn (string $column) => '`'.str_replace('`', '``', $column).'`')
                            ->implode(', ');
                        $values = collect(array_values($data))
                            ->map(fn (mixed $value) => $this->sqlValue($value))
                            ->implode(', ');

                        $sql[] = 'INSERT INTO `'.$quotedTable.'` ('.$columns.') VALUES ('.$values.');';
                    }
                });
            $sql[] = '';
        }

        $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $sql[] = '';

        return implode(PHP_EOL, $sql);
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    private function normalizeValue(mixed $value): array
    {
        return ['value' => is_bool($value) ? $value : trim((string) $value)];
    }
}
