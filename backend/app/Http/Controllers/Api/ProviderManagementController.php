<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ProviderApiException;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\AuditLog;
use App\Models\EmailDomain;
use App\Services\AuditService;
use App\Services\Email\Concerns\HasDeliveryStats;
use App\Services\Email\Concerns\HasDkimManagement;
use App\Services\Email\Concerns\HasEventLog;
use App\Services\Email\Concerns\HasInboundRoutes;
use App\Services\Email\Concerns\HasSuppressionManagement;
use App\Services\Email\Concerns\HasWebhookManagement;
use App\Services\Email\DomainService;
use App\Services\Email\ProviderManagementInterface;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderManagementController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
        private SettingService $settingService,
        private DomainService $domainService,
    ) {}

    // -------------------------------------------------------------------------
    // Provider resolution helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the domain (user-scoped) and return a management-capable provider
     * instance. Aborts with 422 if the domain's provider doesn't implement
     * ProviderManagementInterface.
     */
    private function resolveManagementProvider(Request $request, int $domainId): array
    {
        $domain = EmailDomain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $provider = $this->domainService->resolveProvider($domain->provider);
        } catch (\InvalidArgumentException) {
            abort(422, "Provider '{$domain->provider}' is not supported");
        }

        if (! $provider instanceof ProviderManagementInterface) {
            abort(422, "Provider '{$domain->provider}' does not support management operations");
        }

        return [$domain, $provider];
    }

    private function requireCapability(ProviderManagementInterface $provider, string $capability): void
    {
        $caps = $provider->getCapabilities();
        if (empty($caps[$capability])) {
            abort(422, "Provider '{$provider->getName()}' does not support {$capability}");
        }
    }

    private function wrapProviderCall(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (ProviderApiException $e) {
            $status = ($e->httpStatus >= 400 && $e->httpStatus < 600) ? $e->httpStatus : 502;

            // Never pass upstream 401/403 — frontend intercepts them as session expired.
            if (in_array($status, [401, 403])) {
                return $this->errorResponse(
                    'Provider API authentication failed — check your API key configuration. (' . $e->getMessage() . ')',
                    502,
                );
            }

            return $this->errorResponse($e->getMessage(), $status);
        }
    }

    // -------------------------------------------------------------------------
    // Provider Health
    // -------------------------------------------------------------------------

    public function checkHealth(Request $request): JsonResponse
    {
        $providerName = $request->query('provider', 'mailgun');

        try {
            $provider = $this->domainService->resolveProvider($providerName);
        } catch (\InvalidArgumentException) {
            return $this->errorResponse("Unknown provider '{$providerName}'", 422);
        }

        if (! $provider instanceof ProviderManagementInterface) {
            return $this->errorResponse("Provider '{$providerName}' does not support health checks", 422);
        }

        // Use first domain for this provider to get config, fall back to empty
        $domain = EmailDomain::where('user_id', $request->user()->id)
            ->where('provider', $providerName)
            ->first();

        $config = $domain?->getEffectiveConfig() ?? [];

        $start = microtime(true);
        $ok = $provider->checkApiHealth($config);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return response()->json([
            'healthy'    => $ok,
            'latency_ms' => $latencyMs,
            'provider'   => $providerName,
        ]);
    }

    // -------------------------------------------------------------------------
    // Capabilities
    // -------------------------------------------------------------------------

    public function getCapabilities(Request $request, int $domainId): JsonResponse
    {
        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);

        return response()->json([
            'provider'     => $domain->provider,
            'capabilities' => $provider->getCapabilities(),
        ]);
    }

    // -------------------------------------------------------------------------
    // DKIM
    // -------------------------------------------------------------------------

    public function getDkim(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'dkim_rotation');

            /** @var HasDkimManagement $provider */
            $data = $provider->getDkimKey($domain->name, $domain->getEffectiveConfig());

            return response()->json($data);
        });
    }

    public function rotateDkim(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'dkim_rotation');

            /** @var HasDkimManagement $provider */
            $data = $provider->rotateDkimKey($domain->name, $domain->getEffectiveConfig());

            $domain->update(['dkim_rotated_at' => now()]);

            $this->auditService->log(
                'email_domain.dkim_rotated',
                $domain,
                [],
                ['rotated_at' => now()->toIso8601String()],
                $request->user()->id,
            );

            return response()->json($data);
        });
    }

    public function getDkimRotationSettings(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return $this->errorResponse('Forbidden', 403);
        }

        $intervalDays = (int) $this->settingService->get('mailgun', 'dkim_rotation_interval_days', 0);

        return response()->json([
            'interval_days' => $intervalDays,
            'enabled'       => $intervalDays > 0,
        ]);
    }

    public function updateDkimRotationSettings(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return $this->errorResponse('Forbidden', 403);
        }

        $validated = $request->validate([
            'interval_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $this->settingService->set('mailgun', 'dkim_rotation_interval_days', $validated['interval_days']);

        $this->auditService->log(
            'email_domain.dkim_rotation_settings_updated',
            null,
            [],
            ['interval_days' => $validated['interval_days']],
            $request->user()->id,
        );

        return response()->json([
            'interval_days' => $validated['interval_days'],
            'enabled'       => $validated['interval_days'] > 0,
        ]);
    }

    public function getDkimRotationHistory(Request $request, int $domainId): JsonResponse
    {
        [$domain] = $this->resolveManagementProvider($request, $domainId);

        $logs = AuditLog::where('action', 'email_domain.dkim_rotated')
            ->where('auditable_type', EmailDomain::class)
            ->where('auditable_id', $domain->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'created_at', 'new_values']);

        return response()->json(['history' => $logs]);
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    public function listWebhooks(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'webhooks');

            /** @var HasWebhookManagement $provider */
            $webhooks = $provider->listWebhooks($domain->name, $domain->getEffectiveConfig());

            return response()->json(['webhooks' => $webhooks]);
        });
    }

    public function createWebhook(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'webhooks');

            $validated = $request->validate([
                'event' => ['required', 'string', 'in:delivered,opened,clicked,permanent_fail,temporary_fail,complained,unsubscribed,stored'],
                'url'   => ['required', 'url'],
            ]);

            /** @var HasWebhookManagement $provider */
            $data = $provider->createWebhook(
                $domain->name,
                $validated['event'],
                $validated['url'],
                $domain->getEffectiveConfig(),
            );

            return response()->json($data);
        });
    }

    public function updateWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId, $webhookId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'webhooks');

            $validated = $request->validate([
                'url' => ['required', 'url'],
            ]);

            /** @var HasWebhookManagement $provider */
            $data = $provider->updateWebhook(
                $domain->name,
                $webhookId,
                $validated['url'],
                $domain->getEffectiveConfig(),
            );

            return response()->json($data);
        });
    }

    public function deleteWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId, $webhookId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'webhooks');

            /** @var HasWebhookManagement $provider */
            $data = $provider->deleteWebhook($domain->name, $webhookId, $domain->getEffectiveConfig());

            return response()->json($data);
        });
    }

    public function testWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId, $webhookId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'webhooks');

            /** @var HasWebhookManagement $provider */
            $config = $domain->getEffectiveConfig();
            $webhooks = $provider->listWebhooks($domain->name, $config);

            $webhook = $webhooks[$webhookId] ?? null;
            if (! $webhook) {
                return $this->errorResponse("Webhook '{$webhookId}' is not configured", 404);
            }

            $urls = $webhook['urls'] ?? (is_array($webhook['url'] ?? null) ? $webhook['url'] : [$webhook['url'] ?? '']);
            $url = $urls[0] ?? '';
            if (empty($url)) {
                return $this->errorResponse('Webhook has no URL configured', 422);
            }

            $result = $provider->testWebhook($domain->name, $webhookId, $url, $config);

            return response()->json($result, $result['success'] ? 200 : 502);
        });
    }

    /**
     * Auto-configure all delivery webhooks for this domain to point at the
     * selfmx events endpoint. Uses upsert: create first, update on 400.
     */
    public function autoConfigureWebhooks(Request $request, int $domainId): JsonResponse
    {
        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
        $this->requireCapability($provider, 'webhooks');

        /** @var HasWebhookManagement $provider */
        $appUrl = config('app.url');
        $eventsUrl = "{$appUrl}/api/email/webhook/{$domain->provider}/events";
        $config = $domain->getEffectiveConfig();

        $events = ['delivered', 'permanent_fail', 'complained', 'stored'];
        $results = [];
        $errors = [];
        $lastHttpStatus = 0;

        foreach ($events as $event) {
            try {
                $results[$event] = $provider->createWebhook($domain->name, $event, $eventsUrl, $config);
            } catch (ProviderApiException $e) {
                if ($e->httpStatus === 400) {
                    try {
                        $results[$event] = $provider->updateWebhook($domain->name, $event, $eventsUrl, $config);
                    } catch (ProviderApiException $updateEx) {
                        $errors[$event] = $updateEx->getMessage();
                        $lastHttpStatus = $updateEx->httpStatus;
                    }
                } else {
                    $errors[$event] = $e->getMessage();
                    $lastHttpStatus = $e->httpStatus;
                }
            }
        }

        if (! empty($errors) && empty($results)) {
            $status = in_array($lastHttpStatus, [401, 403]) ? 502 : 422;
            $prefix = in_array($lastHttpStatus, [401, 403])
                ? 'API key lacks permission — ensure you are using the account-level Private API key. '
                : '';

            return $this->errorResponse($prefix . 'Failed to configure webhooks: ' . implode('; ', $errors), $status);
        }

        if (! empty($results)) {
            $this->auditService->log(
                'email_domain.webhooks_configured',
                $domain,
                [],
                ['events' => array_keys($results), 'errors' => $errors, 'url' => $eventsUrl],
                $request->user()->id,
            );
        }

        $response = ['results' => $results];
        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, empty($errors) ? 200 : 207);
    }

    // -------------------------------------------------------------------------
    // Inbound Routes
    // -------------------------------------------------------------------------

    public function listRoutes(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'inbound_routes');

            /** @var HasInboundRoutes $provider */
            $routes = $provider->listRoutes($domain->name, $domain->getEffectiveConfig());

            return response()->json(['routes' => $routes]);
        });
    }

    public function createRoute(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'inbound_routes');

            $validated = $request->validate([
                'expression'  => ['required', 'string'],
                'actions'     => ['required', 'array', 'min:1'],
                'actions.*'   => ['string'],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'priority'    => ['sometimes', 'integer', 'min:0'],
            ]);

            /** @var HasInboundRoutes $provider */
            $data = $provider->createRoute(
                $validated['expression'],
                $validated['actions'],
                $validated['description'] ?? '',
                $validated['priority'] ?? 0,
                $domain->getEffectiveConfig(),
            );

            return response()->json($data);
        });
    }

    public function updateRoute(Request $request, int $domainId, string $routeId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId, $routeId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'inbound_routes');

            $validated = $request->validate([
                'expression'  => ['sometimes', 'string'],
                'action'      => ['sometimes', 'array'],
                'action.*'    => ['string'],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'priority'    => ['sometimes', 'integer', 'min:0'],
            ]);

            /** @var HasInboundRoutes $provider */
            $data = $provider->updateRoute($routeId, $validated, $domain->getEffectiveConfig());

            return response()->json($data);
        });
    }

    public function deleteRoute(Request $request, int $domainId, string $routeId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId, $routeId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'inbound_routes');

            /** @var HasInboundRoutes $provider */
            $data = $provider->deleteRoute($routeId, $domain->getEffectiveConfig());

            return response()->json($data);
        });
    }

    // -------------------------------------------------------------------------
    // Event Log
    // -------------------------------------------------------------------------

    public function getEvents(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'events');

            $validated = $request->validate([
                'event'      => ['sometimes', 'string'],
                'recipient'  => ['sometimes', 'string'],
                'begin'      => ['sometimes', 'string'],
                'end'        => ['sometimes', 'string'],
                'subject'    => ['sometimes', 'string'],
                'message-id' => ['sometimes', 'string'],
                'limit'      => ['sometimes', 'integer', 'min:1', 'max:300'],
                'page'       => ['sometimes', 'nullable', 'string'],
            ]);

            /** @var HasEventLog $provider */
            $data = $provider->getEvents($domain->name, $validated, $domain->getEffectiveConfig());

            return response()->json($data);
        });
    }

    // -------------------------------------------------------------------------
    // Suppressions
    // -------------------------------------------------------------------------

    public function listSuppressions(Request $request, int $domainId, string $type): JsonResponse
    {
        if (! in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type. Must be: bounces, complaints, unsubscribes');
        }

        return $this->wrapProviderCall(function () use ($request, $domainId, $type) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'suppressions');

            $limit = (int) $request->query('limit', 25);
            $page  = $request->query('page');
            $config = $domain->getEffectiveConfig();

            /** @var HasSuppressionManagement $provider */
            $data = match ($type) {
                'bounces'      => $provider->listBounces($domain->name, $limit, $page, $config),
                'complaints'   => $provider->listComplaints($domain->name, $limit, $page, $config),
                'unsubscribes' => $provider->listUnsubscribes($domain->name, $limit, $page, $config),
            };

            return response()->json($data);
        });
    }

    public function deleteSuppression(Request $request, int $domainId, string $type, string $address): JsonResponse
    {
        if (! in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type. Must be: bounces, complaints, unsubscribes');
        }

        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
        $this->requireCapability($provider, 'suppressions');

        $config = $domain->getEffectiveConfig();

        /** @var HasSuppressionManagement $provider */
        $ok = match ($type) {
            'bounces'      => $provider->deleteBounce($domain->name, $address, $config),
            'complaints'   => $provider->deleteComplaint($domain->name, $address, $config),
            'unsubscribes' => $provider->deleteUnsubscribe($domain->name, $address, $config),
        };

        if (! $ok) {
            return $this->errorResponse("Failed to delete {$type} entry for {$address}", 500);
        }

        return $this->successResponse("Removed {$address} from {$type}");
    }

    public function checkSuppression(Request $request, int $domainId): JsonResponse
    {
        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
        $this->requireCapability($provider, 'suppressions');

        $address = $request->query('address', '');
        if (empty($address)) {
            abort(422, 'address query parameter is required');
        }

        /** @var HasSuppressionManagement $provider */
        $data = $provider->checkSuppression($domain->name, $address, $domain->getEffectiveConfig());

        return response()->json($data);
    }

    public function checkSuppressionBatch(Request $request, int $domainId): JsonResponse
    {
        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
        $this->requireCapability($provider, 'suppressions');

        $validated = $request->validate([
            'addresses'   => ['required', 'array', 'min:1', 'max:15'],
            'addresses.*' => ['email'],
        ]);

        $config = $domain->getEffectiveConfig();
        $results = [];

        /** @var HasSuppressionManagement $provider */
        foreach ($validated['addresses'] as $address) {
            $results[$address] = $provider->checkSuppression($domain->name, $address, $config);
        }

        return response()->json(['results' => $results]);
    }

    public function importSuppressions(Request $request, int $domainId, string $type): JsonResponse
    {
        if (! in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type');
        }

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
        $this->requireCapability($provider, 'suppressions');

        $config = $domain->getEffectiveConfig();
        $file = $request->file('file');

        $entries = [];
        $handle = fopen($file->getRealPath(), 'r');
        fgetcsv($handle); // skip header row

        while (($row = fgetcsv($handle)) !== false) {
            if (empty($row[0]) || ! filter_var($row[0], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $entry = ['address' => $row[0]];
            if ($type === 'bounces' && isset($row[1])) {
                $entry['error'] = $row[1];
            }
            if ($type === 'bounces' && isset($row[2])) {
                $entry['code'] = (int) $row[2];
            }
            if ($type === 'unsubscribes' && isset($row[1])) {
                $entry['tag'] = $row[1];
            }
            $entries[] = $entry;
        }
        fclose($handle);

        if (empty($entries)) {
            return $this->errorResponse('CSV file is empty or has no valid entries', 422);
        }

        return $this->wrapProviderCall(function () use ($domain, $provider, $type, $entries, $config) {
            $imported = 0;
            $importMethod = 'import' . ucfirst($type);

            /** @var HasSuppressionManagement $provider */
            foreach (array_chunk($entries, 1000) as $chunk) {
                $provider->{$importMethod}($domain->name, $chunk, $config);
                $imported += count($chunk);
            }

            return response()->json(['imported' => $imported]);
        });
    }

    public function exportSuppressions(Request $request, int $domainId, string $type): StreamedResponse
    {
        if (! in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type');
        }

        [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
        $this->requireCapability($provider, 'suppressions');

        $config = $domain->getEffectiveConfig();
        $filename = "{$domain->name}_{$type}_" . date('Y-m-d_His') . '.csv';

        return response()->stream(function () use ($domain, $provider, $type, $config) {
            $out = fopen('php://output', 'w');

            $headers = match ($type) {
                'bounces'      => ['address', 'error', 'code', 'created_at'],
                'complaints'   => ['address', 'created_at'],
                'unsubscribes' => ['address', 'tag', 'created_at'],
            };
            fputcsv($out, $headers);

            $page = null;
            $pageCount = 0;
            $maxPages = 100;

            /** @var HasSuppressionManagement $provider */
            try {
                do {
                    $data = match ($type) {
                        'bounces'      => $provider->listBounces($domain->name, 100, $page, $config),
                        'complaints'   => $provider->listComplaints($domain->name, 100, $page, $config),
                        'unsubscribes' => $provider->listUnsubscribes($domain->name, 100, $page, $config),
                    };

                    foreach ($data['items'] as $item) {
                        $row = match ($type) {
                            'bounces'      => [$item['address'], $item['error'] ?? '', $item['code'] ?? '', $item['created_at'] ?? ''],
                            'complaints'   => [$item['address'], $item['created_at'] ?? ''],
                            'unsubscribes' => [$item['address'], $item['tag'] ?? '', $item['created_at'] ?? ''],
                        };
                        fputcsv($out, $row);
                    }

                    $page = $this->extractPageToken($data['nextPage'] ?? null);
                    $pageCount++;
                } while ($page && ! empty($data['items']) && $pageCount < $maxPages);
            } catch (\Throwable $e) {
                fputcsv($out, ['ERROR: ' . $e->getMessage()]);
            }

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function extractPageToken(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);

        return $params['p'] ?? $params['page'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Tracking
    // -------------------------------------------------------------------------

    public function getTracking(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'stats');

            /** @var HasDeliveryStats $provider */
            $data = $provider->getTrackingSettings($domain->name, $domain->getEffectiveConfig());

            return response()->json($data);
        });
    }

    public function updateTracking(Request $request, int $domainId, string $type): JsonResponse
    {
        if (! in_array($type, ['click', 'open', 'unsubscribe'])) {
            abort(422, 'Invalid tracking type. Must be: click, open, unsubscribe');
        }

        return $this->wrapProviderCall(function () use ($request, $domainId, $type) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'stats');

            $validated = $request->validate([
                'active' => ['required', 'boolean'],
            ]);

            /** @var HasDeliveryStats $provider */
            $data = $provider->updateTrackingSetting(
                $domain->name,
                $type,
                $validated['active'],
                $domain->getEffectiveConfig(),
            );

            return response()->json($data);
        });
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    public function getStats(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapProviderCall(function () use ($request, $domainId) {
            [$domain, $provider] = $this->resolveManagementProvider($request, $domainId);
            $this->requireCapability($provider, 'stats');

            $validated = $request->validate([
                'duration'   => ['sometimes', 'string', 'in:1d,7d,30d,90d'],
                'resolution' => ['sometimes', 'string', 'in:hour,day,month'],
            ]);

            $events = ['accepted', 'delivered', 'failed', 'complained'];

            /** @var HasDeliveryStats $provider */
            $data = $provider->getDomainStats(
                $domain->name,
                $events,
                $validated['duration'] ?? '30d',
                $validated['resolution'] ?? 'day',
                $domain->getEffectiveConfig(),
            );

            return response()->json($data);
        });
    }
}
