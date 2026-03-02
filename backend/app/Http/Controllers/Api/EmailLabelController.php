<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\EmailLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailLabelController extends Controller
{
    use ApiResponseTrait;

    public function index(Request $request): JsonResponse
    {
        $labels = EmailLabel::where('user_id', $request->user()->id)
            ->withCount('emails')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['labels' => $labels]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:7'],
        ]);

        // Check uniqueness for this user
        $exists = EmailLabel::where('user_id', $request->user()->id)
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return $this->errorResponse('A label with this name already exists', 422);
        }

        $maxOrder = EmailLabel::where('user_id', $request->user()->id)->max('sort_order') ?? 0;

        $label = EmailLabel::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'color' => $validated['color'] ?? null,
            'sort_order' => $maxOrder + 1,
        ]);

        return $this->createdResponse('Label created successfully', ['label' => $label]);
    }

    public function update(Request $request, EmailLabel $emailLabel): JsonResponse
    {
        if ($emailLabel->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:7'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $emailLabel->update($validated);

        return $this->successResponse('Label updated successfully', ['label' => $emailLabel->fresh()]);
    }

    public function destroy(Request $request, EmailLabel $emailLabel): JsonResponse
    {
        if ($emailLabel->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $emailLabel->delete();

        return $this->successResponse('Label deleted successfully');
    }

    public function assign(Request $request, EmailLabel $emailLabel): JsonResponse
    {
        if ($emailLabel->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'email_ids' => ['required', 'array'],
            'email_ids.*' => ['integer', 'exists:emails,id'],
        ]);

        $emailLabel->emails()->syncWithoutDetaching($validated['email_ids']);

        return $this->successResponse('Label assigned successfully');
    }

    public function unassign(Request $request, EmailLabel $emailLabel): JsonResponse
    {
        if ($emailLabel->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'email_ids' => ['required', 'array'],
            'email_ids.*' => ['integer', 'exists:emails,id'],
        ]);

        $emailLabel->emails()->detach($validated['email_ids']);

        return $this->successResponse('Label removed successfully');
    }
}
