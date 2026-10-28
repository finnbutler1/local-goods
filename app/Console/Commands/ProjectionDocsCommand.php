<?php

namespace App\Console\Commands;

use App\Support\Projections\BusinessModel;
use App\Support\Projections\ProjectionDocs;
use Illuminate\Console\Command;

/**
 * Rewrites the projection figures in README.md and docs/finance/MODEL.md
 * from config/projections.php (ADR 0030). Run it after changing an
 * assumption. No database, no network: it only rewrites the marked blocks
 * in those two files.
 */
class ProjectionDocsCommand extends Command
{
    protected $signature = 'projections:docs';

    protected $description = 'Update the business model figures in the README and docs/finance/MODEL.md';

    public function handle(): int
    {
        $docs = new ProjectionDocs(BusinessModel::fromConfig());

        foreach (array_keys(ProjectionDocs::FILES) as $file) {
            $path = base_path($file);
            $before = file_get_contents($path);
            $after = $docs->apply($before, $file);

            if ($after === $before) {
                $this->line("{$file}: already up to date.");

                continue;
            }

            file_put_contents($path, $after);
            $this->info("{$file}: updated.");
        }

        return self::SUCCESS;
    }
}
