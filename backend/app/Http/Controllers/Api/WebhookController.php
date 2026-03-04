<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\UrlValidationService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private UrlValidationService $urlValidator,
        private WebhookService $webhookService
    ) {}

    /**
     * Get all webhooks.
     */
    public function index(): JsonResponse
    {
        $webhooks = Webhook::orderBy('created_at', 'desc')->get();
        $webhooks->each(fn ($w) => $w->setAttribute('secret_set', !empty($w->secret)));

        return $this->dataResponse([
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * Create a new webhook.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'secret' => ['sometimes', 'nullable', 'string'],
            'events' => ['required', 'array'],
            'events.*' => ['string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Validate URL for SSRF protection
        if (!$this->urlValidator->validateUrl($validated['url'])) {
            return $this->errorResponse('Invalid webhook URL: URLs pointing to internal or private addresses are not allowed', 422);
        }

        $webhook = Webhook::create($validated);
        $webhook->setAttribute('secret_set', !empty($webhook->secret));

        return $this->createdResponse('Webhook created successfully', [
            'webhook' => $webhook,
        ]);
    }

    /**
     * Update a webhook.
     */
    public function update(Request $request, Webhook $webhook): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url'],
            'secret' => ['sometimes', 'nullable', 'string'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        // Validate URL for SSRF protection if being updated
        if (isset($validated['url']) && !$this->urlValidator->validateUrl($validated['url'])) {
            return $this->errorResponse('Invalid webhook URL: URLs pointing to internal or private addresses are not allowed', 422);
        }

        $webhook->update($validated);
        $fresh = $webhook->fresh();
        $fresh->setAttribute('secret_set', !empty($fresh->secret));

        return $this->successResponse('Webhook updated successfully', [
            'webhook' => $fresh,
        ]);
    }

    /**
     * Delete a webhook.
     */
    public function destroy(Webhook $webhook): JsonResponse
    {
        $webhook->delete();

        return $this->deleteResponse('Webhook deleted successfully');
    }

    /**
     * Get webhook deliveries.
     */
    public function deliveries(Webhook $webhook, Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', config('app.pagination.default'));

        $deliveries = $webhook->deliveries()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->dataResponse($deliveries);
    }

    /**
     * Test a webhook.
     */
    public function test(Webhook $webhook): JsonResponse
    {
        $result = $this->webhookService->sendTest($webhook);

        $status = ($result['ssrf_blocked'] ?? false) ? 422 : ($result['success'] ? 200 : 500);

        if ($status >= 400) {
            return $this->errorResponse($result['message'], $status);
        }

        return $this->successResponse($result['message'], [
            'success' => $result['success'],
            'status_code' => $result['status_code'] ?? null,
        ]);
    }
}
