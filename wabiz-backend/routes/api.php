<?php

use App\Http\Controllers\Api\AiAssistantSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationHandoffNotificationController;
use App\Http\Controllers\Api\CsMemberController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\WhatsAppConnectionController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'Wabiz API is running',
    ]);
});

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::prefix('webhooks/whatsapp/{provider}')
    ->where(['provider' => '[a-z][a-z0-9_]{0,99}'])
    ->middleware('throttle:120,1')
    ->group(function () {
        Route::get('/', [WhatsAppWebhookController::class, 'verify']);
        Route::post('/', [WhatsAppWebhookController::class, 'receive']);
    });

Route::post('/webhooks/whatsapp/wppconnect/{token}', [WhatsAppWebhookController::class, 'receiveWppConnect'])
    ->middleware('throttle:120,1')
    ->where('token', '[A-Za-z0-9._~-]{32,256}');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/ai/settings', [AiAssistantSettingsController::class, 'show']);
    Route::put('/ai/settings', [AiAssistantSettingsController::class, 'update']);

    Route::get('/whatsapp/connections', [WhatsAppConnectionController::class, 'index']);
    Route::post('/whatsapp/connections', [WhatsAppConnectionController::class, 'store']);
    Route::get('/whatsapp/connections/{connection}/status', [WhatsAppConnectionController::class, 'status']);
    Route::post('/whatsapp/connections/{connection}/start', [WhatsAppConnectionController::class, 'start']);
    Route::get('/whatsapp/connections/{connection}/qr', [WhatsAppConnectionController::class, 'qr']);
    Route::post('/whatsapp/connections/{connection}/disconnect', [WhatsAppConnectionController::class, 'disconnect']);
    Route::post('/whatsapp/connections/{connection}/verify', [WhatsAppConnectionController::class, 'verify']);
    Route::get('/whatsapp/connections/{connection}', [WhatsAppConnectionController::class, 'show']);
    Route::put('/whatsapp/connections/{connection}', [WhatsAppConnectionController::class, 'update']);
    Route::delete('/whatsapp/connections/{connection}', [WhatsAppConnectionController::class, 'destroy']);

    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::put('/campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update']);
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
    Route::post('/campaigns/{campaign}/send', [CampaignController::class, 'send']);
    Route::post('/campaigns/{campaign}/schedule', [CampaignController::class, 'schedule']);
    Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
    Route::get('/campaigns/{campaign}/recipients', [CampaignController::class, 'recipients']);

    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);
    Route::post('/clients/{client}/whatsapp-consent', [ClientController::class, 'recordWhatsAppConsent']);
    Route::delete('/clients/{client}', [ClientController::class, 'destroy']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
    Route::post('/conversations/{conversation}/reset', [ConversationController::class, 'reset']);
    Route::post('/conversations/{conversation}/messages/{message}/reconcile-ai-delivery', [ConversationController::class, 'reconcileAiDelivery']);
    Route::post('/conversations/{conversation}/handoff-notification/retry', [ConversationHandoffNotificationController::class, 'retry']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage']);
    Route::post('/conversations/{conversation}/messages/send', [ConversationController::class, 'sendMessage']);
    Route::patch('/conversations/{conversation}/status', [ConversationController::class, 'updateStatus']);
    Route::patch('/conversations/{conversation}/assignment', [ConversationController::class, 'updateAssignment']);

    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::post('/departments', [DepartmentController::class, 'store']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::put('/departments/{department}', [DepartmentController::class, 'update']);
    Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

    Route::get('/cs-members', [CsMemberController::class, 'index']);
    Route::post('/cs-members', [CsMemberController::class, 'store']);
    Route::get('/cs-members/{csMember}/linkable-users', [CsMemberController::class, 'linkableUsers']);
    Route::get('/cs-members/{csMember}', [CsMemberController::class, 'show']);
    Route::put('/cs-members/{csMember}', [CsMemberController::class, 'update']);
    Route::delete('/cs-members/{csMember}', [CsMemberController::class, 'destroy']);
});
