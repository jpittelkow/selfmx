<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Contact;
use App\Services\Email\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        private ContactService $contactService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', config('app.pagination.default', 25));
        $query = Contact::where('user_id', $request->user()->id);

        if ($request->has('q') && $request->input('q') !== '') {
            $searchTerm = $request->input('q');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('email_address', 'like', "%{$searchTerm}%")
                    ->orWhere('display_name', 'like', "%{$searchTerm}%");
            });
        }

        $sortBy = $request->input('sort', 'email_count');
        $sortDir = $request->input('dir', 'desc');
        $allowedSorts = ['email_count', 'display_name', 'email_address', 'last_emailed_at', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('email_count');
        }

        $contacts = $query->paginate($perPage);

        return $this->dataResponse($contacts);
    }

    public function show(Request $request, Contact $contact): JsonResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $contact->load('mergedAddresses');

        return response()->json(['contact' => $contact]);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $contact->update($validated);

        return response()->json(['contact' => $contact->fresh()]);
    }

    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        if ($contact->user_id !== $request->user()->id) {
            return $this->errorResponse('Not found', 404);
        }

        $contact->delete();

        return $this->successResponse('Contact deleted');
    }

    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'primary_id' => ['required', 'integer', 'exists:contacts,id'],
            'secondary_id' => ['required', 'integer', 'exists:contacts,id', 'different:primary_id'],
        ]);

        $primary = Contact::where('id', $validated['primary_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
        $secondary = Contact::where('id', $validated['secondary_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $merged = $this->contactService->mergeContacts($primary, $secondary);

        return response()->json(['contact' => $merged]);
    }

    public function autocomplete(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $limit = min((int) $request->input('limit', 10), 20);

        $contacts = $this->contactService->searchForAutocomplete(
            $request->user()->id,
            $query,
            $limit,
        );

        return response()->json(['contacts' => $contacts]);
    }

    public function backfill(Request $request): JsonResponse
    {
        $count = $this->contactService->backfillForUser($request->user()->id);

        return $this->successResponse("Backfilled contacts from {$count} emails");
    }
}
