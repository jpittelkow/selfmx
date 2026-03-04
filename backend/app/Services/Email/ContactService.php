<?php

namespace App\Services\Email;

use App\Models\Contact;
use App\Models\ContactMerge;
use App\Models\Email;
use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ContactService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Extract and upsert contacts from an email.
     * Called after processing inbound/outbound emails.
     */
    public function extractFromEmail(Email $email): void
    {
        $userId = $email->user_id;
        $addresses = collect();

        // From address (for inbound emails)
        if ($email->direction === 'inbound') {
            $addresses->push([
                'address' => $email->from_address,
                'name' => $email->from_name,
            ]);
        }

        // All recipients
        $email->loadMissing('recipients');
        foreach ($email->recipients as $recipient) {
            $addresses->push([
                'address' => $recipient->address,
                'name' => $recipient->name,
            ]);
        }

        // Upsert each unique address
        $seen = [];
        foreach ($addresses as $entry) {
            $normalized = strtolower(trim($entry['address']));
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            try {
                $this->upsertContact($userId, $normalized, $entry['name']);
            } catch (\Exception $e) {
                Log::warning('Failed to upsert contact', [
                    'address' => $normalized,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create or update a contact. Updates display_name only if currently null.
     * Increments email_count and updates last_emailed_at.
     */
    public function upsertContact(int $userId, string $address, ?string $name = null): Contact
    {
        // Check merged addresses first
        $merge = ContactMerge::where('merged_email_address', $address)->first();
        if ($merge) {
            $contact = $merge->contact;
            if ($contact && $contact->user_id === $userId) {
                $contact->increment('email_count');
                $contact->update(['last_emailed_at' => now()]);
                return $contact;
            }
        }

        $contact = Contact::firstOrCreate(
            ['user_id' => $userId, 'email_address' => $address],
            ['display_name' => $name, 'email_count' => 0]
        );

        // Update display_name if better info available
        if ($name && !$contact->display_name) {
            $contact->update(['display_name' => $name]);
        }

        $contact->increment('email_count');
        $contact->update(['last_emailed_at' => now()]);

        return $contact;
    }

    /**
     * Merge contact B into contact A.
     * Moves B's email_address to contact_merges, deletes B.
     */
    public function mergeContacts(Contact $primary, Contact $secondary): Contact
    {
        // Record the merge
        ContactMerge::create([
            'contact_id' => $primary->id,
            'merged_email_address' => $secondary->email_address,
        ]);

        // Move secondary's merged addresses to primary
        ContactMerge::where('contact_id', $secondary->id)
            ->update(['contact_id' => $primary->id]);

        // Aggregate counts
        $primary->update([
            'email_count' => $primary->email_count + $secondary->email_count,
            'last_emailed_at' => max(
                $primary->last_emailed_at,
                $secondary->last_emailed_at
            ),
        ]);

        $this->auditService->log('contact.merged', $primary, [], [
            'merged_address' => $secondary->email_address,
        ]);

        $secondary->delete();

        return $primary->fresh();
    }

    /**
     * Search contacts for autocomplete.
     */
    public function searchForAutocomplete(int $userId, string $query, int $limit = 10): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return Contact::where('user_id', $userId)
                ->orderByDesc('email_count')
                ->limit($limit)
                ->get();
        }

        try {
            return Contact::search($query)
                ->where('user_id', $userId)
                ->take($limit)
                ->get();
        } catch (\Exception $e) {
            Log::warning('Contact autocomplete search failed, falling back to database', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            $escaped = \App\Support\Str::escapeLike($query);
            return Contact::where('user_id', $userId)
                ->where(function ($q) use ($escaped) {
                    $q->where('email_address', 'like', "{$escaped}%")
                        ->orWhere('display_name', 'like', "%{$escaped}%");
                })
                ->orderByDesc('email_count')
                ->limit($limit)
                ->get();
        }
    }

    /**
     * Backfill contacts from all existing emails for a user.
     */
    public function backfillForUser(int $userId): int
    {
        $count = 0;
        Email::where('user_id', $userId)
            ->with('recipients')
            ->chunkById(200, function ($emails) use (&$count) {
                foreach ($emails as $email) {
                    $this->extractFromEmail($email);
                    $count++;
                }
            });

        return $count;
    }
}
