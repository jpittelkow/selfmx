<?php

namespace App\Console\Commands;

use App\Services\Search\SearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SearchReindexCommand extends Command
{

    protected $signature = 'search:reindex
        {model? : Model name to reindex (users, user_groups, pages, etc.). Omit to reindex all.}';

    protected $description = 'Reindex searchable models for Meilisearch/Scout.';

    public function handle(): int
    {
        $modelArg = $this->argument('model');

        $searchableModels = SearchService::getSearchableModels();

        if ($modelArg !== null) {
            $modelArg = strtolower($modelArg);

            if ($modelArg === 'pages') {
                return $this->reindexPages(fatal: true);
            }

            if (! isset($searchableModels[$modelArg])) {
                $available = array_merge(array_keys($searchableModels), ['pages']);
                $this->error("Unknown model: {$modelArg}. Available: " . implode(', ', $available));

                return self::FAILURE;
            }
            $models = [$modelArg => $searchableModels[$modelArg]];
        } else {
            // Pages sync is non-fatal when reindexing all models so that
            // model reindexing can still proceed even if the API key is
            // misconfigured for direct Meilisearch operations.
            $this->reindexPages(fatal: false);
            $models = $searchableModels;
        }

        foreach ($models as $name => $class) {
            $this->info("Reindexing {$name}...");
            try {
                Artisan::call('scout:import', ['model' => $class]);
                $output = trim(Artisan::output());
                if ($output !== '') {
                    $this->line($output);
                }
                $this->info("Reindexed {$name}.");
            } catch (\Throwable $e) {
                $this->error("Reindex failed for {$name}: " . $e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info('Search reindex completed.');

        return self::SUCCESS;
    }

    /**
     * Sync the static pages index to Meilisearch.
     *
     * @param  bool  $fatal  When true, return FAILURE on error (used when
     *                       pages is the explicit target). When false, warn
     *                       and continue (used during full reindex).
     */
    protected function reindexPages(bool $fatal = true): int
    {
        $this->info('Syncing pages index...');

        try {
            $service = app(SearchService::class);
            $result = $service->syncPagesToIndex();
            $this->info("Pages index synced: {$result['count']} pages");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $isKeyError = stripos($message, 'api key') !== false || str_contains($message, 'invalid_api_key');
            $hint = $isKeyError
                ? ' Check the API key in Configuration > Search or the MEILI_MASTER_KEY env var.'
                : '';

            if ($fatal) {
                $this->error('Pages sync failed: ' . $message . $hint);

                return self::FAILURE;
            }

            $this->warn('Pages sync failed (non-fatal): ' . $message . $hint);

            return self::SUCCESS;
        }
    }
}
