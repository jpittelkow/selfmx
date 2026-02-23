<?php

use App\Http\Controllers\Api\GraphQLSettingController;
use App\Services\ApiKeyService;
use App\Services\AuditService;
use App\Services\SettingService;

describe('GraphQLSettingController', function () {

    beforeEach(function () {
        $this->settingService = $this->createMock(SettingService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->apiKeyService = $this->createMock(ApiKeyService::class);
        $this->controller = new GraphQLSettingController(
            $this->settingService,
            $this->auditService,
            $this->apiKeyService,
        );
    });

    describe('show', function () {
        it('returns all graphql settings', function () {
            $settings = [
                'enabled' => true,
                'max_keys_per_user' => 5,
                'default_rate_limit' => 60,
                'introspection_enabled' => false,
                'max_query_depth' => 12,
                'max_query_complexity' => 200,
                'max_result_size' => 100,
                'key_rotation_grace_days' => 7,
                'cors_allowed_origins' => '*',
            ];

            $this->settingService
                ->method('getGroup')
                ->with('graphql')
                ->willReturn($settings);

            $response = $this->controller->show();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['data']['settings']['enabled'])->toBeTrue();
            expect($data['data']['settings']['max_keys_per_user'])->toBe(5);
            expect($data['data']['settings']['default_rate_limit'])->toBe(60);
        });
    });

    describe('update', function () {
        it('updates settings and logs audit', function () {
            $oldSettings = ['enabled' => false, 'max_keys_per_user' => 5];
            $this->settingService
                ->method('getGroup')
                ->with('graphql')
                ->willReturn($oldSettings);

            $this->settingService
                ->expects($this->exactly(2))
                ->method('set');

            $this->auditService
                ->expects($this->once())
                ->method('logSettings');

            $request = Illuminate\Http\Request::create('/api/graphql/settings', 'PUT', [
                'enabled' => true,
                'max_keys_per_user' => 10,
            ]);
            $request->setUserResolver(fn () => createAdminUser());

            $response = $this->controller->update($request);

            expect($response->getStatusCode())->toBe(200);
        });

        it('validates max_keys_per_user range', function () {
            $request = Illuminate\Http\Request::create('/api/graphql/settings', 'PUT', [
                'max_keys_per_user' => 0,
            ]);
            $request->setUserResolver(fn () => createAdminUser());

            expect(fn () => $this->controller->update($request))
                ->toThrow(Illuminate\Validation\ValidationException::class);
        });

        it('validates default_rate_limit range', function () {
            $request = Illuminate\Http\Request::create('/api/graphql/settings', 'PUT', [
                'default_rate_limit' => 99999,
            ]);
            $request->setUserResolver(fn () => createAdminUser());

            expect(fn () => $this->controller->update($request))
                ->toThrow(Illuminate\Validation\ValidationException::class);
        });
    });

    describe('adminApiKeyStats', function () {
        it('returns correct stat structure', function () {
            // Create some API keys for testing
            $user = createAdminUser();
            $apiKeyService = app(ApiKeyService::class);
            $apiKeyService->create($user, 'Key 1');
            $apiKeyService->create($user, 'Key 2');

            // Use the real controller with real dependencies
            $controller = app(GraphQLSettingController::class);
            $response = $controller->adminApiKeyStats();
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['data'])->toHaveKeys(['total', 'active', 'expiring_soon', 'never_used']);
            expect($data['data']['total'])->toBeGreaterThanOrEqual(2);
            expect($data['data']['active'])->toBeGreaterThanOrEqual(2);
            expect($data['data']['never_used'])->toBeGreaterThanOrEqual(2);
        });
    });

    describe('adminRevokeKey', function () {
        it('revokes an existing active key', function () {
            $user = createAdminUser();
            $apiKeyService = app(ApiKeyService::class);
            $result = $apiKeyService->create($user, 'Key to Revoke');
            $token = $result['token'];

            $controller = app(GraphQLSettingController::class);
            $request = Illuminate\Http\Request::create("/api/graphql/admin/api-keys/{$token->id}", 'DELETE');
            $request->setUserResolver(fn () => $user);

            $response = $controller->adminRevokeKey($request, $token->id);

            expect($response->getStatusCode())->toBe(200);
            $token->refresh();
            expect($token->isRevoked())->toBeTrue();
        });

        it('returns 404 for non-existent key', function () {
            $request = Illuminate\Http\Request::create('/api/graphql/admin/api-keys/99999', 'DELETE');
            $request->setUserResolver(fn () => createAdminUser());

            $controller = app(GraphQLSettingController::class);
            $response = $controller->adminRevokeKey($request, 99999);

            expect($response->getStatusCode())->toBe(404);
        });

        it('returns 422 for already revoked key', function () {
            $user = createAdminUser();
            $apiKeyService = app(ApiKeyService::class);
            $result = $apiKeyService->create($user, 'Already Revoked');
            $token = $result['token'];
            $apiKeyService->revoke($token);

            $controller = app(GraphQLSettingController::class);
            $request = Illuminate\Http\Request::create("/api/graphql/admin/api-keys/{$token->id}", 'DELETE');
            $request->setUserResolver(fn () => $user);

            $response = $controller->adminRevokeKey($request, $token->id);

            expect($response->getStatusCode())->toBe(422);
        });
    });

    describe('usageStats', function () {
        it('returns correct structure with empty data', function () {
            $controller = app(GraphQLSettingController::class);
            $request = Illuminate\Http\Request::create('/api/graphql/admin/usage-stats', 'GET');

            $response = $controller->usageStats($request);
            $data = $response->getData(true);

            expect($response->getStatusCode())->toBe(200);
            expect($data['data'])->toHaveKeys(['total_7d', 'total_30d', 'daily', 'top_users', 'top_queries']);
            expect($data['data']['total_7d'])->toBe(0);
            expect($data['data']['total_30d'])->toBe(0);
            expect($data['data']['daily'])->toBeArray();
            expect($data['data']['top_users'])->toBeArray();
            expect($data['data']['top_queries'])->toBeArray();
        });
    });
});
