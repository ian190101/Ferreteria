<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Models\AiAssistantKnowledgeSource;
use App\Modules\Inventory\Models\Product;

class AiAssistantKnowledgeIndexService
{
    public function __construct(
        private readonly AiAssistantFastApiClient $fastApi,
    ) {}

    public function indexCatalog(): array
    {
        $documents = [];

        Product::query()
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$documents) {
                foreach ($products as $product) {
                    $content = $this->productContent($product);
                    $source = AiAssistantKnowledgeSource::query()->updateOrCreate(
                        ['source_type' => 'product', 'source_key' => (string) $product->id],
                        [
                            'title' => $product->name,
                            'content' => $content,
                            'metadata' => [
                                'sku' => $product->sku,
                                'item_type' => $product->item_type,
                                'unit' => $product->base_unit,
                                'sellable' => (bool) $product->is_sellable,
                            ],
                            'indexed_at' => now(),
                        ]
                    );

                    $documents[] = [
                        'id' => 'product:'.$product->id,
                        'title' => $source->title,
                        'content' => $source->content,
                        'metadata' => $source->metadata,
                    ];
                }
            });

        $fastApi = $documents === [] ? ['ok' => true, 'indexed' => 0] : $this->fastApi->indexEmbeddings($documents);

        return [
            'indexed' => count($documents),
            'sources_total' => AiAssistantKnowledgeSource::query()->count(),
            'fastapi' => $fastApi,
        ];
    }

    private function productContent(Product $product): string
    {
        $parts = [
            'Producto: '.$product->name,
            'Tipo: '.($product->item_type ?: 'producto'),
            'SKU: '.($product->sku ?: 'sin SKU'),
            'Unidad: '.($product->base_unit ?: $product->unit ?: 'unidad'),
            'Precio venta: Bs '.number_format((float) $product->sale_price, 2, '.', ''),
        ];

        if ($product->duration_minutes) {
            $parts[] = 'Duracion servicio: '.$product->duration_minutes.' minutos';
        }

        return implode("\n", $parts);
    }
}
