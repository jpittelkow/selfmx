<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Email;
use App\Models\EmailRule;
use App\Services\AuditService;
use App\Services\Email\EmailRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailRuleController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private AuditService $auditService,
        private EmailRuleService $emailRuleService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rules = EmailRule::where('user_id', $request->user()->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['rules' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'match_mode' => ['sometimes', Rule::in(['all', 'any'])],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.field' => ['required', Rule::in(['from', 'to', 'cc', 'subject', 'body', 'has_attachment', 'spam_score'])],
            'conditions.*.operator' => ['required', Rule::in(['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'ends_with', 'greater_than', 'less_than'])],
            'conditions.*.value' => ['required'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(['label', 'archive', 'mark_read', 'mark_spam', 'trash', 'forward'])],
            'actions.*.value' => ['nullable'],
            'stop_processing' => ['sometimes', 'boolean'],
        ]);

        $validated['user_id'] = $request->user()->id;
        $validated['match_mode'] = $validated['match_mode'] ?? 'all';
        $validated['sort_order'] = EmailRule::where('user_id', $request->user()->id)->max('sort_order') + 1;

        $rule = EmailRule::create($validated);

        $this->auditService->log('email_rule.created', $rule, null, [
            'name' => $rule->name,
        ]);

        return response()->json(['rule' => $rule], 201);
    }

    public function show(Request $request, EmailRule $rule): JsonResponse
    {
        if ($rule->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        return response()->json(['rule' => $rule]);
    }

    public function update(Request $request, EmailRule $rule): JsonResponse
    {
        if ($rule->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'match_mode' => ['sometimes', Rule::in(['all', 'any'])],
            'conditions' => ['sometimes', 'array', 'min:1'],
            'conditions.*.field' => ['required_with:conditions', Rule::in(['from', 'to', 'cc', 'subject', 'body', 'has_attachment', 'spam_score'])],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'ends_with', 'greater_than', 'less_than'])],
            'conditions.*.value' => ['required_with:conditions'],
            'actions' => ['sometimes', 'array', 'min:1'],
            'actions.*.type' => ['required_with:actions', Rule::in(['label', 'archive', 'mark_read', 'mark_spam', 'trash', 'forward'])],
            'actions.*.value' => ['nullable'],
            'stop_processing' => ['sometimes', 'boolean'],
        ]);

        $rule->update($validated);

        $this->auditService->log('email_rule.updated', $rule, null, [
            'name' => $rule->name,
        ]);

        return response()->json(['rule' => $rule->fresh()]);
    }

    public function destroy(Request $request, EmailRule $rule): JsonResponse
    {
        if ($rule->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $this->auditService->log('email_rule.deleted', $rule, [
            'name' => $rule->name,
        ]);

        $rule->delete();

        return response()->json(['message' => 'Rule deleted']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['required', 'integer'],
        ]);

        $userId = $request->user()->id;

        foreach ($validated['order'] as $index => $ruleId) {
            EmailRule::where('id', $ruleId)
                ->where('user_id', $userId)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Rules reordered']);
    }

    public function test(Request $request, EmailRule $rule): JsonResponse
    {
        if ($rule->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'email_id' => ['required', 'integer'],
        ]);

        $email = Email::where('id', $validated['email_id'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$email) {
            return $this->errorResponse('Email not found', 404);
        }

        $matches = $this->emailRuleService->testRule($rule, $email);

        return response()->json(['matches' => $matches]);
    }
}
