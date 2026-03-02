<?php

namespace App\Services\Email;

use App\Models\Mailbox;
use App\Models\MailboxGroupAssignment;
use App\Models\MailboxUser;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MailboxAccessService
{
    private const CACHE_TTL = 300; // 5 minutes

    private const CACHE_PREFIX = 'mailbox_access:';

    /**
     * Get all mailbox IDs this user can access with their highest role.
     *
     * @return array<int, string> [mailbox_id => role]
     */
    public function getAccessibleMailboxes(User $user): array
    {
        if ($user->isAdmin()) {
            return Mailbox::pluck('id')
                ->mapWithKeys(fn ($id) => [$id => 'owner'])
                ->toArray();
        }

        $cacheKey = self::CACHE_PREFIX . $user->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $this->computeAccessibleMailboxes($user);
        });
    }

    /**
     * Get just the mailbox IDs for query scoping.
     *
     * @return array<int>
     */
    public function getAccessibleMailboxIds(User $user): array
    {
        return array_keys($this->getAccessibleMailboxes($user));
    }

    /**
     * Check if user has at least the given role on a specific mailbox.
     */
    public function hasAccess(User $user, int $mailboxId, string $minimumRole = 'viewer'): bool
    {
        $accessible = $this->getAccessibleMailboxes($user);
        if (! isset($accessible[$mailboxId])) {
            return false;
        }

        return $this->roleLevel($accessible[$mailboxId]) >= $this->roleLevel($minimumRole);
    }

    /**
     * Check if user can send from a mailbox (requires 'member' or 'owner').
     */
    public function canSend(User $user, int $mailboxId): bool
    {
        return $this->hasAccess($user, $mailboxId, 'member');
    }

    /**
     * Check if user can manage mailbox settings (requires 'owner').
     */
    public function canManage(User $user, int $mailboxId): bool
    {
        return $this->hasAccess($user, $mailboxId, 'owner');
    }

    /**
     * Clear cached access for a specific user.
     */
    public function clearCache(User $user): void
    {
        Cache::forget(self::CACHE_PREFIX . $user->id);
    }

    /**
     * Clear cache for all users who have access to a mailbox.
     * Call when mailbox membership changes.
     */
    public function clearMailboxCache(int $mailboxId): void
    {
        // Direct user assignments
        $userIds = MailboxUser::where('mailbox_id', $mailboxId)->pluck('user_id');

        // Group member assignments
        $groupIds = MailboxGroupAssignment::where('mailbox_id', $mailboxId)->pluck('group_id');
        $groupUserIds = DB::table('user_group_members')
            ->whereIn('group_id', $groupIds)
            ->pluck('user_id');

        $allUserIds = $userIds->merge($groupUserIds)->unique();
        foreach ($allUserIds as $userId) {
            Cache::forget(self::CACHE_PREFIX . $userId);
        }
    }

    /**
     * Clear cache for all members of a group.
     * Call when group membership changes.
     */
    public function clearGroupCache(int $groupId): void
    {
        $userIds = DB::table('user_group_members')
            ->where('group_id', $groupId)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            Cache::forget(self::CACHE_PREFIX . $userId);
        }
    }

    /**
     * Compute accessible mailboxes from direct + group assignments.
     *
     * @return array<int, string>
     */
    private function computeAccessibleMailboxes(User $user): array
    {
        $access = [];

        // Direct user assignments
        $directAssignments = MailboxUser::where('user_id', $user->id)->get(['mailbox_id', 'role']);
        foreach ($directAssignments as $assignment) {
            $access[$assignment->mailbox_id] = $assignment->role;
        }

        // Group-based assignments (highest role wins)
        $groupIds = $user->groups()->pluck('user_groups.id');
        if ($groupIds->isNotEmpty()) {
            $groupAssignments = MailboxGroupAssignment::whereIn('group_id', $groupIds)->get(['mailbox_id', 'role']);
            foreach ($groupAssignments as $assignment) {
                $existingRole = $access[$assignment->mailbox_id] ?? null;
                if ($existingRole === null || $this->roleLevel($assignment->role) > $this->roleLevel($existingRole)) {
                    $access[$assignment->mailbox_id] = $assignment->role;
                }
            }
        }

        return $access;
    }

    /**
     * Convert role string to numeric level for comparison.
     */
    private function roleLevel(string $role): int
    {
        return match ($role) {
            'viewer' => 1,
            'member' => 2,
            'owner' => 3,
            default => 0,
        };
    }
}
