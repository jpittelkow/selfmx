<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper for Novu PHP SDK. When Novu is enabled, notifications are sent via Novu API.
 * Uses config('novu.*') which is populated from SettingService at boot.
 */
class NovuService
{
    private ?object $client = null;

    public function isEnabled(): bool
    {
        if (! config('novu.enabled')) {
            return false;
        }
        $apiKey = config('novu.api_key');

        return ! empty(trim((string) $apiKey));
    }

    /**
     * Trigger a Novu workflow for a subscriber.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, transaction_id?: string, error?: string}
     */
    public function triggerWorkflow(string $workflowId, string $subscriberId, array $payload = []): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Novu is not enabled'];
        }

        try {
            $sdk = $this->getClient();
            if (! $sdk) {
                return ['success' => false, 'error' => 'Novu SDK not available'];
            }

            $to = new \novu\Models\Components\SubscriberPayloadDto(
                $subscriberId,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null
            );
            $dto = new \novu\Models\Components\TriggerEventRequestDto(
                $workflowId,
                $to,
                $payload,
                null,
                null,
                null,
                null,
                null
            );
            $response = $sdk->trigger($dto);
            $dtoResponse = $response->triggerEventResponseDto ?? null;
            $transactionId = $dtoResponse->transactionId ?? null;

            return [
                'success' => true,
                'transaction_id' => $transactionId,
            ];
        } catch (\Throwable $e) {
            Log::warning('Novu trigger failed', [
                'workflow_id' => $workflowId,
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create or update a subscriber in Novu (sync user data).
     * Tries create first; on 409 (already exists) calls patch to update.
     */
    public function syncSubscriber(User $user): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $sdk = $this->getClient();
        if (! $sdk) {
            return false;
        }

        $subscriberId = $this->subscriberId($user);
        $name = $user->name ?? '';
        $parts = explode(' ', $name, 2);
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';
        $email = $user->email ?? null;

        try {
            $payload = new \novu\Models\Components\CreateSubscriberRequestDto(
                $subscriberId,
                $firstName !== '' ? $firstName : null,
                $lastName !== '' ? $lastName : null,
                $email,
                null,
                null,
                null,
                null,
                null
            );
            $sdk->subscribers->create($payload);

            return true;
        } catch (\Throwable $e) {
            if ($this->isSubscriberAlreadyExistsException($e)) {
                return $this->updateSubscriber($sdk, $subscriberId, $firstName, $lastName, $email);
            }
            Log::warning('Novu subscriber sync failed', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the exception indicates the subscriber already exists (409 Conflict).
     */
    private function isSubscriberAlreadyExistsException(\Throwable $e): bool
    {
        $message = $e->getMessage();
        if (method_exists($e, 'getStatusCode')) {
            $code = (int) $e->getStatusCode();
            if ($code === 409) {
                return true;
            }
        }
        if (property_exists($e, 'statusCode') && (int) $e->statusCode === 409) {
            return true;
        }

        return (bool) preg_match('/\b409\b/', $message)
            || str_contains(strtolower($message), 'conflict')
            || str_contains(strtolower($message), 'already exists')
            || str_contains(strtolower($message), 'duplicate');
    }

    /**
     * Update an existing subscriber in Novu (called when create returns 409).
     */
    private function updateSubscriber(object $sdk, string $subscriberId, string $firstName, string $lastName, ?string $email): bool
    {
        if (! method_exists($sdk->subscribers, 'patch')
            || ! class_exists(\novu\Models\Components\UpdateSubscriberRequestDto::class)) {
            return false;
        }

        try {
            $updateDto = new \novu\Models\Components\UpdateSubscriberRequestDto(
                $firstName !== '' ? $firstName : null,
                $lastName !== '' ? $lastName : null,
                $email,
                null,
                null,
                null
            );
            $sdk->subscribers->patch($subscriberId, $updateDto);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Novu subscriber update failed', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a subscriber from Novu.
     */
    public function deleteSubscriber(string $subscriberId): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $sdk = $this->getClient();
            if (! $sdk) {
                return false;
            }
            $sdk->subscribers->delete($subscriberId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Novu subscriber delete failed', [
                'subscriber_id' => $subscriberId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Test connection to Novu API and validate workflow mappings.
     *
     * @return array{success: bool, error?: string, workflows_found?: string[], warnings?: array}
     */
    public function testConnection(): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'error' => 'Novu is not enabled or API key is missing'];
        }

        try {
            $sdk = $this->getClient();
            if (! $sdk) {
                return ['success' => false, 'error' => 'Novu SDK not available'];
            }

            $response = $sdk->workflows->list(limit: 100);

            // Extract workflow identifiers from Novu response
            $novuWorkflowIds = [];
            $workflows = $response->listWorkflowsResponseDto->workflows ?? $response->workflows ?? [];
            foreach ($workflows as $workflow) {
                $id = $workflow->workflowId
                    ?? (isset($workflow->triggers[0]) ? $workflow->triggers[0]->identifier : null)
                    ?? $workflow->name
                    ?? null;
                if ($id !== null) {
                    $novuWorkflowIds[] = $id;
                }
            }

            // Compare against our workflow map
            $map = config('novu.workflow_map', []);
            $warnings = [];

            $allTypes = \App\Models\NotificationTemplate::query()
                ->select('type')
                ->distinct()
                ->pluck('type')
                ->toArray();

            // Check for unmapped types
            foreach ($allTypes as $type) {
                if (! isset($map[$type]) || empty($map[$type])) {
                    $warnings[] = [
                        'type' => 'unmapped',
                        'notification_type' => $type,
                        'message' => "No workflow mapped for notification type '{$type}'",
                    ];
                }
            }

            // Check for mapped workflows that don't exist in Novu
            foreach ($map as $type => $workflowId) {
                if (! empty($workflowId) && ! in_array($workflowId, $novuWorkflowIds, true)) {
                    $warnings[] = [
                        'type' => 'missing',
                        'notification_type' => $type,
                        'workflow_id' => $workflowId,
                        'message' => "Workflow '{$workflowId}' for type '{$type}' not found in Novu",
                    ];
                }
            }

            return [
                'success' => true,
                'workflows_found' => $novuWorkflowIds,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get workflow identifier for a notification type (from config workflow_map).
     */
    public function getWorkflowIdForType(string $type): ?string
    {
        $map = config('novu.workflow_map', []);

        return $map[$type] ?? null;
    }

    /**
     * Stable subscriber ID for a user.
     */
    public function subscriberId(User $user): string
    {
        return 'user_'.$user->id;
    }

    /**
     * Lazy-initialize the Novu SDK client.
     */
    private function getClient(): ?object
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (! class_exists(\novu\Novu::class)) {
            return null;
        }

        $apiKey = config('novu.api_key');
        $apiUrl = config('novu.api_url', 'https://api.novu.co');
        if (empty(trim((string) $apiKey))) {
            return null;
        }

        try {
            $builder = \novu\Novu::builder()->setSecurity($apiKey);
            if (method_exists($builder, 'setServerUrl')) {
                $builder->setServerUrl($apiUrl);
            }
            $this->client = $builder->build();

            return $this->client;
        } catch (\Throwable $e) {
            Log::warning('Novu client build failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
