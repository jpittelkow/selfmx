<?php

namespace App\Services\Email;

use App\Jobs\ProcessEmailAIJob;
use App\Models\Email;
use App\Models\EmailAIResult;
use App\Models\EmailLabel;
use App\Models\EmailThread;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LLM\LLMOrchestrator;
use Illuminate\Support\Facades\Log;

class EmailAIService
{
    public function __construct(
        private LLMOrchestrator $orchestrator,
        private AuditService $auditService,
    ) {}

    /**
     * Check if user has AI features available (has an enabled provider).
     */
    public function isAvailable(User $user): bool
    {
        return $user->aiProviders()->enabled()->exists();
    }

    /**
     * Get which AI features are enabled for this user.
     */
    public function getEnabledFeatures(User $user): array
    {
        return [
            'summarization' => (bool) $user->getSetting('email_ai', 'summarization_enabled', true),
            'auto_labeling' => (bool) $user->getSetting('email_ai', 'auto_labeling_enabled', false),
            'priority_inbox' => (bool) $user->getSetting('email_ai', 'priority_inbox_enabled', false),
            'smart_replies' => (bool) $user->getSetting('email_ai', 'smart_replies_enabled', false),
        ];
    }

    /**
     * Dispatch AI processing jobs for an inbound email based on user's enabled features.
     */
    public function dispatchProcessing(Email $email): void
    {
        $user = $email->user;
        if (!$this->isAvailable($user)) {
            return;
        }

        $processAuto = (bool) $user->getSetting('email_ai', 'process_inbound_automatically', true);
        if (!$processAuto) {
            return;
        }

        $features = $this->getEnabledFeatures($user);

        if ($features['auto_labeling']) {
            ProcessEmailAIJob::dispatch($user->id, $email->id, $email->thread_id, 'auto_labeling');
        }

        if ($features['priority_inbox']) {
            ProcessEmailAIJob::dispatch($user->id, $email->id, $email->thread_id, 'priority_inbox');
        }

        // Summarization is triggered on-demand (when user views the thread), not on inbound
        // Smart replies are triggered on-demand (when user clicks generate), not on inbound
    }

    // ─── Thread Summarization ───────────────────────────────────────────

    /**
     * Summarize a thread. Returns cached result if fresh, or generates a new one.
     */
    public function summarizeThread(User $user, EmailThread $thread, ?array $mailboxIds = null): ?EmailAIResult
    {
        // Check cache
        $cached = $this->getCachedResult($thread->id, 'summary', $user->id, 'thread');
        if ($cached && !$this->isStaleSummary($cached, $thread)) {
            return $cached;
        }

        $query = $thread->emails()
            ->where('is_trashed', false)
            ->orderBy('sent_at', 'asc');

        if ($mailboxIds) {
            $query->whereIn('mailbox_id', $mailboxIds);
        }

        $emails = $query->get();

        if ($emails->count() < 2) {
            return null;
        }

        $threadContent = $this->prepareThreadContent($emails);

        $systemPrompt = 'You are an email summarization assistant. Given a thread of emails, provide:
1. A concise summary (2-3 sentences maximum)
2. Key points (up to 5 bullet points)
3. Action items (if any)

Respond ONLY with valid JSON in this exact format:
{"summary": "...", "key_points": ["...", "..."], "action_items": ["..."]}';

        $prompt = "Summarize this email thread:\n\n{$threadContent}";

        $result = $this->queryLLM($user, $prompt, $systemPrompt);
        if (!$result) {
            return null;
        }

        $parsed = $this->parseJsonResponse($result['response']);
        if (!$parsed) {
            return null;
        }

        // Add message_count for staleness detection
        $parsed['message_count'] = $thread->message_count;

        $latestEmail = $emails->last();

        $aiResult = EmailAIResult::updateOrCreate(
            ['thread_id' => $thread->id, 'type' => 'summary', 'user_id' => $user->id],
            [
                'email_id' => $latestEmail->id,
                'result' => $parsed,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'input_tokens' => $result['tokens']['input'] ?? null,
                'output_tokens' => $result['tokens']['output'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
                'version' => ($cached?->version ?? 0) + 1,
            ]
        );

        $this->auditService->log('email_ai.summary_generated', $aiResult, userId: $user->id);

        return $aiResult;
    }

    /**
     * Check if a summary is stale (thread has new messages).
     */
    public function isStaleSummary(EmailAIResult $result, EmailThread $thread): bool
    {
        $cachedCount = $result->result['message_count'] ?? 0;
        return $thread->message_count !== $cachedCount;
    }

    // ─── Smart Categorization / Auto-Labeling ───────────────────────────

    /**
     * Suggest labels for an email.
     */
    public function suggestLabels(User $user, Email $email): ?EmailAIResult
    {
        // Check cache
        $cached = $this->getCachedResult($email->id, 'labels', $user->id, 'email');
        if ($cached) {
            return $cached;
        }

        $userLabels = EmailLabel::where('user_id', $user->id)->get();
        $labelList = $userLabels->map(fn ($l) => "ID:{$l->id} \"{$l->name}\"")->implode(', ');

        $emailContent = $this->prepareEmailContent($email, 4000);

        $systemPrompt = 'You are an email categorization assistant. Given an email and a list of existing labels, suggest which labels apply. You may also suggest new label categories if none of the existing labels are appropriate.

Respond ONLY with valid JSON in this exact format:
{"suggested_labels": [{"name": "Label Name", "existing_label_id": 5, "confidence": 0.9, "reason": "short reason"}], "categories": ["work", "finance"]}

Rules:
- existing_label_id should be the ID of a matching existing label, or null if suggesting a new one
- confidence should be 0.0 to 1.0
- Suggest 1-5 labels maximum
- Only suggest labels with confidence >= 0.5';

        $prompt = "Existing labels: [{$labelList}]\n\nEmail:\nFrom: {$email->from_address} ({$email->from_name})\nSubject: {$email->subject}\n\n{$emailContent}";

        $result = $this->queryLLM($user, $prompt, $systemPrompt);
        if (!$result) {
            return null;
        }

        $parsed = $this->parseJsonResponse($result['response']);
        if (!$parsed || !isset($parsed['suggested_labels'])) {
            return null;
        }

        $aiResult = EmailAIResult::updateOrCreate(
            ['email_id' => $email->id, 'type' => 'labels', 'user_id' => $user->id],
            [
                'thread_id' => $email->thread_id,
                'result' => $parsed,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'input_tokens' => $result['tokens']['input'] ?? null,
                'output_tokens' => $result['tokens']['output'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
            ]
        );

        $this->auditService->log('email_ai.labels_suggested', $aiResult, userId: $user->id);

        // Auto-apply if enabled
        $autoApply = (bool) $user->getSetting('email_ai', 'auto_labeling_auto_apply', false);
        if ($autoApply) {
            $this->autoApplyLabels($email, $parsed['suggested_labels']);
        }

        return $aiResult;
    }

    /**
     * Auto-apply high-confidence labels that match existing user labels.
     */
    private function autoApplyLabels(Email $email, array $suggestions): void
    {
        $existingLabelIds = $email->labels()->pluck('email_labels.id')->toArray();

        foreach ($suggestions as $suggestion) {
            $labelId = $suggestion['existing_label_id'] ?? null;
            $confidence = $suggestion['confidence'] ?? 0;

            if ($labelId && $confidence >= 0.8 && !in_array($labelId, $existingLabelIds)) {
                $label = EmailLabel::find($labelId);
                if ($label && $label->user_id === $email->user_id) {
                    $email->labels()->attach($labelId);
                    $this->auditService->log('email_ai.labels_applied', $email, userId: $email->user_id);
                }
            }
        }
    }

    // ─── Priority Scoring ───────────────────────────────────────────────

    /**
     * Score the priority of an email.
     */
    public function scorePriority(User $user, Email $email): ?EmailAIResult
    {
        // Check cache
        $cached = $this->getCachedResult($email->id, 'priority', $user->id, 'email');
        if ($cached) {
            return $cached;
        }

        // Step 1: Rule-based pre-score
        $ruleScore = $this->ruleBasedPriorityScore($user, $email);

        // Step 2: If score is ambiguous (0.2-0.8), use LLM to refine
        if ($ruleScore >= 0.2 && $ruleScore <= 0.8) {
            return $this->llmPriorityScore($user, $email, $ruleScore);
        }

        // Step 3: For clear scores, store rule-based result without LLM
        $level = $ruleScore > 0.7 ? 'high' : ($ruleScore > 0.3 ? 'medium' : 'low');
        $result = [
            'score' => round($ruleScore, 2),
            'level' => $level,
            'reasons' => $this->getRuleBasedReasons($user, $email),
            'source' => 'rules',
        ];

        return EmailAIResult::updateOrCreate(
            ['email_id' => $email->id, 'type' => 'priority', 'user_id' => $user->id],
            [
                'thread_id' => $email->thread_id,
                'result' => $result,
            ]
        );
    }

    /**
     * Rule-based priority pre-score (no LLM cost).
     */
    private function ruleBasedPriorityScore(User $user, Email $email): float
    {
        $score = 0.0;

        // Sender frequency (how often do we interact?)
        $senderCount = Email::where('user_id', $user->id)
            ->where('from_address', $email->from_address)
            ->count();

        if ($senderCount > 20) {
            $score += 0.2;
        } elseif ($senderCount > 5) {
            $score += 0.1;
        }

        // Direct recipient (To) vs CC
        $email->loadMissing('recipients');
        $userMailboxes = $user->mailboxes()->pluck('address')->toArray();
        $isDirectRecipient = $email->recipients
            ->where('type', 'to')
            ->whereIn('address', $userMailboxes)
            ->isNotEmpty();

        if ($isDirectRecipient) {
            $score += 0.15;
        }

        // Thread activity (active conversations are more important)
        if ($email->thread_id) {
            $threadCount = Email::where('thread_id', $email->thread_id)->count();
            if ($threadCount > 3) {
                $score += 0.1;
            }
        }

        // Subject urgency keywords
        $urgentKeywords = ['urgent', 'asap', 'deadline', 'action required', 'important', 'critical', 'emergency'];
        $subject = strtolower($email->subject ?? '');
        foreach ($urgentKeywords as $keyword) {
            if (str_contains($subject, $keyword)) {
                $score += 0.15;
                break;
            }
        }

        return min($score, 1.0);
    }

    /**
     * Get human-readable reasons for rule-based score.
     */
    private function getRuleBasedReasons(User $user, Email $email): array
    {
        $reasons = [];

        $senderCount = Email::where('user_id', $user->id)
            ->where('from_address', $email->from_address)
            ->count();

        if ($senderCount > 20) {
            $reasons[] = 'Frequent contact';
        } elseif ($senderCount > 5) {
            $reasons[] = 'Known contact';
        }

        $urgentKeywords = ['urgent', 'asap', 'deadline', 'action required', 'important', 'critical', 'emergency'];
        $subject = strtolower($email->subject ?? '');
        foreach ($urgentKeywords as $keyword) {
            if (str_contains($subject, $keyword)) {
                $reasons[] = 'Contains urgency indicator';
                break;
            }
        }

        return $reasons ?: ['Standard priority'];
    }

    /**
     * LLM-enhanced priority scoring for ambiguous cases.
     */
    private function llmPriorityScore(User $user, Email $email, float $ruleScore): ?EmailAIResult
    {
        $emailContent = $this->prepareEmailContent($email, 2000);

        $senderCount = Email::where('user_id', $user->id)
            ->where('from_address', $email->from_address)
            ->count();

        $systemPrompt = 'You are an email priority scoring assistant. Score this email\'s importance from 0.0 to 1.0.

Consider: sender relationship, urgency, direct address, content signals, thread activity.

Respond ONLY with valid JSON:
{"score": 0.85, "level": "high", "reasons": ["reason 1", "reason 2"]}

Where level is: "low" (0-0.3), "medium" (0.3-0.7), "high" (0.7-1.0)';

        $prompt = "From: {$email->from_address} ({$email->from_name})\nSubject: {$email->subject}\nSender history: {$senderCount} previous emails\nRule-based pre-score: {$ruleScore}\n\n{$emailContent}";

        $result = $this->queryLLM($user, $prompt, $systemPrompt);
        if (!$result) {
            // Fall back to rule-based score
            $level = $ruleScore > 0.7 ? 'high' : ($ruleScore > 0.3 ? 'medium' : 'low');
            return EmailAIResult::updateOrCreate(
                ['email_id' => $email->id, 'type' => 'priority', 'user_id' => $user->id],
                [
                    'thread_id' => $email->thread_id,
                    'result' => [
                        'score' => round($ruleScore, 2),
                        'level' => $level,
                        'reasons' => $this->getRuleBasedReasons($user, $email),
                        'source' => 'rules',
                    ],
                ]
            );
        }

        $parsed = $this->parseJsonResponse($result['response']);
        if (!$parsed || !isset($parsed['score'])) {
            return null;
        }

        $parsed['source'] = 'llm';

        $aiResult = EmailAIResult::updateOrCreate(
            ['email_id' => $email->id, 'type' => 'priority', 'user_id' => $user->id],
            [
                'thread_id' => $email->thread_id,
                'result' => $parsed,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'input_tokens' => $result['tokens']['input'] ?? null,
                'output_tokens' => $result['tokens']['output'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
            ]
        );

        $this->auditService->log('email_ai.priority_scored', $aiResult, userId: $user->id);

        return $aiResult;
    }

    // ─── Smart Reply Suggestions ────────────────────────────────────────

    /**
     * Generate reply suggestions for an email (on-demand only).
     */
    public function generateReplies(User $user, Email $email, ?array $mailboxIds = null): ?EmailAIResult
    {
        // Check cache
        $cached = $this->getCachedResult($email->id, 'replies', $user->id, 'email');
        if ($cached) {
            return $cached;
        }

        // Build context from thread (last 3 messages)
        $context = '';
        if ($email->thread_id) {
            $query = Email::where('thread_id', $email->thread_id)
                ->where('is_trashed', false)
                ->orderBy('sent_at', 'desc')
                ->take(3);

            if ($mailboxIds) {
                $query->whereIn('mailbox_id', $mailboxIds);
            }

            $threadEmails = $query->get()->reverse();

            foreach ($threadEmails as $threadEmail) {
                $body = $this->prepareEmailContent($threadEmail, 2000);
                $context .= "[From: {$threadEmail->from_address}] {$body}\n---\n";
            }
        } else {
            $context = $this->prepareEmailContent($email, 4000);
        }

        $systemPrompt = 'You are an email reply assistant. Generate 3 short reply suggestions for this email.

Each reply should be:
- 1-3 sentences
- A different tone/approach (positive, inquisitive, deferring)
- Natural and conversational
- Appropriate for a professional email

Respond ONLY with valid JSON:
{"suggestions": [{"text": "reply text...", "tone": "positive", "action": "acknowledge"}, {"text": "...", "tone": "inquisitive", "action": "question"}, {"text": "...", "tone": "professional", "action": "defer"}]}';

        $prompt = "Generate reply suggestions for this email conversation:\n\n{$context}\n\nThe latest email to reply to:\nFrom: {$email->from_address} ({$email->from_name})\nSubject: {$email->subject}";

        $result = $this->queryLLM($user, $prompt, $systemPrompt);
        if (!$result) {
            return null;
        }

        $parsed = $this->parseJsonResponse($result['response']);
        if (!$parsed || !isset($parsed['suggestions'])) {
            return null;
        }

        $aiResult = EmailAIResult::updateOrCreate(
            ['email_id' => $email->id, 'type' => 'replies', 'user_id' => $user->id],
            [
                'thread_id' => $email->thread_id,
                'result' => $parsed,
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
                'input_tokens' => $result['tokens']['input'] ?? null,
                'output_tokens' => $result['tokens']['output'] ?? null,
                'duration_ms' => $result['duration_ms'] ?? null,
            ]
        );

        $this->auditService->log('email_ai.replies_generated', $aiResult, userId: $user->id);

        return $aiResult;
    }

    // ─── Shared Helpers ─────────────────────────────────────────────────

    /**
     * Get a cached AI result.
     */
    public function getCachedResult(int $entityId, string $type, int $userId, string $entity = 'email'): ?EmailAIResult
    {
        $query = EmailAIResult::ofType($type)->where('user_id', $userId);

        if ($entity === 'thread') {
            $query->where('thread_id', $entityId);
        } else {
            $query->where('email_id', $entityId);
        }

        return $query->latestVersion()->first();
    }

    /**
     * Query the LLM, always using single mode to control costs.
     */
    private function queryLLM(User $user, string $prompt, string $systemPrompt): ?array
    {
        try {
            $result = $this->orchestrator->query(
                user: $user,
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                mode: 'single',
            );

            if (!($result['success'] ?? false)) {
                Log::warning('Email AI LLM query failed', [
                    'user_id' => $user->id,
                    'error' => $result['error'] ?? 'Unknown',
                ]);
                return null;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Email AI LLM query exception', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parse a JSON response from the LLM, handling markdown code fences.
     */
    private function parseJsonResponse(string $response): ?array
    {
        // Strip markdown code fences if present
        $response = trim($response);
        if (str_starts_with($response, '```')) {
            $response = preg_replace('/^```(?:json)?\s*\n?/', '', $response);
            $response = preg_replace('/\n?```\s*$/', '', $response);
        }

        $decoded = json_decode(trim($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('Email AI failed to parse JSON response', [
                'error' => json_last_error_msg(),
                'response' => mb_substr($response, 0, 500),
            ]);
            return null;
        }

        return $decoded;
    }

    /**
     * Prepare email body for LLM input (strip HTML, truncate).
     */
    private function prepareEmailContent(Email $email, int $maxChars = 4000): string
    {
        $text = $email->body_text;

        if (!$text && $email->body_html) {
            $text = strip_tags($email->body_html);
        }

        if (!$text) {
            return '[No content]';
        }

        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars) . '... [truncated]';
        }

        return $text;
    }

    /**
     * Prepare thread content for LLM (concatenate all emails with metadata).
     */
    private function prepareThreadContent($emails, int $maxChars = 8000): string
    {
        $parts = [];
        $totalChars = 0;
        $perEmailBudget = (int) ($maxChars / max($emails->count(), 1));

        foreach ($emails as $i => $email) {
            $date = $email->sent_at?->format('Y-m-d H:i') ?? 'Unknown';
            $header = "[Email " . ($i + 1) . "] From: {$email->from_address} | Date: {$date}";
            $body = $this->prepareEmailContent($email, $perEmailBudget);

            $part = "{$header}\nSubject: {$email->subject}\n{$body}";
            $totalChars += mb_strlen($part);

            if ($totalChars > $maxChars) {
                $parts[] = "[... earlier messages truncated ...]";
                break;
            }

            $parts[] = $part;
        }

        return implode("\n\n---\n\n", $parts);
    }
}
