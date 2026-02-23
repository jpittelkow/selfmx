<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use App\Auth\ApiKeyGuard;
use App\Enums\Permission;
use App\Models\User;
use App\Services\ApiKeyService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register LLM Orchestrator
        $this->app->singleton(\App\Services\LLM\LLMOrchestrator::class, function ($app) {
            return new \App\Services\LLM\LLMOrchestrator();
        });

        // Register Notification Template Service
        $this->app->singleton(\App\Services\NotificationTemplateService::class, function ($app) {
            return new \App\Services\NotificationTemplateService();
        });

        // Register Notification Orchestrator
        $this->app->singleton(\App\Services\Notifications\NotificationOrchestrator::class, function ($app) {
            return new \App\Services\Notifications\NotificationOrchestrator(
                $app->make(\App\Services\NotificationTemplateService::class),
                $app->make(\App\Services\NovuService::class),
                $app->make(\App\Services\Notifications\NotificationRateLimiter::class)
            );
        });

        // Register Backup Service
        $this->app->singleton(\App\Services\Backup\BackupService::class, function ($app) {
            return new \App\Services\Backup\BackupService();
        });

        // Register Version Service
        $this->app->singleton(\App\Services\VersionService::class, function ($app) {
            return new \App\Services\VersionService();
        });

        // Register Email Config Service
        $this->app->singleton(\App\Services\EmailConfigService::class, function ($app) {
            return new \App\Services\EmailConfigService($app->make(\App\Services\SettingService::class));
        });

        // Register Email Template Service
        $this->app->singleton(\App\Services\EmailTemplateService::class, function ($app) {
            return new \App\Services\EmailTemplateService();
        });

        // Register Audit Service
        $this->app->singleton(\App\Services\AuditService::class, function ($app) {
            return new \App\Services\AuditService();
        });

        // Register Storage Service
        $this->app->singleton(\App\Services\StorageService::class, function ($app) {
            return new \App\Services\StorageService();
        });

        // Register URL Validation Service (SSRF Protection)
        $this->app->singleton(\App\Services\UrlValidationService::class, function ($app) {
            return new \App\Services\UrlValidationService();
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register all permissions as Gates (for can:permission.name)
        foreach (Permission::cases() as $perm) {
            Gate::define($perm->value, fn (User $user) => $user->hasPermission($perm));
        }

        // Admin gate for backwards compatibility (super-permission)
        Gate::define('admin', fn (User $user) => $user->isAdmin());

        // Configure password validation defaults
        Password::defaults(function () {
            $rule = Password::min(8)
                ->mixedCase()
                ->numbers()
                ->symbols();

            // In production, also check against compromised password databases
            if (app()->isProduction()) {
                $rule->uncompromised();
            }

            return $rule;
        });

        $this->registerCustomFilesystemDrivers();
        $this->registerApiKeyGuard();
    }

    /**
     * Register the api-key authentication guard driver.
     */
    private function registerApiKeyGuard(): void
    {
        Auth::extend('api-key', function ($app, $name, array $config) {
            return new ApiKeyGuard(
                $app->make(ApiKeyService::class),
                $app->make('request')
            );
        });
    }

    /**
     * Register custom filesystem drivers (GCS, Azure) when packages are installed.
     */
    private function registerCustomFilesystemDrivers(): void
    {
        if (class_exists(\League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter::class)
            && class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            Storage::extend('gcs', function ($app, $config) {
                $clientOptions = array_filter([
                    'projectId' => $config['project_id'] ?? null,
                    'keyFilePath' => $config['key_file_path'] ?? null,
                    'keyFile' => $config['credentials'] ?? null,
                ]);
                $client = new \Google\Cloud\Storage\StorageClient($clientOptions);
                $bucket = $client->bucket($config['bucket'] ?? '');
                $adapter = new \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter($bucket);
                return new \Illuminate\Filesystem\FilesystemAdapter(
                    new \League\Flysystem\Filesystem($adapter, ['visibility' => $config['visibility'] ?? \League\Flysystem\Visibility::PRIVATE]),
                    $adapter,
                    $config
                );
            });
        }

        if (class_exists(\League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter::class)
            && class_exists(\MicrosoftAzure\Storage\Blob\BlobRestProxy::class)) {
            Storage::extend('azure', function ($app, $config) {
                $connectionString = $config['connection_string'] ?? '';
                $client = \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService($connectionString);
                $adapter = new \League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter($client, $config['container'] ?? '');
                return new \Illuminate\Filesystem\FilesystemAdapter(
                    new \League\Flysystem\Filesystem($adapter, ['visibility' => $config['visibility'] ?? \League\Flysystem\Visibility::PRIVATE]),
                    $adapter,
                    $config
                );
            });
        }
    }
}
