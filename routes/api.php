<?php

use App\Http\Controllers\Api\ClientPackageController;
use App\Http\Controllers\Api\ClientSubscriptionController;
use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileClientController;
use App\Http\Controllers\Api\MobileClubController;
use App\Http\Controllers\Api\MobileFriendController;
use App\Http\Controllers\Api\MobilePcController;
use App\Http\Controllers\Api\ClientGameProfileController;
use App\Http\Controllers\Api\ClientHubProfileController;
use App\Http\Controllers\Api\AgentGameProfileController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\ShiftExpenseController;
use App\Http\Controllers\Api\ShiftHistoryController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\ZoneController;
use App\Http\Controllers\Api\ZonePricingWindowController;
use App\Http\Controllers\Api\LayoutController;
use App\Http\Controllers\Cp\CpAuthController;
use App\Http\Controllers\Cp\CpLicenseController;
use App\Http\Controllers\ShellController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientAuthController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\PcController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\PcCommandController;
use App\Http\Controllers\Api\PcPairCodeController;
use App\Http\Controllers\Api\OperatorController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\ShellGameController;
use App\Http\Controllers\Api\OwnerMobileAuthController;
use App\Http\Controllers\Api\BillingLogController;
use App\Http\Controllers\Api\ClubVisualController;
use App\Http\Controllers\Api\NexoraAssistantController;
use App\Http\Controllers\Api\ShellBannerController;
use App\Http\Controllers\Api\ShellBannerManifestController;

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AgentSessionController;

use App\Http\Controllers\Saas\SaasAuthController;
use App\Http\Controllers\Saas\SaasPlanController;
use App\Http\Controllers\Saas\SaasReportController;
use App\Http\Controllers\Saas\TenantController;
use App\Http\Controllers\Saas\LicenseController;
use App\Http\Controllers\Landing\LandingLeadController as PublicLandingLeadController;
use App\Http\Controllers\Saas\LandingLeadController as SaasLandingLeadController;

/**
 * -----------------------------------
 * Public (no auth)
 * -----------------------------------
 */

Route::get('/ping', fn() => response()->json(['ok' => true]));

Route::prefix('saas')->group(function () {
    Route::get('/hello', fn() => response()->json(['saas' => true]));
});

// Operator login (throttle)
Route::middleware('throttle:10,1')->post('/auth/login', [AuthController::class, 'login']);

// Client login (throttle)
Route::middleware('throttle:20,1')->post('/client-auth/login', [ClientAuthController::class, 'login']);
Route::get('/client-auth/state',  [ClientAuthController::class, 'state']);
Route::post('/client-auth/logout', [ClientAuthController::class, 'logout']);
Route::get('/client-auth/settings', [ClientAuthController::class, 'publicSettings']);
Route::post('/pcs/heartbeat', [\App\Http\Controllers\Api\PcHeartbeatController::class, 'heartbeat']);
Route::post('/pcs/commands/ack', [\App\Http\Controllers\Api\PcHeartbeatController::class, 'ack']);
// Agent pair (no pc.device yet)
Route::post('/agent/pair', [AgentController::class, 'pair']);
Route::get('/deployment/quick-install/{code}/script.ps1', [DeploymentController::class, 'publicInstallerScript'])
    ->middleware('throttle:120,1');
Route::get('/deployment/quick-install/{code}/gpo.ps1', [DeploymentController::class, 'publicGpoScript'])
    ->middleware('throttle:120,1');

Route::middleware('throttle:30,1')->post('/landing/leads', [PublicLandingLeadController::class, 'store']);


Route::post('/shell/session-state', [ShellController::class, 'sessionState']);
Route::post('/shell/logout', [ShellController::class, 'logout']);

/**
 * -----------------------------------
 * SaaS (Super Admin)
 * -----------------------------------
 */
Route::prefix('saas')->group(function () {
    Route::middleware('throttle:10,1')->post('/auth/login', [SaasAuthController::class, 'login']);

    Route::middleware('auth:saas')->group(function () {
        Route::get('/auth/me', [SaasAuthController::class, 'me']);
        Route::post('/auth/logout', [SaasAuthController::class, 'logout']);

        // Tenants
        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/tenants/{id}', [TenantController::class, 'show']);
        Route::patch('/tenants/{id}', [TenantController::class, 'update']);

        // SaaS plans
        Route::get('/plans', [SaasPlanController::class, 'index']);
        Route::patch('/plans/{id}', [SaasPlanController::class, 'update']);

        // SaaS reports
        Route::get('/reports/overview', [SaasReportController::class, 'overview']);

        // Landing leads
        Route::get('/landing-leads', [SaasLandingLeadController::class, 'index']);
        Route::patch('/landing-leads/{id}', [SaasLandingLeadController::class, 'update']);

        // Licenses
        Route::get('/licenses', [LicenseController::class, 'index']);
        Route::post('/tenants/{tenantId}/licenses', [LicenseController::class, 'createForTenant']);
        Route::patch('/licenses/{id}', [LicenseController::class, 'update']);
        Route::post('/licenses/{id}/revoke', [LicenseController::class, 'revoke']);

        // Operators
        Route::get('/tenants/{tenantId}/operators', [\App\Http\Controllers\Saas\TenantOperatorController::class, 'index']);
        Route::post('/tenants/{tenantId}/operators', [\App\Http\Controllers\Saas\TenantOperatorController::class, 'store']);
        Route::patch('/operators/{id}', [\App\Http\Controllers\Saas\TenantOperatorController::class, 'update']);
    });
});

Route::prefix('cp')->group(function () {

    // optional: license tekshirib tenant nomini ko‘rsatish
    Route::middleware('throttle:30,1')->post('/license/resolve', [CpLicenseController::class, 'resolve']);

    // CP login (license + login + password)
    Route::middleware('throttle:20,1')->post('/auth/login', [CpAuthController::class, 'login']);

    Route::middleware('auth:operator')->group(function () {
        Route::get('/auth/me', [CpAuthController::class, 'me']);
        Route::post('/auth/logout', [CpAuthController::class, 'logout']);
    });
});

/**
 * -----------------------------------
 * Client-auth protected
 * -----------------------------------
 */
Route::middleware('client.auth')->group(function () {
    Route::get('/client-auth/me', [ClientAuthController::class, 'me']);
    Route::get('/shell/games', [ShellGameController::class, 'index']);
    Route::get('/client/game-profiles', [ClientGameProfileController::class, 'index']);
    Route::get('/client/game-profiles/{gameSlug}', [ClientGameProfileController::class, 'show']);
    Route::post('/client/game-profiles/{gameSlug}', [ClientGameProfileController::class, 'upsert']);
    Route::get('/client/game-profiles/{gameSlug}/download', [ClientGameProfileController::class, 'download']);
    Route::get('/client/hub-profile', [ClientHubProfileController::class, 'show']);
    Route::post('/client/hub-profile', [ClientHubProfileController::class, 'upsert']);
});

/**
 * -----------------------------------
 * Agent (PC device protected)
 * -----------------------------------
 */
Route::middleware(['pc.device', 'throttle:120,1'])->group(function () {
    Route::post('/agent/heartbeat', [AgentController::class, 'heartbeat']);
    Route::get('/agent/settings', [AgentController::class, 'settings']);
    Route::get('/agent/commands/poll', [AgentController::class, 'poll']);
    Route::post('/agent/commands/ack', [AgentController::class, 'ack']);
    Route::get('/agent/shell-banners', [ShellBannerManifestController::class, 'index']);
    Route::get('/agent/profiles/pull', [AgentGameProfileController::class, 'pull']);
    Route::post('/agent/profiles/push', [AgentGameProfileController::class, 'push']);
    Route::get('/agent/profiles/{id}/download', [AgentGameProfileController::class, 'download']);

    Route::post('/agent/sessions/start', [AgentSessionController::class, 'start']);
});

/**
 * -----------------------------------
 * Operator panel (tenant) protected
 * -----------------------------------
 */
Route::middleware('auth:operator')->group(function () {

    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/settings/promo-video', [SettingController::class, 'uploadPromoVideo']);
    Route::post('/settings/agent-installer', [SettingController::class, 'uploadAgentInstaller']);
    Route::post('/settings/client-installer', [SettingController::class, 'uploadClientInstaller']);
    Route::post('/settings/shell-installer', [SettingController::class, 'uploadShellInstaller']);
    Route::post('/club-visuals/upload-image', [ClubVisualController::class, 'uploadImage'])
        ->middleware('require.role:admin,owner');
    Route::post('/club-visuals/upload-audio', [ClubVisualController::class, 'uploadAudio'])
        ->middleware('require.role:admin,owner');
    Route::post('/club-visuals/generate-draft', [ClubVisualController::class, 'generateDraft'])
        ->middleware(['require.role:admin,owner', 'require.feature:ai_generation']);
    Route::post('/shell-banners/upload-image', [ShellBannerController::class, 'uploadImage'])
        ->middleware('require.role:admin,owner');
    Route::post('/shell-banners/upload-logo', [ShellBannerController::class, 'uploadLogo'])
        ->middleware('require.role:admin,owner');
    Route::post('/shell-banners/upload-audio', [ShellBannerController::class, 'uploadAudio'])
        ->middleware('require.role:admin,owner');
    Route::get('/nexora-assistant/overview', [NexoraAssistantController::class, 'overview'])
        ->middleware('require.feature:nexora_ai');
    Route::post('/nexora-assistant/plan', [NexoraAssistantController::class, 'plan'])
        ->middleware('require.feature:nexora_ai');
    Route::post('/nexora-assistant/execute', [NexoraAssistantController::class, 'execute'])
        ->middleware('require.feature:nexora_ai');
    Route::post('/nexora-assistant/speak', [NexoraAssistantController::class, 'speak'])
        ->middleware('require.feature:nexora_ai');
    Route::post('/nexora-assistant/transcribe', [NexoraAssistantController::class, 'transcribe'])
        ->middleware('require.feature:nexora_ai');
    Route::post('/nexora-assistant/autopilot', [NexoraAssistantController::class, 'updateAutopilot'])
        ->middleware(['require.role:admin,owner', 'require.feature:ai_autopilot']);

    // Clients
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::post('/clients/{id}/topup', [ClientController::class, 'topup']);
    Route::post('/clients/bulk-topup', [ClientController::class, 'bulkTopup']);
    Route::get('/clients/{id}/history', [ClientController::class, 'history']);
    Route::post('/clients/{id}/transfer', [TransferController::class, 'store']);
    Route::get('/clients/{id}/sessions', [ClientController::class, 'sessions']);
    Route::get('/clients/{id}/returns/options', [ReturnController::class, 'options']);
    Route::post('/clients/{id}/returns', [ReturnController::class, 'store']);

    Route::get('/returns', [ReturnController::class, 'index']);
    Route::get('/transfers', [TransferController::class, 'index']);
    Route::get('/billing-logs', [BillingLogController::class, 'index']);

    // PCs
    Route::get('/pcs', [PcController::class, 'index']);
    Route::post('/pcs', [PcController::class, 'store']);
    Route::patch('/pcs/{id}', [PcController::class, 'update']);
    Route::delete('/pcs/{id}', [PcController::class, 'destroy']);
    Route::post('/pcs/layout/batch', [PcController::class, 'layoutBatchUpdate']);

    // Layout (hall grid)
    Route::get('/layout', [LayoutController::class, 'index']);
    Route::patch('/layout/grid', [LayoutController::class, 'updateGrid'])
        ->middleware('require.role:admin,owner');
    Route::post('/layout/cells/batch', [LayoutController::class, 'batchUpdate'])
        ->middleware('require.role:admin,owner');

    // Sessions
    Route::get('/sessions/active', [SessionController::class, 'active']);
    Route::post('/sessions/start', [SessionController::class, 'start']);
    Route::post('/sessions/{id}/stop', [SessionController::class, 'stop']);
    Route::post('/sessions/{id}/pause', [SessionController::class, 'pause']);
    Route::post('/sessions/{id}/resume', [SessionController::class, 'resume']);

    // Shifts
    Route::get('/shifts/current', [ShiftController::class, 'current']);
    Route::post('/shifts/open', [ShiftController::class, 'open']);
    Route::post('/shifts/close', [ShiftController::class, 'close']);
    Route::get('/shifts/report', [ShiftController::class, 'report']);
    Route::get('/shifts/current/summary', [ShiftController::class, 'currentSummary']);

    Route::get('/shifts/current/expenses', [ShiftExpenseController::class, 'current']);
    Route::post('/shifts/current/expenses', [ShiftExpenseController::class, 'store']);
    Route::delete('/shifts/expenses/{id}', [ShiftExpenseController::class, 'destroy']);

    Route::get('/shifts/history', [ShiftHistoryController::class, 'index']); // closed shifts
    Route::get('/shifts/history/export', [ShiftHistoryController::class, 'export'])
        ->middleware('require.role:admin,owner');
    Route::get('/shifts/history/export-xlsx', [ShiftHistoryController::class, 'exportXlsx'])
        ->middleware('require.role:admin,owner');
    Route::get('/shifts/{shift}/history', [ShiftHistoryController::class, 'show']); // bitta shift detail

    Route::get('/shifts/history/{id}', [ShiftHistoryController::class, 'show']);

    // Reports (CP)
    Route::get('/reports/overview', [ReportController::class, 'overview'])
        ->middleware('require.role:admin,owner');
    Route::get('/reports/branch-compare', [ReportController::class, 'branchCompare'])
        ->middleware('require.role:admin,owner');
    Route::get('/reports/autopilot', [ReportController::class, 'autopilot'])
        ->middleware(['require.role:admin,owner', 'require.feature:ai_autopilot']);
    Route::post('/reports/autopilot/apply', [ReportController::class, 'autopilotApply'])
        ->middleware(['require.role:admin,owner', 'require.feature:ai_autopilot']);
    Route::get('/reports/exchange', [ReportController::class, 'exchange'])
        ->middleware('require.role:admin,owner');
    Route::post('/reports/exchange/config', [ReportController::class, 'exchangeConfig'])
        ->middleware('require.role:admin,owner');

    Route::get('/promotions', [PromotionController::class,'index']);
    Route::post('/promotions', [PromotionController::class,'store']);
    Route::patch('/promotions/{id}', [PromotionController::class,'update']);
    Route::post('/promotions/{id}/toggle', [PromotionController::class,'toggle']);
    Route::get('/promotions/active-for-topup', [PromotionController::class, 'activeForTopup']);

    Route::get('/zones', [ZoneController::class, 'index']);
    Route::post('/zones', [ZoneController::class, 'store']);
    Route::patch('/zones/{id}', [ZoneController::class, 'update']);
    Route::post('/zones/{id}/toggle', [ZoneController::class, 'toggle']);
    Route::get('/club-visuals', [ClubVisualController::class, 'index'])
        ->middleware('require.role:admin,owner');
    Route::get('/shell-banners', [ShellBannerController::class, 'index'])
        ->middleware('require.role:admin,owner');
    Route::post('/club-visuals', [ClubVisualController::class, 'store'])
        ->middleware('require.role:admin,owner');
    Route::post('/shell-banners', [ShellBannerController::class, 'store'])
        ->middleware('require.role:admin,owner');
    Route::patch('/club-visuals/{id}', [ClubVisualController::class, 'update'])
        ->middleware('require.role:admin,owner');
    Route::patch('/shell-banners/{id}', [ShellBannerController::class, 'update'])
        ->middleware('require.role:admin,owner');
    Route::post('/club-visuals/{id}/toggle', [ClubVisualController::class, 'toggle'])
        ->middleware('require.role:admin,owner');
    Route::post('/shell-banners/{id}/toggle', [ShellBannerController::class, 'toggle'])
        ->middleware('require.role:admin,owner');
    Route::delete('/club-visuals/{id}', [ClubVisualController::class, 'destroy'])
        ->middleware('require.role:admin,owner');
    Route::delete('/shell-banners/{id}', [ShellBannerController::class, 'destroy'])
        ->middleware('require.role:admin,owner');
    Route::get('/zones/{id}/pricing-windows', [ZonePricingWindowController::class, 'index']);
    Route::post('/zones/{id}/pricing-windows', [ZonePricingWindowController::class, 'store']);
    Route::patch('/zones/{id}/pricing-windows/{windowId}', [ZonePricingWindowController::class, 'update']);
    Route::post('/zones/{id}/pricing-windows/{windowId}/toggle', [ZonePricingWindowController::class, 'toggle']);
    Route::delete('/zones/{id}/pricing-windows/{windowId}', [ZonePricingWindowController::class, 'destroy']);

    Route::post('/tenants/join-code/refresh', [\App\Http\Controllers\Api\TenantJoinCodeController::class, 'refresh']);


    Route::get('/packages', [PackageController::class, 'index']);
    Route::post('/packages', [PackageController::class, 'store']);
    Route::patch('/packages/{id}', [PackageController::class, 'update']);
    Route::post('/packages/{id}/toggle', [PackageController::class, 'toggle']);

    Route::post('/clients/{id}/packages/attach', [ClientPackageController::class, 'attach']);

    Route::get('/clients/{id}/packages', [ClientController::class, 'packages']);

    Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
    Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
    Route::patch('/subscription-plans/{id}', [SubscriptionPlanController::class, 'update']);
    Route::post('/subscription-plans/{id}/toggle', [SubscriptionPlanController::class, 'toggle']);

    Route::get('/clients/{id}/subscriptions', [ClientSubscriptionController::class, 'index']);
    Route::post('/clients/{id}/subscribe', [ClientSubscriptionController::class, 'subscribe']);
    Route::post('/clients/{id}/subscriptions/{subId}/cancel', [ClientSubscriptionController::class, 'cancel']);

    // Bookings
    Route::get('/bookings', [BookingController::class,'index']);
    Route::post('/bookings', [BookingController::class,'store']);
    Route::post('/bookings/{id}/cancel', [BookingController::class,'cancel']);

    // PC commands
    Route::post('/pcs/{pcId}/commands', [PcCommandController::class, 'send']);

    // Deployment (iCafe-like rollout)
    Route::post('/deployment/quick-install', [DeploymentController::class, 'quickInstall'])
        ->middleware('require.role:admin,owner');
    Route::post('/deployment/quick-install/bulk', [DeploymentController::class, 'quickInstallBulk'])
        ->middleware('require.role:admin,owner');
    Route::get('/deployment/quick-install/{code}/script.ps1/private', [DeploymentController::class, 'installerScript'])
        ->middleware('require.role:admin,owner');
    Route::get('/deployment/pair-codes', [DeploymentController::class, 'pairCodes'])
        ->middleware('require.role:admin,owner');
    Route::delete('/deployment/pair-codes/{code}', [DeploymentController::class, 'revokePairCode'])
        ->middleware('require.role:admin,owner');
    Route::post('/deployment/rollout', [DeploymentController::class, 'rollout'])
        ->middleware('require.role:admin,owner');
    Route::get('/deployment/batches', [DeploymentController::class, 'batches'])
        ->middleware('require.role:admin,owner');
    Route::get('/deployment/batches/{batchId}', [DeploymentController::class, 'batchStatus'])
        ->middleware('require.role:admin,owner');
    Route::post('/deployment/batches/{batchId}/retry-failed', [DeploymentController::class, 'retryFailed'])
        ->middleware('require.role:admin,owner');

    Route::get('/logs', [LogController::class, 'index'])
        ->middleware('require.role:admin,owner');

    // Pair code (operator creates)
    Route::post('/pcs/pair-code', [PcPairCodeController::class, 'create']);

    // Settings (club profile)
    Route::get('/settings', [SettingController::class,'index'])
        ->middleware('require.role:admin,owner');
    Route::post('/settings', [SettingController::class,'update'])
        ->middleware('require.role:admin,owner');

    // Shell games catalog
    Route::get('/shell-games', [ShellGameController::class, 'adminIndex'])
        ->middleware('require.role:admin,owner');
    Route::post('/shell-games', [ShellGameController::class, 'store'])
        ->middleware('require.role:admin,owner');
    Route::patch('/shell-games/{id}', [ShellGameController::class, 'update'])
        ->middleware('require.role:admin,owner');
    Route::post('/shell-games/{id}/toggle', [ShellGameController::class, 'toggle'])
        ->middleware('require.role:admin,owner');
    Route::post('/pcs/{pcId}/shell-games/{gameId}/state', [ShellGameController::class, 'setPcState'])
        ->middleware('require.role:admin,owner');
});

Route::prefix('mobile')->group(function () {

    // 1) Global mobile login (public)
    Route::post('/auth/login', [MobileAuthController::class, 'login']);
    Route::post('/auth/register', [MobileAuthController::class, 'register']);

    // 2) Mobile token bilan: me, switch club, join club, logout
    Route::middleware('mobile.auth')->group(function () {
        Route::get('/auth/me', [MobileAuthController::class, 'me']);
        Route::get('/auth/profile', [MobileAuthController::class, 'profile']);
        Route::post('/auth/profile', [MobileAuthController::class, 'saveProfile']);
        Route::post('/auth/profile/avatar', [MobileAuthController::class, 'uploadAvatar']);
        Route::post('/auth/switch-club', [MobileAuthController::class, 'switchClub']);
        Route::post('/auth/logout', [MobileAuthController::class, 'logout']);

        // Klub qo‘shish (code orqali)
        Route::post('/club/join', [MobileClubController::class, 'joinByCode']);
        Route::get('/club/preview/{tenantId}', [MobileClubController::class, 'preview']);
        Route::get('/friends', [MobileFriendController::class, 'index']);
        Route::get('/friends/search', [MobileFriendController::class, 'search']);
        Route::post('/friends/requests', [MobileFriendController::class, 'sendRequest']);
        Route::post('/friends/requests/{id}/respond', [MobileFriendController::class, 'respondRequest']);
        Route::delete('/friends/{friendMobileUserId}', [MobileFriendController::class, 'remove']);
    });

    // 3) Club token bilan: client summary, pcs list, booking, open by qr
    Route::middleware('client.auth')->group(function () {
        Route::get('/client/summary', [MobileClientController::class, 'summary']);
        Route::post('/client/missions/{code}/claim', [MobileClientController::class, 'claimMission']);
        Route::get('/club/profile', [MobileClubController::class, 'profile']);
        Route::get('/club/reviews', [MobileClubController::class, 'reviews']);
        Route::post('/club/reviews', [MobileClubController::class, 'saveReview']);
        Route::get('/friends/invites', [MobileFriendController::class, 'invites']);
        Route::post('/friends/invites', [MobileFriendController::class, 'invite']);
        Route::post('/friends/invites/{inviteId}/respond', [MobileFriendController::class, 'respondInvite']);

        Route::get('/pcs', [MobilePcController::class, 'index']);
        Route::post('/pcs/{pcId}/book', [MobilePcController::class, 'book']);
        Route::post('/pcs/party-book', [MobilePcController::class, 'partyBook']);
        Route::post('/pcs/rebook-quick', [MobilePcController::class, 'quickRebook']);
        Route::get('/pcs/smart-seat', [MobilePcController::class, 'smartSeat']);
        Route::post('/pcs/smart-seat/hold', [MobilePcController::class, 'smartSeatHold']);
        Route::get('/client/smart-queue', [MobilePcController::class, 'smartQueueIndex']);
        Route::post('/client/smart-queue/join', [MobilePcController::class, 'smartQueueJoin']);
        Route::delete('/client/smart-queue/{id}', [MobilePcController::class, 'smartQueueCancel']);
        Route::delete('/pcs/{pcId}/book', [MobilePcController::class, 'unbook']);

        Route::post('/pcs/open', [MobilePcController::class, 'openByQr']);
    });
});

/**
 * -----------------------------------
 * Owner mobile (read-only)
 * -----------------------------------
 */
Route::prefix('owner-mobile')->group(function () {
    Route::middleware('throttle:20,1')->post('/auth/login', [OwnerMobileAuthController::class, 'login']);

    Route::middleware(['auth:operator','require.role:owner'])->group(function () {
        Route::get('/auth/me', [OwnerMobileAuthController::class, 'me']);
        Route::post('/auth/logout', [OwnerMobileAuthController::class, 'logout']);
        Route::get('/nexora/overview', [NexoraAssistantController::class, 'overview'])->middleware('require.feature:nexora_ai');
        Route::post('/nexora/plan', [NexoraAssistantController::class, 'plan'])->middleware('require.feature:nexora_ai');
        Route::post('/nexora/execute', [NexoraAssistantController::class, 'execute'])->middleware('require.feature:nexora_ai');
        Route::post('/nexora/speak', [NexoraAssistantController::class, 'speak'])->middleware('require.feature:nexora_ai');
        Route::post('/nexora/transcribe', [NexoraAssistantController::class, 'transcribe'])->middleware('require.feature:nexora_ai');

        // Shifts (read-only)
        Route::get('/shifts/current', [ShiftController::class, 'current']);
        Route::get('/shifts/current/summary', [ShiftController::class, 'currentSummary']);
        Route::get('/shifts/report', [ShiftController::class, 'report']);
        Route::get('/shifts/history', [ShiftHistoryController::class, 'index']);
        Route::get('/shifts/history/{id}', [ShiftHistoryController::class, 'show']);

        // Reports (read-only)
        Route::get('/reports/overview', [ReportController::class, 'overview']);
        Route::get('/reports/cash', [ReportController::class, 'cash']);
        Route::get('/reports/sessions', [ReportController::class, 'sessions']);
        Route::get('/reports/top-clients', [ReportController::class, 'topClients']);
        Route::get('/reports/ai-insights', [ReportController::class, 'aiInsights'])->middleware('require.feature:ai_insights');
        Route::get('/reports/lost-revenue', [ReportController::class, 'lostRevenue']);
        Route::get('/reports/zone-profitability', [ReportController::class, 'zoneProfitability']);
        Route::get('/reports/ab-compare', [ReportController::class, 'abCompare']);
        Route::get('/reports/monthly-pdf', [ReportController::class, 'monthlyPdf']);
        Route::get('/reports/branch-compare', [ReportController::class, 'branchCompare']);
        Route::get('/reports/exchange', [ReportController::class, 'exchange']);
        Route::get('/reports/autopilot', [ReportController::class, 'autopilot'])->middleware('require.feature:ai_autopilot');
    });
});

/**
 * -----------------------------------
 * Admin/Owner only
 * -----------------------------------
 */
Route::middleware(['auth:operator','require.role:admin,owner'])->group(function () {

    // Operators
    Route::get('/operators', [OperatorController::class,'index']);
    Route::post('/operators', [OperatorController::class,'store']);
    Route::patch('/operators/{id}', [OperatorController::class,'update']);

    // Reports
    Route::get('/reports/cash', [ReportController::class,'cash']);
    Route::get('/reports/sessions', [ReportController::class,'sessions']);
    Route::get('/reports/top-clients', [ReportController::class,'topClients']);

});
