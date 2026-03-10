<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasskeyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SSOController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SettingController;
// NotificationSettingsController deprecated - routes now use UserNotificationSettingsController
use App\Http\Controllers\Api\NotificationChannelConfigController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\MailSettingController;
use App\Http\Controllers\Api\NotificationSettingController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\StorageSettingController;
use App\Http\Controllers\Api\FileManagerController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BackupSettingController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\NotificationTemplateController;
use App\Http\Controllers\Api\NovuController;
use App\Http\Controllers\Api\NovuSettingController;
use App\Http\Controllers\Api\VersionController;
use App\Http\Controllers\Api\LLMController;
use App\Http\Controllers\Api\LLMModelController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\LLMSettingController;
use App\Http\Controllers\Api\AuthSettingController;
use App\Http\Controllers\Api\SSOSettingController;
use App\Http\Controllers\Api\UserSettingController;
use App\Http\Controllers\Api\UserNotificationSettingsController;
use App\Http\Controllers\Api\AppLogExportController;
use App\Http\Controllers\Api\LogRetentionController;
use App\Http\Controllers\Api\SuspiciousActivityController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Admin\SearchAdminController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\ChangelogController;
use App\Http\Controllers\Api\NotificationDeliveryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\GraphQLSettingController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\StripeConnectController;
use App\Http\Controllers\Api\StripeSettingController;
use App\Http\Controllers\Api\StripePaymentController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\SpamFilterController;
use App\Http\Controllers\Api\EmailRuleController;
use App\Http\Controllers\Api\EmailAIController;
use App\Http\Controllers\Api\EmailWebhookController;
use App\Http\Controllers\Api\EmailDomainController;
use App\Http\Controllers\Api\MailboxController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\EmailThreadController;
use App\Http\Controllers\Api\EmailLabelController;
use App\Http\Controllers\Api\EmailAttachmentController;
use App\Http\Controllers\Api\EmailHostingSettingController;
use App\Http\Controllers\Api\EmailProviderAccountController;
use App\Http\Controllers\Api\EmailImportController;
use App\Http\Controllers\Api\ProviderManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::get('/version', [VersionController::class, 'index']);
Route::get('/health', fn() => response()->json(['status' => 'ok']));

// SSO Provider Info (public)
Route::get('/auth/sso/providers', [SSOController::class, 'providers']);

// Public system settings and branding (no auth required)
Route::get('/system-settings/public', [SystemSettingController::class, 'publicSettings']);
Route::get('/branding', [BrandingController::class, 'show']);

// Client error reporting (rate limited, no auth)
Route::post('/client-errors', [ClientErrorController::class, 'store'])
    ->middleware('throttle:10,1');

// Stripe webhook (public, no auth — signature verified in controller)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:60,1');

// Email webhooks (public, no auth — signature verified in controller)
Route::post('/email/webhook/{provider}', [EmailWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');
Route::post('/email/webhook/{provider}/events', [EmailWebhookController::class, 'handleEvent'])
    ->middleware('throttle:120,1');

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    // Email availability check for signup (rate limited to prevent enumeration)
    Route::post('/check-email', [AuthController::class, 'checkEmail'])
        ->middleware('throttle:10,1');

    // Registration & Login (rate limited)
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('rate.sensitive:register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('rate.sensitive:login');
    
    // Password Reset (rate limited)
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('rate.sensitive:password_reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('rate.sensitive:password_reset');
    
    // Email Verification
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])
        ->middleware('auth:sanctum');
    
    // SSO Routes
    // NOTE: The redirect (/sso/{provider}) and callback (/callback/{provider})
    // routes are in routes/web.php, NOT here. They need the 'web' middleware
    // for proper session/cookie handling during OAuth flows. See web.php comments.
    Route::post('/sso/{provider}/link', [SSOController::class, 'link'])
        ->middleware('auth:sanctum');
    Route::delete('/sso/{provider}/unlink', [SSOController::class, 'unlink'])
        ->middleware('auth:sanctum');
    
    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->middleware('2fa.setup');
        Route::post('/logout', [AuthController::class, 'logout']);
        
        // 2FA Routes
        Route::prefix('2fa')->group(function () {
            Route::get('/status', [TwoFactorController::class, 'status']);
            Route::post('/enable', [TwoFactorController::class, 'enable']);
            Route::post('/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('/disable', [TwoFactorController::class, 'disable']);
            // 2FA verify is rate limited to prevent brute force
            Route::post('/verify', [TwoFactorController::class, 'verify'])
                ->withoutMiddleware('auth:sanctum')
                ->middleware('rate.sensitive:2fa');
            Route::get('/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::post('/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        });

        // Passkey Routes (authenticated management)
        Route::prefix('passkeys')->group(function () {
            Route::get('/', [PasskeyController::class, 'index']);
            Route::post('/register/options', [PasskeyController::class, 'registerOptions']);
            Route::post('/register', [PasskeyController::class, 'register']);
            Route::put('/{id}', [PasskeyController::class, 'update']);
            Route::delete('/{id}', [PasskeyController::class, 'destroy']);
        });
    });

    // Passkey login (unauthenticated, rate limited)
    Route::post('/passkeys/login/options', [PasskeyController::class, 'loginOptions'])
        ->middleware('throttle:10,1');
    Route::post('/passkeys/login', [PasskeyController::class, 'login'])
        ->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'verified', '2fa.setup'])->group(function () {

    // Profile
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::put('/password', [ProfileController::class, 'updatePassword']);
        Route::delete('/', [ProfileController::class, 'destroy']);
    });

    // Novu subscriber HMAC (for Inbox auth)
    Route::get('/novu/subscriber-hash', [NovuController::class, 'subscriberHash']);

    // User Settings
    Route::prefix('user')->group(function () {
        Route::get('/settings', [UserSettingController::class, 'show']);
        Route::put('/settings', [UserSettingController::class, 'update']);
        Route::post('/settings/detect-timezone', [UserSettingController::class, 'detectTimezone']);
        Route::get('/notification-settings', [UserNotificationSettingsController::class, 'show']);
        Route::put('/notification-settings', [UserNotificationSettingsController::class, 'update']);
        Route::get('/notification-settings/type-preferences', [UserNotificationSettingsController::class, 'typePreferences']);
        Route::put('/notification-settings/type-preferences', [UserNotificationSettingsController::class, 'updateTypePreference']);
        Route::get('/webpush-subscriptions', [UserNotificationSettingsController::class, 'listWebPushSubscriptions']);
        Route::post('/webpush-subscription', [UserNotificationSettingsController::class, 'storeWebPushSubscription']);
        Route::delete('/webpush-subscription', [UserNotificationSettingsController::class, 'destroyWebPushSubscription']);
        Route::delete('/webpush-subscription/{id}', [UserNotificationSettingsController::class, 'destroyWebPushSubscriptionById'])->whereNumber('id');
    });

    // Dashboard (static widget data)
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Onboarding (wizard status and progress)
    Route::prefix('onboarding')->group(function () {
        Route::get('/status', [OnboardingController::class, 'status']);
        Route::post('/wizard/complete', [OnboardingController::class, 'completeWizard']);
        Route::post('/wizard/dismiss', [OnboardingController::class, 'dismissWizard']);
        Route::post('/wizard/step', [OnboardingController::class, 'completeStep']);
        Route::post('/wizard/reset', [OnboardingController::class, 'resetWizard']);
    });
    
    // Changelog (authenticated, read-only)
    Route::get('/changelog', [ChangelogController::class, 'index']);

    // Settings (permission: settings.view / settings.edit)
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingController::class, 'index'])->middleware('can:settings.view');
        Route::put('/', [SettingController::class, 'update'])->middleware('can:settings.edit');
        // Legacy routes - deprecated, use /user/notification-settings instead
        Route::get('/notifications', [UserNotificationSettingsController::class, 'show']);
        Route::put('/notifications', [UserNotificationSettingsController::class, 'update']);
        Route::get('/{group}', [SettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/{group}', [SettingController::class, 'updateGroup'])->middleware('can:settings.edit');
    });

    // System Settings (permission: settings.view / settings.edit)
    Route::prefix('system-settings')->group(function () {
        Route::get('/', [SystemSettingController::class, 'index'])->middleware('can:settings.view');
        Route::put('/', [SystemSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::get('/{group}', [SystemSettingController::class, 'show'])->middleware('can:settings.view');
    });

    // Admin notification channel config (permission: settings.view / settings.edit)
    Route::prefix('admin')->group(function () {
        Route::get('notification-channels', [NotificationChannelConfigController::class, 'index'])->middleware('can:settings.view');
        Route::put('notification-channels', [NotificationChannelConfigController::class, 'update'])->middleware('can:settings.edit');
        Route::post('notification-channels/test-all', [NotificationChannelConfigController::class, 'testAll'])->middleware('can:settings.edit');
        Route::get('notification-channels/verify', [NotificationChannelConfigController::class, 'verify'])->middleware('can:settings.view');
    });
    
    // Search
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/search/suggestions', [SearchController::class, 'suggestions']);

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/mark-read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);
        Route::post('/delete-batch', [NotificationController::class, 'destroyBatch']);

        // Test notification (throttled: 5 per minute)
        Route::post('/test/{channel}', [NotificationController::class, 'test'])->middleware('throttle:5,1');

        // Push notification diagnostic
        Route::get('/diagnose-push', [NotificationController::class, 'diagnosePush']);
    });
    
    // LLM/AI (permission: settings.view / settings.edit)
    Route::prefix('llm')->group(function () {
        Route::get('/providers', [LLMController::class, 'providers'])->middleware('can:settings.view');
        Route::post('/providers', [LLMController::class, 'storeProvider'])->middleware('can:settings.edit');
        Route::put('/providers/{provider}', [LLMController::class, 'updateProvider'])->middleware('can:settings.edit');
        Route::delete('/providers/{provider}', [LLMController::class, 'destroyProvider'])->middleware('can:settings.edit');
        Route::get('/config', [LLMController::class, 'config'])->middleware('can:settings.view');
        Route::put('/config', [LLMController::class, 'updateConfig'])->middleware('can:settings.edit');
        Route::post('/test/{provider}', [LLMController::class, 'testProvider'])->middleware('can:settings.edit');
        Route::post('/query', [LLMController::class, 'query'])->middleware('can:settings.view');
        Route::post('/query/vision', [LLMController::class, 'visionQuery'])->middleware('can:settings.view');
    });

    // Backup & Restore (permission: backups.view / backups.create / backups.restore / backups.delete)
    Route::prefix('backup')->group(function () {
        Route::get('/', [BackupController::class, 'index'])->middleware('can:backups.view');
        Route::post('/create', [BackupController::class, 'create'])->middleware('can:backups.create');
        Route::get('/download/{filename}', [BackupController::class, 'download'])->middleware('can:backups.view');
        Route::post('/restore', [BackupController::class, 'restore'])->middleware('can:backups.restore');
        Route::delete('/{filename}', [BackupController::class, 'destroy'])->middleware('can:backups.delete');
    });

    // User Management (permission: users.view / users.create / users.edit / users.delete)
    Route::prefix('users')->group(function () {
        Route::get('/', [UserController::class, 'index'])->middleware('can:users.view');
        Route::post('/', [UserController::class, 'store'])->middleware('can:users.create');
        Route::get('/{user}', [UserController::class, 'show'])->middleware('can:users.view');
        Route::put('/{user}', [UserController::class, 'update'])->middleware('can:users.edit');
        Route::delete('/{user}', [UserController::class, 'destroy'])->middleware('can:users.delete');
        Route::post('/{user}/toggle-admin', [UserController::class, 'toggleAdmin'])->middleware('can:users.edit');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('can:users.edit');
        Route::post('/{user}/disable', [UserController::class, 'toggleDisabled'])->middleware('can:users.edit');
        Route::post('/{user}/resend-verification', [UserController::class, 'resendVerification'])->middleware('can:users.edit');
        Route::put('/{user}/groups', [UserController::class, 'updateGroups'])->middleware('can:users.edit');
    });

    // Permissions list (permission: groups.view, for UI dropdowns)
    Route::get('/permissions', [GroupController::class, 'availablePermissions'])->middleware('can:groups.view');

    // User Groups (permission: groups.view / groups.manage)
    Route::prefix('groups')->group(function () {
        Route::get('/', [GroupController::class, 'index'])->middleware('can:groups.view');
        Route::post('/', [GroupController::class, 'store'])->middleware('can:groups.manage');
        Route::get('/{group}', [GroupController::class, 'show'])->middleware('can:groups.view');
        Route::put('/{group}', [GroupController::class, 'update'])->middleware('can:groups.manage');
        Route::delete('/{group}', [GroupController::class, 'destroy'])->middleware('can:groups.manage');
        Route::get('/{group}/members', [GroupController::class, 'members'])->middleware('can:groups.view');
        Route::post('/{group}/members', [GroupController::class, 'addMembers'])->middleware('can:groups.manage');
        Route::delete('/{group}/members/{user}', [GroupController::class, 'removeMember'])->middleware('can:groups.manage');
        Route::get('/{group}/permissions', [GroupController::class, 'permissions'])->middleware('can:groups.view');
        Route::put('/{group}/permissions', [GroupController::class, 'updatePermissions'])->middleware('can:groups.manage');
    });

    // Audit Logs (permission: audit.view / logs.export)
    Route::prefix('audit-logs')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->middleware('can:audit.view');
        Route::get('/export', [AuditLogController::class, 'export'])->middleware('can:logs.export');
        Route::get('/stats', [AuditLogController::class, 'stats'])->middleware('can:audit.view');
    });

    // Application logs export (permission: logs.export)
    Route::prefix('app-logs')->group(function () {
        Route::get('/recent', [AppLogExportController::class, 'recent'])->middleware('can:logs.export');
        Route::get('/export', [AppLogExportController::class, 'export'])->middleware('can:logs.export');
    });

    // Log retention and cleanup config (permission: settings.edit)
    Route::prefix('log-retention')->group(function () {
        Route::get('/', [LogRetentionController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [LogRetentionController::class, 'update'])->middleware('can:settings.edit');
    });

    // Integration Usage (permission: usage.view / logs.export)
    Route::prefix('usage')->group(function () {
        Route::get('/stats', [UsageController::class, 'stats'])->middleware('can:usage.view');
        Route::get('/breakdown', [UsageController::class, 'breakdown'])->middleware('can:usage.view');
        Route::get('/export', [UsageController::class, 'export'])->middleware('can:logs.export');
    });

    // Suspicious activity alerts (permission: logs.view)
    Route::get('/suspicious-activity', [SuspiciousActivityController::class, 'index'])->middleware('can:logs.view');
    
    // Email Templates (permission: settings.view / settings.edit)
    Route::prefix('email-templates')->group(function () {
        Route::get('/', [EmailTemplateController::class, 'index'])->middleware('can:settings.view');
        Route::get('/{key}', [EmailTemplateController::class, 'show'])->middleware('can:settings.view');
        Route::put('/{key}', [EmailTemplateController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/{key}/preview', [EmailTemplateController::class, 'preview'])->middleware('can:settings.view');
        Route::post('/{key}/test', [EmailTemplateController::class, 'test'])->middleware('can:settings.edit');
        Route::post('/{key}/reset', [EmailTemplateController::class, 'reset'])->middleware('can:settings.edit');
    });

    // Notification Templates (permission: settings.view / settings.edit)
    Route::prefix('notification-templates')->group(function () {
        Route::get('/', [NotificationTemplateController::class, 'index'])->middleware('can:settings.view');
        Route::get('/{id}', [NotificationTemplateController::class, 'show'])->middleware('can:settings.view');
        Route::put('/{id}', [NotificationTemplateController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/{id}/preview', [NotificationTemplateController::class, 'preview'])->middleware('can:settings.view');
        Route::post('/{id}/reset', [NotificationTemplateController::class, 'reset'])->middleware('can:settings.edit');
    });

    // Mail Settings (permission: settings.view / settings.edit)
    Route::prefix('mail-settings')->group(function () {
        Route::get('/', [MailSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [MailSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/test', [MailSettingController::class, 'sendTestEmail'])->middleware('can:settings.edit');
        Route::delete('/keys/{key}', [MailSettingController::class, 'reset'])->middleware('can:settings.edit');
    });

    // Notification Channel Settings (permission: settings.view / settings.edit)
    Route::prefix('notification-settings')->group(function () {
        Route::get('/', [NotificationSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [NotificationSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/generate-vapid', [NotificationSettingController::class, 'generateVapid'])->middleware('can:settings.edit');
        Route::post('/test/{channel}', [NotificationSettingController::class, 'testChannel'])->middleware('can:settings.edit');
        Route::delete('/keys/{key}', [NotificationSettingController::class, 'reset'])->middleware('can:settings.edit');
    });

    // Notification Delivery Log (permission: notification_deliveries.view)
    Route::prefix('notification-deliveries')->group(function () {
        Route::get('/', [NotificationDeliveryController::class, 'index'])->middleware('can:notification_deliveries.view');
        Route::get('/stats', [NotificationDeliveryController::class, 'stats'])->middleware('can:notification_deliveries.view');
    });

    // Novu Settings (permission: settings.view / settings.edit)
    Route::prefix('novu-settings')->group(function () {
        Route::get('/', [NovuSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [NovuSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/test', [NovuSettingController::class, 'test'])->middleware('can:settings.edit');
        Route::get('/workflow-map', [NovuSettingController::class, 'workflowMap'])->middleware('can:settings.view');
        Route::put('/workflow-map', [NovuSettingController::class, 'updateWorkflowMap'])->middleware('can:settings.edit');
        Route::delete('/keys/{key}', [NovuSettingController::class, 'resetKey'])->middleware('can:settings.edit');
    });

    // LLM System-Wide Settings (permission: settings.view / settings.edit)
    Route::prefix('llm-settings')->group(function () {
        Route::get('/', [LLMSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [LLMSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::delete('/keys/{key}', [LLMSettingController::class, 'reset'])->middleware('can:settings.edit');
        Route::post('/test-key', [LLMModelController::class, 'testKey'])->middleware('can:settings.edit');
        Route::post('/discover-models', [LLMModelController::class, 'discover'])->middleware('can:settings.edit');
    });

    // Auth Settings (permission: settings.view / settings.edit)
    Route::prefix('auth-settings')->group(function () {
        Route::get('/', [AuthSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [AuthSettingController::class, 'update'])->middleware('can:settings.edit');
    });

    // SSO Settings (permission: settings.view / settings.edit)
    Route::prefix('sso-settings')->group(function () {
        Route::get('/', [SSOSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [SSOSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/test/{provider}', [SSOSettingController::class, 'test'])->middleware('can:settings.edit');
        Route::delete('/keys/{key}', [SSOSettingController::class, 'reset'])->middleware('can:settings.edit');
    });

    // Stripe Connect (permission: settings.view / settings.edit)
    Route::prefix('stripe/connect')->group(function () {
        Route::get('/status', [StripeConnectController::class, 'status'])->middleware('can:settings.view');
        Route::post('/oauth-link', [StripeConnectController::class, 'createOAuthLink'])->middleware('can:settings.edit');
        Route::post('/account-link', [StripeConnectController::class, 'createAccountLink'])->middleware('can:settings.edit');
        Route::post('/login-link', [StripeConnectController::class, 'createLoginLink'])->middleware('can:settings.edit');
        Route::delete('/disconnect', [StripeConnectController::class, 'disconnect'])->middleware('can:settings.edit');
    });

    // Stripe Settings (permission: settings.view / settings.edit)
    Route::prefix('stripe/settings')->group(function () {
        Route::get('/', [StripeSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [StripeSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/test', [StripeSettingController::class, 'testConnection'])->middleware('can:settings.edit');
        Route::delete('/keys/{key}', [StripeSettingController::class, 'reset'])->middleware('can:settings.edit');
    });

    // Payments (permission: payments.view / payments.manage)
    Route::prefix('payments')->group(function () {
        Route::get('/', [StripePaymentController::class, 'index'])->middleware('can:payments.view');
        Route::post('/intent', [StripePaymentController::class, 'createIntent'])->middleware('can:payments.manage');
        Route::get('/admin', [StripePaymentController::class, 'adminIndex'])->middleware('can:payments.manage');
        Route::get('/{payment}', [StripePaymentController::class, 'show'])->middleware('can:payments.view');
    });

    // Backup Settings (permission: settings.view / settings.edit)
    Route::prefix('backup-settings')->group(function () {
        Route::get('/', [BackupSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [BackupSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/reset/{key}', [BackupSettingController::class, 'reset'])->middleware('can:settings.edit');
        Route::post('/test/{destination}', [BackupSettingController::class, 'testDestination'])->middleware('can:settings.edit');
    });

    // Search Admin (permission: settings.view / settings.edit for reindex)
    Route::prefix('admin')->group(function () {
        Route::get('/search/stats', [SearchAdminController::class, 'stats'])->middleware('can:settings.view');
        Route::get('/search/health', [SearchAdminController::class, 'health'])->middleware('can:settings.view');
        Route::post('/search/test-connection', [SearchAdminController::class, 'testConnection'])->middleware('can:settings.edit');
        Route::post('/search/reindex', [SearchAdminController::class, 'reindex'])->middleware('can:settings.edit');
    });

    // Jobs (permission: settings.view / settings.edit for run)
    Route::prefix('jobs')->group(function () {
        Route::get('/scheduled', [JobController::class, 'scheduled'])->middleware('can:settings.view');
        Route::post('/run/{command}', [JobController::class, 'run'])->middleware('can:settings.edit');
        Route::get('/queue', [JobController::class, 'queueStatus'])->middleware('can:settings.view');
        Route::get('/failed', [JobController::class, 'failedJobs'])->middleware('can:settings.view');
        Route::post('/failed/{id}/retry', [JobController::class, 'retryJob'])->middleware('can:settings.edit');
        Route::delete('/failed/{id}', [JobController::class, 'deleteJob'])->middleware('can:settings.edit');
        Route::post('/failed/retry-all', [JobController::class, 'retryAllFailed'])->middleware('can:settings.edit');
        Route::delete('/failed/clear', [JobController::class, 'clearFailed'])->middleware('can:settings.edit');
    });

    // Storage Settings (permission: settings.view / settings.edit)
    Route::prefix('storage-settings')->group(function () {
        Route::get('/', [StorageSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [StorageSettingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/test', [StorageSettingController::class, 'test'])->middleware('can:settings.edit');
        Route::get('/stats', [StorageSettingController::class, 'stats'])->middleware('can:settings.view');
        Route::get('/analytics', [StorageSettingController::class, 'analytics'])->middleware('can:settings.view');
        Route::get('/cleanup-suggestions', [StorageSettingController::class, 'cleanupSuggestions'])->middleware('can:settings.view');
        Route::post('/cleanup', [StorageSettingController::class, 'cleanup'])->middleware('can:settings.edit');
        Route::get('/paths', [StorageSettingController::class, 'paths'])->middleware('can:settings.view');
        Route::get('/health', [StorageSettingController::class, 'health'])->middleware('can:settings.view');
    });

    // File Manager (admin only)
    Route::prefix('storage/files')->middleware('can:admin')->group(function () {
        Route::get('/', [FileManagerController::class, 'index']);
        Route::post('/', [FileManagerController::class, 'upload']);
        Route::get('/{path}/download', [FileManagerController::class, 'download'])->where('path', '.*');
        Route::put('/{path}/rename', [FileManagerController::class, 'rename'])->where('path', '.*');
        Route::put('/{path}/move', [FileManagerController::class, 'move'])->where('path', '.*');
        Route::get('/{path}', [FileManagerController::class, 'show'])->where('path', '.*');
        Route::delete('/{path}', [FileManagerController::class, 'destroy'])->where('path', '.*');
    });
    
    // API Tokens (Authenticated users)
    Route::prefix('api-tokens')->group(function () {
        Route::get('/', [ApiTokenController::class, 'index']);
        Route::post('/', [ApiTokenController::class, 'store']);
        Route::delete('/{token}', [ApiTokenController::class, 'destroy']);
    });

    // GraphQL API Keys (Authenticated users — sk_ prefixed keys)
    Route::prefix('user/api-keys')->group(function () {
        Route::get('/', [ApiKeyController::class, 'index']);
        Route::post('/', [ApiKeyController::class, 'store']);
        Route::put('/{id}', [ApiKeyController::class, 'update']);
        Route::delete('/{id}', [ApiKeyController::class, 'destroy']);
        Route::post('/{id}/rotate', [ApiKeyController::class, 'rotate']);
    });

    // GraphQL Settings (admin)
    Route::prefix('graphql/settings')->group(function () {
        Route::get('/', [GraphQLSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [GraphQLSettingController::class, 'update'])->middleware('can:settings.edit');
    });

    Route::prefix('graphql/admin')->group(function () {
        Route::get('/api-keys', [GraphQLSettingController::class, 'adminApiKeys'])->middleware('can:api_keys.manage');
        Route::get('/api-keys/stats', [GraphQLSettingController::class, 'adminApiKeyStats'])->middleware('can:api_keys.manage');
        Route::delete('/api-keys/{id}', [GraphQLSettingController::class, 'adminRevokeKey'])->middleware('can:api_keys.manage');
        Route::get('/usage-stats', [GraphQLSettingController::class, 'usageStats'])->middleware('can:settings.view');
    });

    // Webhooks (permission: settings.edit)
    Route::prefix('webhooks')->group(function () {
        Route::get('/', [WebhookController::class, 'index'])->middleware('can:settings.view');
        Route::post('/', [WebhookController::class, 'store'])->middleware('can:settings.edit');
        // More specific routes must come before parameterized routes
        Route::get('/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->middleware('can:settings.view');
        Route::post('/{webhook}/test', [WebhookController::class, 'test'])->middleware('can:settings.edit');
        Route::put('/{webhook}', [WebhookController::class, 'update'])->middleware('can:settings.edit');
        Route::delete('/{webhook}', [WebhookController::class, 'destroy'])->middleware('can:settings.edit');
    });

    // Branding (permission: settings.edit - public read is defined above)
    Route::prefix('branding')->group(function () {
        Route::put('/', [BrandingController::class, 'update'])->middleware('can:settings.edit');
        Route::post('/logo', [BrandingController::class, 'uploadLogo'])->middleware('can:settings.edit');
        Route::post('/logo-dark', [BrandingController::class, 'uploadLogoDark'])->middleware('can:settings.edit');
        Route::post('/favicon', [BrandingController::class, 'uploadFavicon'])->middleware('can:settings.edit');
        Route::delete('/logo', [BrandingController::class, 'deleteLogo'])->middleware('can:settings.edit');
        Route::delete('/logo-dark', [BrandingController::class, 'deleteLogoDark'])->middleware('can:settings.edit');
        Route::delete('/favicon', [BrandingController::class, 'deleteFavicon'])->middleware('can:settings.edit');
    });

    // Email Hosting Settings (spam threshold, attachment size)
    Route::prefix('email-hosting-settings')->group(function () {
        Route::get('/', [EmailHostingSettingController::class, 'show'])->middleware('can:settings.view');
        Route::put('/', [EmailHostingSettingController::class, 'update'])->middleware('can:settings.edit');
    });

    // Email Provider Accounts (admin: settings.view / settings.edit)
    Route::prefix('email/provider-accounts')->group(function () {
        Route::get('/', [EmailProviderAccountController::class, 'index'])->middleware('can:settings.view');
        Route::post('/', [EmailProviderAccountController::class, 'store'])->middleware('can:settings.edit');
        Route::get('/{providerAccount}', [EmailProviderAccountController::class, 'show'])->middleware('can:settings.view');
        Route::put('/{providerAccount}', [EmailProviderAccountController::class, 'update'])->middleware('can:settings.edit');
        Route::delete('/{providerAccount}', [EmailProviderAccountController::class, 'destroy'])->middleware('can:settings.edit');
        Route::post('/{providerAccount}/test', [EmailProviderAccountController::class, 'test'])->middleware('can:settings.edit');
        Route::post('/{providerAccount}/default', [EmailProviderAccountController::class, 'setDefault'])->middleware('can:settings.edit');
        Route::get('/{providerAccount}/domains', [EmailProviderAccountController::class, 'listProviderDomains'])->middleware('can:settings.view');
        Route::post('/{providerAccount}/import-domains', [EmailProviderAccountController::class, 'importDomains'])->middleware('can:settings.edit');
    });

    // Email Domains (authenticated users manage their own domains)
    Route::prefix('email/domains')->group(function () {
        Route::get('/', [EmailDomainController::class, 'index']);
        Route::post('/', [EmailDomainController::class, 'store']);
        Route::get('/{emailDomain}', [EmailDomainController::class, 'show']);
        Route::put('/{emailDomain}', [EmailDomainController::class, 'update']);
        Route::delete('/{emailDomain}', [EmailDomainController::class, 'destroy']);
        Route::post('/{emailDomain}/verify', [EmailDomainController::class, 'verify']);
    });

    // Provider Management (Phase B) — provider-agnostic domain management
    Route::get('/email/provider/health', [ProviderManagementController::class, 'checkHealth']);
    Route::get('/email/dkim-rotation-settings', [ProviderManagementController::class, 'getDkimRotationSettings']);
    Route::put('/email/dkim-rotation-settings', [ProviderManagementController::class, 'updateDkimRotationSettings']);
    Route::prefix('email/domains/{domainId}/management')->whereNumber('domainId')->group(function () {
        // Capabilities
        Route::get('/capabilities', [ProviderManagementController::class, 'getCapabilities']);

        // DKIM
        Route::get('/dkim', [ProviderManagementController::class, 'getDkim']);
        Route::post('/dkim/rotate', [ProviderManagementController::class, 'rotateDkim']);
        Route::get('/dkim/rotation-history', [ProviderManagementController::class, 'getDkimRotationHistory']);

        // Webhooks
        Route::get('/webhooks', [ProviderManagementController::class, 'listWebhooks']);
        Route::post('/webhooks', [ProviderManagementController::class, 'createWebhook']);
        Route::post('/webhooks/auto-configure', [ProviderManagementController::class, 'autoConfigureWebhooks']);
        Route::post('/webhooks/{webhookId}/test', [ProviderManagementController::class, 'testWebhook']);
        Route::put('/webhooks/{webhookId}', [ProviderManagementController::class, 'updateWebhook']);
        Route::delete('/webhooks/{webhookId}', [ProviderManagementController::class, 'deleteWebhook']);

        // Inbound Routes
        Route::get('/routes', [ProviderManagementController::class, 'listRoutes']);
        Route::post('/routes', [ProviderManagementController::class, 'createRoute']);
        Route::put('/routes/{routeId}', [ProviderManagementController::class, 'updateRoute']);
        Route::delete('/routes/{routeId}', [ProviderManagementController::class, 'deleteRoute']);

        // Event Log
        Route::get('/events', [ProviderManagementController::class, 'getEvents']);

        // Suppressions
        Route::get('/suppressions/check', [ProviderManagementController::class, 'checkSuppression']);
        Route::post('/suppressions/check-batch', [ProviderManagementController::class, 'checkSuppressionBatch']);
        Route::get('/suppressions/{type}', [ProviderManagementController::class, 'listSuppressions']);
        Route::get('/suppressions/{type}/export', [ProviderManagementController::class, 'exportSuppressions']);
        Route::post('/suppressions/{type}/import', [ProviderManagementController::class, 'importSuppressions']);
        Route::delete('/suppressions/{type}/{address}', [ProviderManagementController::class, 'deleteSuppression'])
            ->where('address', '.*');

        // Tracking
        Route::get('/tracking', [ProviderManagementController::class, 'getTracking']);
        Route::put('/tracking/{type}', [ProviderManagementController::class, 'updateTracking']);

        // Stats
        Route::get('/stats', [ProviderManagementController::class, 'getStats']);
    });

    // Deprecated /mailgun/ aliases — kept for backward compatibility
    Route::prefix('email/domains/{domainId}/mailgun')->whereNumber('domainId')->group(function () {
        Route::get('/capabilities', [ProviderManagementController::class, 'getCapabilities']);
        Route::get('/dkim', [ProviderManagementController::class, 'getDkim']);
        Route::post('/dkim/rotate', [ProviderManagementController::class, 'rotateDkim']);
        Route::get('/dkim/rotation-history', [ProviderManagementController::class, 'getDkimRotationHistory']);
        Route::get('/webhooks', [ProviderManagementController::class, 'listWebhooks']);
        Route::post('/webhooks', [ProviderManagementController::class, 'createWebhook']);
        Route::post('/webhooks/auto-configure', [ProviderManagementController::class, 'autoConfigureWebhooks']);
        Route::post('/webhooks/{webhookId}/test', [ProviderManagementController::class, 'testWebhook']);
        Route::put('/webhooks/{webhookId}', [ProviderManagementController::class, 'updateWebhook']);
        Route::delete('/webhooks/{webhookId}', [ProviderManagementController::class, 'deleteWebhook']);
        Route::get('/routes', [ProviderManagementController::class, 'listRoutes']);
        Route::post('/routes', [ProviderManagementController::class, 'createRoute']);
        Route::put('/routes/{routeId}', [ProviderManagementController::class, 'updateRoute']);
        Route::delete('/routes/{routeId}', [ProviderManagementController::class, 'deleteRoute']);
        Route::get('/events', [ProviderManagementController::class, 'getEvents']);
        Route::get('/suppressions/check', [ProviderManagementController::class, 'checkSuppression']);
        Route::post('/suppressions/check-batch', [ProviderManagementController::class, 'checkSuppressionBatch']);
        Route::get('/suppressions/{type}', [ProviderManagementController::class, 'listSuppressions']);
        Route::get('/suppressions/{type}/export', [ProviderManagementController::class, 'exportSuppressions']);
        Route::post('/suppressions/{type}/import', [ProviderManagementController::class, 'importSuppressions']);
        Route::delete('/suppressions/{type}/{address}', [ProviderManagementController::class, 'deleteSuppression'])
            ->where('address', '.*');
        Route::get('/tracking', [ProviderManagementController::class, 'getTracking']);
        Route::put('/tracking/{type}', [ProviderManagementController::class, 'updateTracking']);
        Route::get('/stats', [ProviderManagementController::class, 'getStats']);
    });

    // Mailboxes (access-based: users see mailboxes they have access to)
    Route::prefix('email/mailboxes')->group(function () {
        Route::get('/', [MailboxController::class, 'index']);
        Route::post('/', [MailboxController::class, 'store']);
        Route::get('/{mailbox}', [MailboxController::class, 'show']);
        Route::put('/{mailbox}', [MailboxController::class, 'update']);
        Route::delete('/{mailbox}', [MailboxController::class, 'destroy']);

        // Member management (requires owner role on the mailbox)
        Route::get('/{mailbox}/members', [MailboxController::class, 'members']);
        Route::post('/{mailbox}/members', [MailboxController::class, 'addMember']);
        Route::put('/{mailbox}/members/{memberId}', [MailboxController::class, 'updateMember']);
        Route::delete('/{mailbox}/members/{memberId}', [MailboxController::class, 'removeMember']);

        // Forwarding
        Route::get('/{mailbox}/forward', [\App\Http\Controllers\Api\MailboxForwardController::class, 'show']);
        Route::put('/{mailbox}/forward', [\App\Http\Controllers\Api\MailboxForwardController::class, 'upsert']);
        Route::delete('/{mailbox}/forward', [\App\Http\Controllers\Api\MailboxForwardController::class, 'destroy']);
    });

    // Email unread counts (per-mailbox)
    Route::get('/email/unread-counts', [EmailController::class, 'unreadCounts']);

    // Email Messages (authenticated users manage their own emails)
    Route::prefix('email/messages')->group(function () {
        Route::get('/', [EmailController::class, 'index']);
        Route::get('/search', [EmailController::class, 'search']);
        Route::post('/send', [EmailController::class, 'send']);
        Route::post('/draft', [EmailController::class, 'saveDraft']);
        Route::post('/bulk', [EmailController::class, 'bulk']);
        Route::get('/{email}', [EmailController::class, 'show']);
        Route::delete('/{email}', [EmailController::class, 'destroy']);
        Route::patch('/{email}/read', [EmailController::class, 'markRead']);
        Route::patch('/{email}/star', [EmailController::class, 'toggleStar']);
        Route::patch('/{email}/spam', [EmailController::class, 'toggleSpam']);
        Route::put('/{email}/draft', [EmailController::class, 'updateDraft']);
        Route::post('/{email}/send', [EmailController::class, 'sendDraft']);
        Route::get('/{email}/reply-data', [EmailController::class, 'replyData']);
        Route::get('/{email}/provider-events', [EmailController::class, 'providerEvents']);
        Route::post('/{email}/snooze', [EmailController::class, 'snooze']);
        Route::delete('/{email}/snooze', [EmailController::class, 'unsnooze']);
    });

    // Email Threads (authenticated users)
    Route::prefix('email/threads')->group(function () {
        Route::get('/', [EmailThreadController::class, 'index']);
        Route::get('/{emailThread}', [EmailThreadController::class, 'show']);
    });

    // Email Labels (authenticated users manage their own labels)
    Route::prefix('email/labels')->group(function () {
        Route::get('/', [EmailLabelController::class, 'index']);
        Route::post('/', [EmailLabelController::class, 'store']);
        Route::put('/{emailLabel}', [EmailLabelController::class, 'update']);
        Route::delete('/{emailLabel}', [EmailLabelController::class, 'destroy']);
        Route::post('/{emailLabel}/assign', [EmailLabelController::class, 'assign']);
        Route::delete('/{emailLabel}/assign', [EmailLabelController::class, 'unassign']);
    });

    // Email AI Features (authenticated users)
    Route::prefix('email/ai')->group(function () {
        Route::get('/status', [EmailAIController::class, 'status']);
        Route::get('/settings', [EmailAIController::class, 'settings']);
        Route::put('/settings', [EmailAIController::class, 'updateSettings']);
        Route::get('/thread/{emailThread}/summary', [EmailAIController::class, 'threadSummary']);
        Route::post('/thread/{emailThread}/summarize', [EmailAIController::class, 'requestSummary']);
        Route::get('/email/{email}/labels', [EmailAIController::class, 'suggestedLabels']);
        Route::post('/email/{email}/labels/apply', [EmailAIController::class, 'applyLabels']);
        Route::get('/email/{email}/priority', [EmailAIController::class, 'emailPriority']);
        Route::get('/email/{email}/replies', [EmailAIController::class, 'smartReplies']);
        Route::post('/email/{email}/replies/generate', [EmailAIController::class, 'generateReplies']);
    });

    // Email Attachments (authenticated users)
    Route::get('/email/attachments/{emailAttachment}/download', [EmailAttachmentController::class, 'download']);

    // Spam Filter Lists (authenticated users manage their own allow/block lists)
    Route::prefix('email/spam-filter')->group(function () {
        Route::get('/', [SpamFilterController::class, 'index']);
        Route::post('/', [SpamFilterController::class, 'store']);
        Route::post('/bulk', [SpamFilterController::class, 'bulkStore']);
        Route::delete('/{entry}', [SpamFilterController::class, 'destroy']);
    });

    // Email Rules/Filters (authenticated users manage their own rules)
    Route::prefix('email/rules')->group(function () {
        Route::get('/', [EmailRuleController::class, 'index']);
        Route::post('/', [EmailRuleController::class, 'store']);
        Route::put('/reorder', [EmailRuleController::class, 'reorder']);
        Route::get('/{rule}', [EmailRuleController::class, 'show']);
        Route::put('/{rule}', [EmailRuleController::class, 'update']);
        Route::delete('/{rule}', [EmailRuleController::class, 'destroy']);
        Route::post('/{rule}/test', [EmailRuleController::class, 'test']);
    });

    // Email Import (authenticated users import into their mailboxes)
    Route::post('/email/import', [EmailImportController::class, 'import']);
    Route::get('/email/import/{jobId}/status', [EmailImportController::class, 'status']);

    // Contacts (authenticated users manage their own contacts)
    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/autocomplete', [ContactController::class, 'autocomplete']);
        Route::post('/backfill', [ContactController::class, 'backfill']);
        Route::post('/merge', [ContactController::class, 'merge']);
        Route::get('/{contact}', [ContactController::class, 'show']);
        Route::put('/{contact}', [ContactController::class, 'update']);
        Route::delete('/{contact}', [ContactController::class, 'destroy']);
    });

});
