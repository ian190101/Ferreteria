<?php

use App\Modules\AiAssistant\Http\Controllers\AiAssistantController;
use App\Modules\AiAssistant\Http\Controllers\AiAssistantExternalApiController;
use App\Modules\AiAssistant\Http\Controllers\AiAssistantSettingsController;
use App\Modules\AiAssistant\Http\Controllers\AiAssistantWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'business_feature:ai_assistant', 'business_capability:uses_ai_assistant', 'permission:ai-assistant.view'])
    ->prefix('ai-assistant')
    ->name('ai-assistant.')
    ->group(function () {
        Route::get('/', [AiAssistantController::class, 'index'])->name('index');
        Route::post('/send', [AiAssistantController::class, 'send'])->middleware('permission:ai-assistant.chat')->name('send');
        Route::get('/files/{message}', [AiAssistantController::class, 'download'])->middleware('permission:ai-assistant.chat')->name('files.download');
        Route::post('/actions/{toolRun}/confirm', [AiAssistantController::class, 'confirm'])->middleware('permission:ai-assistant.actions.approve')->name('actions.confirm');
        Route::post('/actions/{toolRun}/cancel', [AiAssistantController::class, 'cancel'])->middleware('permission:ai-assistant.chat')->name('actions.cancel');
    });

Route::middleware(['auth', 'verified', 'system_superadmin'])
    ->prefix('system-superadmin/ai-assistant')
    ->name('ai-assistant.settings.')
    ->group(function () {
        Route::get('/', [AiAssistantSettingsController::class, 'index'])->name('index');
        Route::post('/channels', [AiAssistantSettingsController::class, 'storeChannel'])->middleware('permission:ai-assistant.credentials.manage')->name('channels.store');
        Route::post('/api-clients', [AiAssistantSettingsController::class, 'storeApiClient'])->middleware('permission:ai-assistant.external-api.manage')->name('api-clients.store');
        Route::patch('/api-clients/{client}/revoke', [AiAssistantSettingsController::class, 'revokeApiClient'])->middleware('permission:ai-assistant.external-api.manage')->name('api-clients.revoke');
        Route::post('/health', [AiAssistantSettingsController::class, 'health'])->middleware('permission:ai-assistant.manage')->name('health');
        Route::post('/knowledge/index', [AiAssistantSettingsController::class, 'indexKnowledge'])->middleware('permission:ai-assistant.manage')->name('knowledge.index');
    });

Route::prefix('ai-assistant/webhooks')
    ->name('ai-assistant.webhooks.')
    ->group(function () {
        Route::post('/telegram/{channel}', [AiAssistantWebhookController::class, 'telegram'])->middleware('throttle:ai-assistant-webhook')->name('telegram');
        Route::get('/meta/verify', [AiAssistantWebhookController::class, 'metaVerify'])->middleware('throttle:60,1')->name('meta.verify');
        Route::post('/meta/{channel}', [AiAssistantWebhookController::class, 'meta'])->middleware('throttle:ai-assistant-webhook')->name('meta');
    });

Route::prefix('api/ai-assistant/v1')
    ->name('ai-assistant.external.')
    ->group(function () {
        Route::get('/status', [AiAssistantExternalApiController::class, 'status'])->middleware('throttle:ai-assistant-external')->name('status');
        Route::post('/chat', [AiAssistantExternalApiController::class, 'chat'])->middleware('throttle:ai-assistant-external')->name('chat');
        Route::post('/transcribe', [AiAssistantExternalApiController::class, 'transcribe'])->middleware('throttle:ai-assistant-external')->name('transcribe');
        Route::post('/tools', [AiAssistantExternalApiController::class, 'tools'])->middleware('throttle:ai-assistant-external')->name('tools');
    });
