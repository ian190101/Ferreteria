<?php

namespace App\Modules\Settings\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductBranchStock;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Purchases\Models\Supplier;
use App\Modules\Settings\Models\ImportBatch;
use App\Support\BranchAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class BulkImportService
{
    public function import(UploadedFile $file, string $module, int $branchId, ?int $userId = null): ImportBatch
    {
        $batch = ImportBatch::query()->create([
            'user_id' => $userId,
            'module' => $module,
            'file_name' => $file->getClientOriginalName(),
            'status' => 'processing',
        ]);

        $rows = $this->readRows($file);
        $stats = ['total_rows' => count($rows), 'created_rows' => 0, 'updated_rows' => 0, 'failed_rows' => 0, 'errors' => []];

        DB::transaction(function () use ($rows, $module, $branchId, &$stats) {
            foreach ($rows as $index => $row) {
                try {
                    $result = match ($module) {
                        'products' => $this->importProduct($row, $branchId),
                        'customers' => $this->importCustomer($row),
                        'suppliers' => $this->importSupplier($row),
                        default => throw ValidationException::withMessages(['module' => 'Modulo de importacion no soportado.']),
                    };

                    $stats[$result === 'created' ? 'created_rows' : 'updated_rows']++;
                } catch (\Throwable $exception) {
                    $stats['failed_rows']++;
                    $stats['errors'][] = [
                        'fila' => $index + 2,
                        'mensaje' => $exception instanceof ValidationException
                            ? collect($exception->errors())->flatten()->first()
                            : $exception->getMessage(),
                    ];
                }
            }
        });

        $batch->update([
            'status' => $stats['failed_rows'] > 0 ? 'completed_with_errors' : 'completed',
            'total_rows' => $stats['total_rows'],
            'created_rows' => $stats['created_rows'],
            'updated_rows' => $stats['updated_rows'],
            'failed_rows' => $stats['failed_rows'],
            'errors' => array_slice($stats['errors'], 0, 100),
        ]);

        app(ProductionLogService::class)->record('imports', $stats['failed_rows'] > 0 ? 'warning' : 'info', 'bulk_import_completed', 'Importacion masiva finalizada.', [
            'batch_id' => $batch->id,
            'module' => $module,
            'stats' => $stats,
        ]);

        return $batch->refresh();
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'xlsx' => $this->readXlsxRows($file),
            'pdf' => $this->readPdfRows($file),
            default => $this->readCsvRows($file),
        };
    }

    private function readCsvRows(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo cargado.']);
        }

        $headers = null;
        $rows = [];

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            if ($headers === null) {
                $headers = array_map(fn (string $header) => Str::snake(trim($header)), $data);
                continue;
            }

            if (count(array_filter($data, fn ($value) => $value !== null && trim((string) $value) !== '')) === 0) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($data, count($headers), null)) ?: [];
        }

        fclose($handle);

        return $rows;
    }

    private function readXlsxRows(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages([
                'file' => 'El servidor no tiene habilitada la extension ZIP de PHP, necesaria para leer Excel .xlsx.',
            ]);
        }

        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'No se pudo abrir el archivo Excel. Verifica que sea .xlsx valido.']);
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! $sheetXml) {
            throw ValidationException::withMessages(['file' => 'El Excel no tiene una primera hoja legible para importar.']);
        }

        $xml = simplexml_load_string($sheetXml);
        if (! $xml) {
            throw ValidationException::withMessages(['file' => 'No se pudo interpretar la primera hoja del Excel.']);
        }

        $table = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = $this->xlsxColumnIndex($reference);
                $type = (string) ($cell['t'] ?? '');
                $raw = (string) ($cell->v ?? '');
                $inline = isset($cell->is->t) ? (string) $cell->is->t : '';
                $values[$columnIndex] = match ($type) {
                    's' => $sharedStrings[(int) $raw] ?? '',
                    'inlineStr' => $inline,
                    default => $raw,
                };
            }

            if ($values !== []) {
                ksort($values);
                $table[] = array_values($values);
            }
        }

        return $this->rowsFromTable($table, 'Excel');
    }

    private function readPdfRows(UploadedFile $file): array
    {
        $text = $this->extractPdfText($file->getRealPath());

        if (trim($text) === '') {
            throw ValidationException::withMessages([
                'file' => 'No se pudo extraer texto del PDF. Para importar desde PDF, el archivo debe tener texto seleccionable o el servidor debe tener pdftotext instalado.',
            ]);
        }

        $lines = collect(preg_split('/\R+/', $text) ?: [])
            ->map(fn (string $line) => trim(preg_replace('/\s{2,}/', ',', $line) ?? $line))
            ->filter()
            ->values()
            ->all();

        $table = array_map(fn (string $line) => str_getcsv($line), $lines);

        return $this->rowsFromTable($table, 'PDF');
    }

    private function rowsFromTable(array $table, string $source): array
    {
        $table = collect($table)
            ->map(fn (array $row) => array_map(fn ($value) => trim((string) $value), $row))
            ->filter(fn (array $row) => count(array_filter($row, fn ($value) => $value !== '')) > 0)
            ->values();

        if ($table->count() < 2) {
            throw ValidationException::withMessages([
                'file' => "El archivo {$source} necesita una fila de encabezados y al menos una fila de datos.",
            ]);
        }

        $headers = array_map(fn (string $header) => Str::snake(trim($header)), $table->shift());

        return $table
            ->map(fn (array $row) => array_combine($headers, array_pad($row, count($headers), null)) ?: [])
            ->all();
    }

    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! $xml) {
            return [];
        }

        $document = simplexml_load_string($xml);
        if (! $document) {
            return [];
        }

        $strings = [];
        foreach ($document->si ?? [] as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }

            $strings[] = collect($item->r ?? [])->map(fn ($run) => (string) $run->t)->implode('');
        }

        return $strings;
    }

    private function xlsxColumnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: 'A';
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }

    private function extractPdfText(string $path): string
    {
        $command = 'pdftotext -layout '.escapeshellarg($path).' - 2>&1';
        $output = function_exists('shell_exec') ? shell_exec($command) : null;

        if (is_string($output) && trim($output) !== '' && ! str_contains(strtolower($output), 'command not found')) {
            return $output;
        }

        $raw = @file_get_contents($path) ?: '';
        preg_match_all('/\(([^()]*)\)\s*Tj/', $raw, $matches);

        return implode(PHP_EOL, $matches[1] ?? []);
    }

    private function importProduct(array $row, int $branchId): string
    {
        $name = trim((string) ($row['name'] ?? $row['nombre'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'El producto necesita nombre.']);
        }

        $sku = trim((string) ($row['sku'] ?? '')) ?: Str::upper(Str::slug(Str::limit($name, 20, ''), '-')).'-'.Str::upper(Str::random(4));
        $unitSymbol = trim((string) ($row['unit'] ?? $row['unidad'] ?? 'unidad')) ?: 'unidad';
        $unit = ProductUnit::query()->firstOrCreate(
            ['symbol' => $unitSymbol],
            ['name' => $unitSymbol, 'type' => 'quantity', 'is_active' => true],
        );
        $categoryName = trim((string) ($row['category'] ?? $row['categoria'] ?? 'General')) ?: 'General';
        $category = ProductCategory::query()->firstOrCreate(
            ['name' => $categoryName],
            ['is_active' => true],
        );

        $product = Product::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'product_category_id' => $category->id,
                'product_unit_id' => $unit->id,
                'name' => $name,
                'category' => $categoryName,
                'barcode' => trim((string) ($row['barcode'] ?? $row['codigo_barras'] ?? '')) ?: $sku,
                'base_unit' => $unit->symbol,
                'inventory_tracking_mode' => Product::TRACKING_GLOBAL,
                'purchase_price' => (float) str_replace(',', '.', (string) ($row['purchase_price'] ?? $row['precio_compra'] ?? 0)),
                'sale_price' => (float) str_replace(',', '.', (string) ($row['sale_price'] ?? $row['precio_venta'] ?? 0)),
                'minimum_stock_meters' => (float) str_replace(',', '.', (string) ($row['minimum_stock'] ?? $row['stock_minimo'] ?? 0)),
                'is_active' => true,
            ],
        );

        ProductBranchStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['available_meters' => (float) str_replace(',', '.', (string) ($row['stock'] ?? 0))],
        );

        return $product->wasRecentlyCreated ? 'created' : 'updated';
    }

    private function importCustomer(array $row): string
    {
        $name = trim((string) ($row['name'] ?? $row['nombre'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'El cliente necesita nombre.']);
        }

        $customer = Customer::query()->updateOrCreate(
            ['document_number' => trim((string) ($row['document_number'] ?? $row['documento'] ?? $name))],
            [
                'name' => $name,
                'phone' => $row['phone'] ?? $row['telefono'] ?? null,
                'email' => $row['email'] ?? null,
                'address' => $row['address'] ?? $row['direccion'] ?? null,
                'credit_limit' => (float) str_replace(',', '.', (string) ($row['credit_limit'] ?? $row['limite_credito'] ?? 0)),
                'is_active' => true,
            ],
        );

        return $customer->wasRecentlyCreated ? 'created' : 'updated';
    }

    private function importSupplier(array $row): string
    {
        $name = trim((string) ($row['name'] ?? $row['nombre'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'El proveedor necesita nombre.']);
        }

        $supplier = Supplier::query()->updateOrCreate(
            ['tax_id' => trim((string) ($row['tax_id'] ?? $row['nit'] ?? $name))],
            [
                'name' => $name,
                'phone' => $row['phone'] ?? $row['telefono'] ?? null,
                'email' => $row['email'] ?? null,
                'is_active' => true,
            ],
        );

        return $supplier->wasRecentlyCreated ? 'created' : 'updated';
    }
}
