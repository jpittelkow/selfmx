<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MailgunApiException;
use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailDomain;
use App\Services\AuditService;
use App\Services\Email\MailgunProvider;
use App\Services\SettingService;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MailgunManagementController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private MailgunProvider $mailgun,
        private AuditService $auditService,
        private SettingService $settingService,
    ) {}

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveDomain(Request $request, int $domainId): EmailDomain
    {
        $domain = EmailDomain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($domain->provider !== 'mailgun') {
            abort(422, 'Domain is not configured with Mailgun');
        }

        return $domain;
    }

    private function domainConfig(EmailDomain $domain): array
    {
        return $domain->provider_config ?? [];
    }

    private function wrapMailgunCall(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (MailgunApiException $e) {
            $status = ($e->httpStatus >= 400 && $e->httpStatus < 600) ? $e->httpStatus : 502;

            // Never pass Mailgun's 401/403 as our response — the frontend intercepts
            // 401 as "session expired" and redirects to login. Map upstream auth
            // errors to 502 (Bad Gateway) so the user sees a proper error toast instead.
            if (in_array($status, [401, 403])) {
                return $this->errorResponse('Mailgun API authentication failed — check your API key configuration. ('.$e->getMessage().')', 502);
            }

            return $this->errorResponse($e->getMessage(), $status);
        }
    }

    // -------------------------------------------------------------------------
    // Provider Health
    // -------------------------------------------------------------------------

    public function checkHealth(Request $request): JsonResponse
    {
        $config = [];
        // Use first Mailgun domain's config if available, otherwise fall back to global key
        $domain = EmailDomain::where('user_id', $request->user()->id)
            ->where('provider', 'mailgun')
            ->first();

        if ($domain) {
            $config = $this->domainConfig($domain);
        }

        $start = microtime(true);
        $ok = $this->mailgun->checkApiHealth($config);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        return response()->json([
            'healthy' => $ok,
            'latency_ms' => $latencyMs,
            'provider' => 'mailgun',
        ]);
    }

    // -------------------------------------------------------------------------
    // DKIM
    // -------------------------------------------------------------------------

    public function getDkim(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $data = $this->mailgun->getDkimKey($domain->name, $this->domainConfig($domain));

            return response()->json($data);
        });
    }

    public function rotateDkim(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $data = $this->mailgun->rotateDkimKey($domain->name, $this->domainConfig($domain));

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
            'enabled' => $intervalDays > 0,
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
            'enabled' => $validated['interval_days'] > 0,
        ]);
    }

    public function getDkimRotationHistory(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);

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
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $webhooks = $this->mailgun->listWebhooks($domain->name, $this->domainConfig($domain));

            return response()->json(['webhooks' => $webhooks]);
        });
    }

    public function createWebhook(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $validated = $request->validate([
                'event' => ['required', 'string', 'in:delivered,opened,clicked,bounced,complained,unsubscribed,stored'],
                'url'   => ['required', 'url'],
            ]);

            $data = $this->mailgun->createWebhook(
                $domain->name,
                $validated['event'],
                $validated['url'],
                $this->domainConfig($domain),
            );

            return response()->json($data);
        });
    }

    public function updateWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId, $webhookId) {
            $domain = $this->resolveDomain($request, $domainId);
            $validated = $request->validate([
                'url' => ['required', 'url'],
            ]);

            $data = $this->mailgun->updateWebhook(
                $domain->name,
                $webhookId,
                $validated['url'],
                $this->domainConfig($domain),
            );

            return response()->json($data);
        });
    }

    public function deleteWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId, $webhookId) {
            $domain = $this->resolveDomain($request, $domainId);
            $data = $this->mailgun->deleteWebhook($domain->name, $webhookId, $this->domainConfig($domain));

            return response()->json($data);
        });
    }

    public function testWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId, $webhookId) {
            $domain = $this->resolveDomain($request, $domainId);
            $webhooks = $this->mailgun->listWebhooks($domain->name, $this->domainConfig($domain));

            $webhook = $webhooks[$webhookId] ?? null;
            if (! $webhook) {
                return $this->errorResponse("Webhook '{$webhookId}' is not configured", 404);
            }

            $urls = $webhook['urls'] ?? (is_array($webhook['url'] ?? null) ? $webhook['url'] : [$webhook['url'] ?? '']);
            $url = $urls[0] ?? '';
            if (empty($url)) {
                return $this->errorResponse('Webhook has no URL configured', 422);
            }

            $result = $this->mailgun->testWebhook(
                $domain->name,
                $webhookId,
                $url,
                $this->domainConfig($domain),
            );

            return response()->json($result, $result['success'] ? 200 : 502);
        });
    }

    /**
     * Auto-configure all selfmx delivery webhooks for this domain.
     * Sets delivered, bounced, complained to the selfmx events endpoint.
     * Uses upsert: tries to create, falls back to update if already exists.
     */
    public function autoConfigureWebhooks(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $appUrl = config('app.url');
        $eventsUrl = "{$appUrl}/api/email/webhook/mailgun/events";
        $config = $this->domainConfig($domain);

        $events = ['delivered', 'bounced', 'complained'];
        $results = [];
        $errors = [];
        $lastHttpStatus = 0;

        foreach ($events as $event) {
            try {
                $results[$event] = $this->mailgun->createWebhook($domain->name, $event, $eventsUrl, $config);
            } catch (MailgunApiException $e) {
                \Log::warning('Webhook autoconfigure create failed', [
                    'event' => $event, 'domain' => $domain->name,
                    'status' => $e->httpStatus, 'message' => $e->getMessage(),
                    'body' => $e->responseBody,
                ]);

                // Only retry as update if Mailgun returned 400 (webhook already exists)
                if ($e->httpStatus === 400) {
                    try {
                        $results[$event] = $this->mailgun->updateWebhook($domain->name, $event, $eventsUrl, $config);
                    } catch (MailgunApiException $updateEx) {
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
            // Map 401/403 to 502 so frontend doesn't interpret as session expired
            $status = in_array($lastHttpStatus, [401, 403]) ? 502 : 422;
            $prefix = in_array($lastHttpStatus, [401, 403])
                ? 'Mailgun API key lacks permission — ensure you are using the account-level Private API key, not a domain sending key. '
                : '';
            return $this->errorResponse($prefix.'Failed to configure webhooks: '.implode('; ', $errors), $status);
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
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $routes = $this->mailgun->listRoutes($domain->name, $this->domainConfig($domain));

            return response()->json(['routes' => $routes]);
        });
    }

    public function createRoute(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $validated = $request->validate([
                'expression'  => ['required', 'string'],
                'actions'     => ['required', 'array', 'min:1'],
                'actions.*'   => ['string'],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'priority'    => ['sometimes', 'integer', 'min:0'],
            ]);

            $data = $this->mailgun->createRoute(
                $validated['expression'],
                $validated['actions'],
                $validated['description'] ?? '',
                $validated['priority'] ?? 0,
                $this->domainConfig($domain),
            );

            return response()->json($data);
        });
    }

    public function updateRoute(Request $request, int $domainId, string $routeId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId, $routeId) {
            $domain = $this->resolveDomain($request, $domainId);
            $validated = $request->validate([
                'expression'  => ['sometimes', 'string'],
                'action'      => ['sometimes', 'array'],
                'action.*'    => ['string'],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'priority'    => ['sometimes', 'integer', 'min:0'],
            ]);

            $data = $this->mailgun->updateRoute($routeId, $validated, $this->domainConfig($domain));

            return response()->json($data);
        });
    }

    public function deleteRoute(Request $request, int $domainId, string $routeId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId, $routeId) {
            $domain = $this->resolveDomain($request, $domainId);
            $data = $this->mailgun->deleteRoute($routeId, $this->domainConfig($domain));

            return response()->json($data);
        });
    }

    // -------------------------------------------------------------------------
    // Event Log
    // -------------------------------------------------------------------------

    public function getEvents(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
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

            $data = $this->mailgun->getEvents($domain->name, $validated, $this->domainConfig($domain));

            return response()->json($data);
        });
    }

    // -------------------------------------------------------------------------
    // Suppressions
    // -------------------------------------------------------------------------

    public function listSuppressions(Request $request, int $domainId, string $type): JsonResponse
    {
        if (!in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type. Must be: bounces, complaints, unsubscribes');
        }

        return $this->wrapMailgunCall(function () use ($request, $domainId, $type) {
            $domain = $this->resolveDomain($request, $domainId);
            $limit = (int) $request->query('limit', 25);
            $page  = $request->query('page');
            $config = $this->domainConfig($domain);

            $data = match ($type) {
                'bounces'      => $this->mailgun->listBounces($domain->name, $limit, $page, $config),
                'complaints'   => $this->mailgun->listComplaints($domain->name, $limit, $page, $config),
                'unsubscribes' => $this->mailgun->listUnsubscribes($domain->name, $limit, $page, $config),
            };

            return response()->json($data);
        });
    }

    public function deleteSuppression(Request $request, int $domainId, string $type, string $address): JsonResponse
    {
        if (!in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type. Must be: bounces, complaints, unsubscribes');
        }

        $domain = $this->resolveDomain($request, $domainId);
        $config = $this->domainConfig($domain);

        $ok = match ($type) {
            'bounces'      => $this->mailgun->deleteBounce($domain->name, $address, $config),
            'complaints'   => $this->mailgun->deleteComplaint($domain->name, $address, $config),
            'unsubscribes' => $this->mailgun->deleteUnsubscribe($domain->name, $address, $config),
        };

        if (!$ok) {
            return $this->errorResponse("Failed to delete {$type} entry for {$address}", 500);
        }

        return $this->successResponse("Removed {$address} from {$type}");
    }

    public function checkSuppression(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $address = $request->query('address', '');

        if (empty($address)) {
            abort(422, 'address query parameter is required');
        }

        $data = $this->mailgun->checkSuppression($domain->name, $address, $this->domainConfig($domain));

        return response()->json($data);
    }

    public function checkSuppressionBatch(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $validated = $request->validate([
            'addresses' => ['required', 'array', 'min:1', 'max:15'],
            'addresses.*' => ['email'],
        ]);

        $config = $this->domainConfig($domain);
        $results = [];
        foreach ($validated['addresses'] as $address) {
            $results[$address] = $this->mailgun->checkSuppression($domain->name, $address, $config);
        }

        return response()->json(['results' => $results]);
    }

    public function importSuppressions(Request $request, int $domainId, string $type): JsonResponse
    {
        if (! in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type');
        }

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $domain = $this->resolveDomain($request, $domainId);
        $config = $this->domainConfig($domain);
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

        return $this->wrapMailgunCall(function () use ($domain, $type, $entries, $config) {
            $imported = 0;
            $importMethod = 'import'.ucfirst($type);
            foreach (array_chunk($entries, 1000) as $chunk) {
                $this->mailgun->{$importMethod}($domain->name, $chunk, $config);
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

        $domain = $this->resolveDomain($request, $domainId);
        $config = $this->domainConfig($domain);
        $filename = "{$domain->name}_{$type}_".date('Y-m-d_His').'.csv';

        return response()->stream(function () use ($domain, $type, $config) {
            $out = fopen('php://output', 'w');

            $headers = match ($type) {
                'bounces' => ['address', 'error', 'code', 'created_at'],
                'complaints' => ['address', 'created_at'],
                'unsubscribes' => ['address', 'tag', 'created_at'],
            };
            fputcsv($out, $headers);

            $page = null;
            $pageCount = 0;
            $maxPages = 100;
            try {
                do {
                    $data = match ($type) {
                        'bounces' => $this->mailgun->listBounces($domain->name, 100, $page, $config),
                        'complaints' => $this->mailgun->listComplaints($domain->name, 100, $page, $config),
                        'unsubscribes' => $this->mailgun->listUnsubscribes($domain->name, 100, $page, $config),
                    };

                    foreach ($data['items'] as $item) {
                        $row = match ($type) {
                            'bounces' => [$item['address'], $item['error'] ?? '', $item['code'] ?? '', $item['created_at'] ?? ''],
                            'complaints' => [$item['address'], $item['created_at'] ?? ''],
                            'unsubscribes' => [$item['address'], $item['tag'] ?? '', $item['created_at'] ?? ''],
                        };
                        fputcsv($out, $row);
                    }

                    $page = $this->extractPageToken($data['nextPage'] ?? null);
                    $pageCount++;
                } while ($page && ! empty($data['items']) && $pageCount < $maxPages);
            } catch (\Throwable $e) {
                fputcsv($out, ['ERROR: '.$e->getMessage()]);
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv',
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
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $data = $this->mailgun->getTrackingSettings($domain->name, $this->domainConfig($domain));

            return response()->json($data);
        });
    }

    public function updateTracking(Request $request, int $domainId, string $type): JsonResponse
    {
        if (! in_array($type, ['click', 'open', 'unsubscribe'])) {
            abort(422, 'Invalid tracking type. Must be: click, open, unsubscribe');
        }

        return $this->wrapMailgunCall(function () use ($request, $domainId, $type) {
            $domain = $this->resolveDomain($request, $domainId);
            $validated = $request->validate([
                'active' => ['required', 'boolean'],
            ]);

            $data = $this->mailgun->updateTrackingSetting(
                $domain->name,
                $type,
                $validated['active'],
                $this->domainConfig($domain),
            );

            return response()->json($data);
        });
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    public function getStats(Request $request, int $domainId): JsonResponse
    {
        return $this->wrapMailgunCall(function () use ($request, $domainId) {
            $domain = $this->resolveDomain($request, $domainId);
            $validated = $request->validate([
                'duration'   => ['sometimes', 'string', 'in:1d,7d,30d,90d'],
                'resolution' => ['sometimes', 'string', 'in:hour,day,month'],
            ]);

            $events = ['accepted', 'delivered', 'failed', 'bounced', 'complained'];
            $data = $this->mailgun->getDomainStats(
                $domain->name,
                $events,
                $validated['duration'] ?? '30d',
                $validated['resolution'] ?? 'day',
                $this->domainConfig($domain),
            );

            return response()->json($data);
        });
    }
}
