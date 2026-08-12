<?php

use App\Models\User;
use App\Modules\AiAssistant\Models\AiAssistantApiClient;
use App\Modules\AiAssistant\Models\AiAssistantChannel;
use App\Modules\AiAssistant\Models\AiAssistantConversation;
use App\Modules\AiAssistant\Models\AiAssistantKnowledgeSource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Sale;
use App\Modules\SystemSuperadmin\Models\BusinessProfile;
use App\Modules\SystemSuperadmin\Services\BusinessProfileConfiguration;
use App\Support\SystemCacheInvalidator;
use App\Support\SystemRoles;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function aiAssistantBranch(): Branch
{
    return Branch::query()->create([
        'name' => 'Sucursal IA',
        'code' => 'IA-'.uniqid(),
        'barcode' => 'BR-IA-'.uniqid(),
        'address' => 'Cochabamba',
        'is_active' => true,
    ]);
}

function aiAssistantUser(array $permissions, bool $system = false): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate([
        'name' => $system ? SystemRoles::SYSTEM_SUPERADMIN : 'ia-test',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions($permissions);

    $user = User::factory()->create([
        'branch_id' => aiAssistantBranch()->id,
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function activateAiAssistantProfile(array $overrides = []): void
{
    BusinessProfile::query()->create([
        'name' => 'Perfil IA',
        'business_type' => 'hardware_store',
        'status' => 'active',
        'configuration' => BusinessProfileConfiguration::normalized(array_replace_recursive([
            'modules' => [
                'ai_assistant' => true,
                'sales_notes' => true,
                'reports' => true,
                'inventory' => true,
                'exports' => true,
            ],
            'capabilities' => [
                'uses_ai_assistant' => true,
                'uses_ai_internal_chat' => true,
                'uses_ai_owner_analytics' => true,
                'uses_ai_voice' => true,
                'uses_ai_exports' => true,
                'uses_ai_order_capture' => true,
                'uses_ai_external_api' => true,
            ],
            'feature_flags' => [
                'ai_assistant_engine' => true,
            ],
            'ai_assistant' => [
                'enabled' => true,
                'model_mode' => 'cpu_light',
                'fastapi' => [
                    'url' => 'https://ai.test',
                    'timeout_seconds' => 3,
                    'internal_token' => 'test-token',
                ],
            ],
        ], $overrides)),
        'applied_at' => now(),
    ]);

    SystemCacheInvalidator::bumpOperational();
}

it('mantiene bloqueado el chatbot IA por defecto si el perfil no lo activa', function () {
    $user = aiAssistantUser(['ai-assistant.view', 'ai-assistant.chat']);

    $this->actingAs($user)
        ->get(route('ai-assistant.index'))
        ->assertNotFound();
});

it('muestra chat interno y responde usando herramientas seguras del ERP', function () {
    Http::fake([
        'https://ai.test/chat' => Http::response(['answer' => null], 200),
    ]);
    activateAiAssistantProfile();
    $user = aiAssistantUser(['ai-assistant.view', 'ai-assistant.chat', 'sales.view', 'reports.view', 'inventory.products.view']);

    Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'document_type' => 'sale_note',
        'receipt_number' => 'NV-IA-1',
        'status' => 'issued',
        'subtotal' => 100,
        'discount_total' => 0,
        'total' => 100,
        'balance_due' => 0,
        'sold_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ai-assistant.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AiAssistant/Index', false)
            ->where('voiceEnabled', true));

    $this->actingAs($user)
        ->post(route('ai-assistant.send'), ['message' => 'Cuanto vendi este mes?'])
        ->assertRedirect(route('ai-assistant.index'));

    expect(AiAssistantConversation::query()->count())->toBe(1)
        ->and(AiAssistantConversation::first()->messages()->count())->toBe(2)
        ->and(AiAssistantConversation::first()->messages()->latest()->first()->content)->toContain('Ventas del');
});

it('permite a sistemasuperadmin guardar canales credenciales cifradas y clientes API', function () {
    activateAiAssistantProfile();
    $user = aiAssistantUser([
        'ai-assistant.manage',
        'ai-assistant.credentials.manage',
        'ai-assistant.external-api.manage',
    ], true);

    $this->actingAs($user)
        ->get(route('ai-assistant.settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AiAssistant/Settings', false));

    $this->actingAs($user)
        ->post(route('ai-assistant.settings.channels.store'), [
            'provider' => 'telegram',
            'name' => 'Bot pruebas',
            'status' => 'active',
            'webhook_url' => 'https://erp.test/ai-assistant/webhooks/telegram/1',
            'credentials_json' => '{"bot_token":"123:abc"}',
            'allowed_scopes' => ['chat', 'voice', 'orders'],
            'settings_json' => '{"mode":"test"}',
        ])
        ->assertRedirect(route('ai-assistant.settings.index'));

    $channel = AiAssistantChannel::query()->firstOrFail();
    expect($channel->encrypted_credentials)->not->toContain('123:abc')
        ->and(Crypt::decryptString($channel->encrypted_credentials))->toContain('123:abc');

    $this->actingAs($user)
        ->post(route('ai-assistant.settings.api-clients.store'), [
            'name' => 'Sistema externo',
            'scopes' => ['chat', 'external_api'],
            'rate_limit_per_minute' => 20,
        ])
        ->assertRedirect(route('ai-assistant.settings.index'));

    expect(AiAssistantApiClient::query()->count())->toBe(1);
});

it('permite chat y herramientas por API externa solo con token y scopes validos', function () {
    Http::fake([
        'https://ai.test/chat' => Http::response(['answer' => null], 200),
    ]);
    activateAiAssistantProfile();
    $user = aiAssistantUser([
        'ai-assistant.view',
        'ai-assistant.chat',
        'ai-assistant.reports.generate',
        'sales.view',
        'reports.view',
        'settings.manage',
    ]);
    $token = 'aia_prueba_segura';

    AiAssistantApiClient::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'name' => 'Integracion QA',
        'token_hash' => hash('sha256', $token),
        'scopes' => ['chat', 'external_api', 'reports'],
        'status' => 'active',
        'rate_limit_per_minute' => 30,
    ]);

    Sale::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'document_type' => 'sale_note',
        'receipt_number' => 'NV-API-1',
        'status' => 'issued',
        'subtotal' => 150,
        'discount_total' => 0,
        'total' => 150,
        'balance_due' => 0,
        'sold_at' => now(),
    ]);

    $this->withToken($token)
        ->postJson(route('ai-assistant.external.chat'), [
            'message' => 'Cuanto vendi este mes?',
            'external_user_ref' => 'qa-externo',
        ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('intent', 'consultar_ventas');

    $this->withToken($token)
        ->postJson(route('ai-assistant.external.tools'), [
            'tool' => 'generar_exportacion_pdf',
            'input' => ['message' => 'Genera pdf de ventas'],
        ])
        ->assertOk()
        ->assertJsonPath('result.status', 'completed')
        ->assertJsonPath('result.format', 'pdf');

    $this->withHeader('Authorization', '')
        ->postJson(route('ai-assistant.external.chat'), ['message' => 'hola'])
        ->assertUnauthorized();
});

it('bloquea herramientas externas cuando el token no tiene el scope requerido', function () {
    activateAiAssistantProfile();
    $user = aiAssistantUser(['sales.view']);
    $token = 'aia_sin_reportes';

    AiAssistantApiClient::query()->create([
        'branch_id' => $user->branch_id,
        'user_id' => $user->id,
        'name' => 'Integracion limitada',
        'token_hash' => hash('sha256', $token),
        'scopes' => ['external_api'],
        'status' => 'active',
        'rate_limit_per_minute' => 30,
    ]);

    $this->withToken($token)
        ->postJson(route('ai-assistant.external.tools'), [
            'tool' => 'generar_exportacion_pdf',
            'input' => ['message' => 'Genera pdf de ventas'],
        ])
        ->assertForbidden();
});

it('procesa webhook de Telegram y registra conversacion con secreto configurado', function () {
    Http::fake([
        'https://ai.test/chat' => Http::response(['answer' => null], 200),
        'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);
    activateAiAssistantProfile();
    $user = aiAssistantUser(['ai-assistant.view', 'ai-assistant.chat', 'inventory.products.view']);

    $channel = AiAssistantChannel::query()->create([
        'branch_id' => $user->branch_id,
        'provider' => AiAssistantChannel::PROVIDER_TELEGRAM,
        'name' => 'Telegram QA',
        'status' => AiAssistantChannel::STATUS_ACTIVE,
        'encrypted_credentials' => Crypt::encryptString('{"bot_token":"123:abc"}'),
        'allowed_scopes' => ['chat', 'voice', 'orders'],
        'settings' => [
            'user_id' => $user->id,
            'secret_token' => 'telegram-secret',
        ],
    ]);

    Customer::query()->create([
        'name' => 'Cliente prueba',
        'document_number' => '123',
        'phone' => '70000000',
        'is_active' => true,
    ]);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'telegram-secret')
        ->postJson(route('ai-assistant.webhooks.telegram', $channel), [
            'message' => [
                'chat' => ['id' => '99'],
                'from' => ['id' => '77'],
                'text' => 'precio calamina',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('status', 'processed');

    expect(AiAssistantConversation::query()->where('external_user_ref', '77')->exists())->toBeTrue();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'incorrecto')
        ->postJson(route('ai-assistant.webhooks.telegram', $channel), [
            'message' => ['chat' => ['id' => '99'], 'from' => ['id' => '77'], 'text' => 'hola'],
        ])
        ->assertForbidden();
});

it('indexa catalogo permitido para RAG desde sistemasuperadmin', function () {
    Http::fake([
        'https://ai.test/embed/index' => Http::response(['ok' => true, 'indexed' => 1], 200),
    ]);
    activateAiAssistantProfile();
    $user = aiAssistantUser(['ai-assistant.manage'], true);

    Product::query()->create([
        'name' => 'Servicio delivery IA',
        'sku' => 'IA-SERV-1',
        'barcode' => 'IA-SERV-1',
        'category' => 'Servicios',
        'item_type' => 'service',
        'base_unit' => 'servicio',
        'is_sellable' => true,
        'is_purchasable' => false,
        'is_inventory_item' => false,
        'is_consumable' => false,
        'is_prepared' => false,
        'is_digital' => false,
        'sale_price' => 25,
        'purchase_price' => 0,
        'minimum_stock_meters' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('ai-assistant.settings.knowledge.index'))
        ->assertRedirect(route('ai-assistant.settings.index'));

    expect(AiAssistantKnowledgeSource::query()->where('source_type', 'product')->count())->toBe(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/embed/index')
        && data_get($request->data(), 'documents.0.title') === 'Servicio delivery IA');
});
