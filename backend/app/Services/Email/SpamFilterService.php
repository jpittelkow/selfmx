<?php

namespace App\Services\Email;

use App\Models\SpamFilterList;
use App\Services\SettingService;

class SpamFilterService
{
    public function __construct(
        private SettingService $settingService,
    ) {}

    /**
     * Check if a parsed email should be classified as spam.
     */
    public function isSpam(ParsedEmail $parsed, int $userId): bool
    {
        $fromAddress = strtolower($parsed->fromAddress);

        // Phase 1: Block list check (highest priority)
        if ($this->isBlocklisted($fromAddress, $userId)) {
            return true;
        }

        // Phase 2: Allow list check (overrides threshold)
        if ($this->isAllowlisted($fromAddress, $userId)) {
            return false;
        }

        // Phase 3: Threshold check
        $threshold = (float) $this->settingService->get('email', 'spam_threshold', '5.0');

        if ($parsed->spamScore !== null && $parsed->spamScore >= $threshold) {
            return true;
        }

        return false;
    }

    /**
     * Get the spam score for a parsed email (normalized).
     */
    public function getScore(ParsedEmail $parsed): ?float
    {
        return $parsed->spamScore;
    }

    /**
     * Check if a sender is on the user's block list.
     */
    private function isBlocklisted(string $fromAddress, int $userId): bool
    {
        $domain = $this->extractDomain($fromAddress);

        return SpamFilterList::where('user_id', $userId)
            ->where('type', 'block')
            ->where(function ($q) use ($fromAddress, $domain) {
                $q->where(function ($q) use ($fromAddress) {
                    $q->where('match_type', 'exact')->where('value', $fromAddress);
                })->orWhere(function ($q) use ($domain) {
                    if ($domain) {
                        $q->where('match_type', 'domain')->where('value', $domain);
                    }
                });
            })
            ->exists();
    }

    /**
     * Check if a sender is on the user's allow list.
     */
    private function isAllowlisted(string $fromAddress, int $userId): bool
    {
        $domain = $this->extractDomain($fromAddress);

        return SpamFilterList::where('user_id', $userId)
            ->where('type', 'allow')
            ->where(function ($q) use ($fromAddress, $domain) {
                $q->where(function ($q) use ($fromAddress) {
                    $q->where('match_type', 'exact')->where('value', $fromAddress);
                })->orWhere(function ($q) use ($domain) {
                    if ($domain) {
                        $q->where('match_type', 'domain')->where('value', $domain);
                    }
                });
            })
            ->exists();
    }

    /**
     * Extract the domain from an email address.
     */
    private function extractDomain(string $email): ?string
    {
        $atPos = strpos($email, '@');

        return $atPos !== false ? substr($email, $atPos + 1) : null;
    }
}
