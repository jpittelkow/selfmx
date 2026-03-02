<?php

namespace App\Services\Email;

use App\Models\Email;
use App\Models\EmailRule;
use Illuminate\Support\Facades\Log;

class EmailRuleService
{
    /**
     * Evaluate all active rules for a user against an inbound email.
     */
    public function evaluateRules(Email $email, int $userId): void
    {
        $rules = EmailRule::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matchesRule($rule, $email)) {
                $this->executeActions($rule->actions, $email);

                Log::info('Email rule matched', [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'email_id' => $email->id,
                ]);

                if ($rule->stop_processing) {
                    break;
                }
            }
        }
    }

    /**
     * Test a single rule against an email without executing actions.
     */
    public function testRule(EmailRule $rule, Email $email): bool
    {
        return $this->matchesRule($rule, $email);
    }

    /**
     * Check if a rule's conditions match an email.
     */
    private function matchesRule(EmailRule $rule, Email $email): bool
    {
        $conditions = $rule->conditions;
        if (empty($conditions)) {
            return false;
        }

        $isAndMode = $rule->match_mode === 'all';

        foreach ($conditions as $condition) {
            $result = $this->evaluateCondition($condition, $email);

            if ($isAndMode && !$result) {
                return false; // AND mode: one failure means no match
            }
            if (!$isAndMode && $result) {
                return true; // OR mode: one success means match
            }
        }

        // AND mode: all passed; OR mode: none passed
        return $isAndMode;
    }

    /**
     * Evaluate a single condition against an email.
     */
    private function evaluateCondition(array $condition, Email $email): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? '';
        $value = $condition['value'] ?? '';

        $fieldValue = $this->getFieldValue($field, $email);

        // Boolean field handling (has_attachment)
        if ($field === 'has_attachment') {
            return (bool) $fieldValue === (bool) $value;
        }

        // Numeric field handling (spam_score)
        if ($field === 'spam_score') {
            $numericValue = (float) $fieldValue;
            $compareValue = (float) $value;

            return match ($operator) {
                'greater_than' => $numericValue > $compareValue,
                'less_than' => $numericValue < $compareValue,
                'equals' => abs($numericValue - $compareValue) < 0.001,
                default => false,
            };
        }

        // String field handling
        $fieldValue = strtolower((string) $fieldValue);
        $value = strtolower((string) $value);

        return match ($operator) {
            'contains' => str_contains($fieldValue, $value),
            'not_contains' => !str_contains($fieldValue, $value),
            'equals' => $fieldValue === $value,
            'not_equals' => $fieldValue !== $value,
            'starts_with' => str_starts_with($fieldValue, $value),
            'ends_with' => str_ends_with($fieldValue, $value),
            default => false,
        };
    }

    /**
     * Get the value of a field from an email for comparison.
     */
    private function getFieldValue(string $field, Email $email): mixed
    {
        return match ($field) {
            'from' => $email->from_address,
            'to' => $email->recipients?->where('type', 'to')->pluck('address')->implode(', ') ?? '',
            'cc' => $email->recipients?->where('type', 'cc')->pluck('address')->implode(', ') ?? '',
            'subject' => $email->subject ?? '',
            'body' => $email->body_text ?? strip_tags($email->body_html ?? ''),
            'has_attachment' => $email->attachments()->exists(),
            'spam_score' => $email->spam_score,
            default => '',
        };
    }

    /**
     * Execute the actions defined in a rule.
     */
    private function executeActions(array $actions, Email $email): void
    {
        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            match ($type) {
                'label' => $this->applyLabel($email, (int) $value),
                'archive' => $email->update(['is_archived' => true, 'is_read' => true]),
                'mark_read' => $email->update(['is_read' => true]),
                'mark_spam' => $email->update(['is_spam' => true]),
                'trash' => $email->update(['is_trashed' => true]),
                'forward' => $this->forwardEmail($email, (string) $value),
                default => Log::warning("Unknown email rule action: {$type}"),
            };
        }
    }

    /**
     * Apply a label to an email.
     */
    private function applyLabel(Email $email, int $labelId): void
    {
        // Use syncWithoutDetaching to avoid duplicates
        $email->labels()->syncWithoutDetaching([$labelId]);
    }

    /**
     * Forward an email to another address.
     */
    private function forwardEmail(Email $email, string $forwardTo): void
    {
        if (empty($forwardTo)) {
            return;
        }

        try {
            $emailService = app(EmailService::class);
            $emailService->sendEmail($email->user, [
                'mailbox_id' => $email->mailbox_id,
                'to' => [['address' => $forwardTo]],
                'subject' => 'Fwd: ' . ($email->subject ?? ''),
                'body_html' => $email->body_html ?? $email->body_text ?? '',
                'body_text' => $email->body_text,
                'in_reply_to' => $email->message_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to forward email via rule', [
                'email_id' => $email->id,
                'forward_to' => $forwardTo,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
