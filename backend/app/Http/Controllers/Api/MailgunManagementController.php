<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailDomain;
use App\Services\AuditService;
use App\Services\Email\MailgunProvider;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $ok = $this->mailgun->checkApiHealth($config);

        return response()->json(['healthy' => $ok]);
    }

    // -------------------------------------------------------------------------
    // DKIM
    // -------------------------------------------------------------------------

    public function getDkim(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $data = $this->mailgun->getDkimKey($domain->name, $this->domainConfig($domain));

        return response()->json($data);
    }

    public function rotateDkim(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $data = $this->mailgun->rotateDkimKey($domain->name, $this->domainConfig($domain));

        // Update the rotation timestamp
        $domain->update(['dkim_rotated_at' => now()]);

        $this->auditService->log(
            'email_domain.dkim_rotated',
            $domain,
            [],
            ['rotated_at' => now()->toIso8601String()],
            $request->user()->id,
        );

        return response()->json($data);
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    public function listWebhooks(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $webhooks = $this->mailgun->listWebhooks($domain->name, $this->domainConfig($domain));

        return response()->json(['webhooks' => $webhooks]);
    }

    public function createWebhook(Request $request, int $domainId): JsonResponse
    {
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
    }

    public function updateWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
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
    }

    public function deleteWebhook(Request $request, int $domainId, string $webhookId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $data = $this->mailgun->deleteWebhook($domain->name, $webhookId, $this->domainConfig($domain));

        return response()->json($data);
    }

    /**
     * Auto-configure all selfmx delivery webhooks for this domain.
     * Sets delivered, bounced, complained to the selfmx events endpoint.
     */
    public function autoConfigureWebhooks(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $appUrl = config('app.url');
        $eventsUrl = "{$appUrl}/api/email/webhook/mailgun/events";
        $config = $this->domainConfig($domain);

        $events = ['delivered', 'bounced', 'complained'];
        $results = [];

        foreach ($events as $event) {
            $results[$event] = $this->mailgun->createWebhook($domain->name, $event, $eventsUrl, $config);
        }

        $this->auditService->log(
            'email_domain.webhooks_configured',
            $domain,
            [],
            ['events' => $events, 'url' => $eventsUrl],
            $request->user()->id,
        );

        return response()->json(['results' => $results]);
    }

    // -------------------------------------------------------------------------
    // Inbound Routes
    // -------------------------------------------------------------------------

    public function listRoutes(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $routes = $this->mailgun->listRoutes($domain->name, $this->domainConfig($domain));

        return response()->json(['routes' => $routes]);
    }

    public function createRoute(Request $request, int $domainId): JsonResponse
    {
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
    }

    public function updateRoute(Request $request, int $domainId, string $routeId): JsonResponse
    {
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
    }

    public function deleteRoute(Request $request, int $domainId, string $routeId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $data = $this->mailgun->deleteRoute($routeId, $this->domainConfig($domain));

        return response()->json($data);
    }

    // -------------------------------------------------------------------------
    // Event Log
    // -------------------------------------------------------------------------

    public function getEvents(Request $request, int $domainId): JsonResponse
    {
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
    }

    // -------------------------------------------------------------------------
    // Suppressions
    // -------------------------------------------------------------------------

    public function listSuppressions(Request $request, int $domainId, string $type): JsonResponse
    {
        if (!in_array($type, ['bounces', 'complaints', 'unsubscribes'])) {
            abort(422, 'Invalid suppression type. Must be: bounces, complaints, unsubscribes');
        }

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

    // -------------------------------------------------------------------------
    // Tracking
    // -------------------------------------------------------------------------

    public function getTracking(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->resolveDomain($request, $domainId);
        $data = $this->mailgun->getTrackingSettings($domain->name, $this->domainConfig($domain));

        return response()->json($data);
    }

    public function updateTracking(Request $request, int $domainId, string $type): JsonResponse
    {
        if (!in_array($type, ['click', 'open', 'unsubscribe'])) {
            abort(422, 'Invalid tracking type. Must be: click, open, unsubscribe');
        }

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
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    public function getStats(Request $request, int $domainId): JsonResponse
    {
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
    }
}
