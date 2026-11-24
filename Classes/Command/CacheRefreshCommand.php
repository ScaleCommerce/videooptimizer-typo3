<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Command;

use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Registry;

/**
 * Cache invalidation, polling variant (for installs without webhooks; schedulable via the TYPO3
 * scheduler "Execute console commands" task, e.g. every 5-15 minutes).
 *
 * Fetches the fresh video list (bypassing the soft TTL), fingerprints every video (sha1 of title,
 * status, ready, error, duration, poster/thumbnail/embed URL, updated_at) and compares it with the
 * previous run. Fingerprints live in sys_registry (not in a cache), so flushing caches does not
 * trigger a full invalidation. For changed/removed videos the tags vo_video_<uuid> (+ library) are
 * flushed in the API cache and the page cache; vo_lists is flushed if anything changed. The first
 * run only records a baseline and flushes the lists once.
 *
 * Options: --dry-run (report only, nothing flushed or stored), --warm (re-fetch each video into the
 * cache afterwards). Exit codes: 0 ok, 1 API/runtime failure or no token.
 */
#[AsCommand(
    name: 'videooptimizer:cache:refresh',
    description: 'Detects changed/removed videos via the API and invalidates the affected API and page cache entries.',
)]
final class CacheRefreshCommand extends Command
{
    private const REGISTRY_NAMESPACE = 'tx_videooptimizer';
    private const REGISTRY_KEY = 'videoFingerprints';

    public function __construct(
        private readonly VideoOptimizerClient $client,
        private readonly Registry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report changes only; flush and store nothing.')
            ->addOption('warm', null, InputOption::VALUE_NONE, 'Also warm the cache (getVideo) for every video.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $warm = (bool)$input->getOption('warm');

        if (!$this->client->hasToken()) {
            $output->writeln('<error>No API token configured (extension configuration apiToken or VO_API_TOKEN).</error>');

            return Command::FAILURE;
        }

        try {
            $videos = $this->client->refreshAllVideos();
        } catch (VideoOptimizerException $exception) {
            $output->writeln('<error>Could not fetch videos: ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $current = [];
        $libraries = [];
        foreach ($videos as $video) {
            $uuid = (string)($video['uuid'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $current[$uuid] = $this->fingerprint($video);
            $libraries[$uuid] = (string)($video['library_id'] ?? '');
        }

        $previous = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);
        $baseline = !is_array($previous);
        $previous = is_array($previous) ? $previous : [];

        $changed = [];
        $added = [];
        foreach ($current as $uuid => $hash) {
            if (!array_key_exists($uuid, $previous)) {
                $added[] = $uuid;
            } elseif ($previous[$uuid] !== $hash) {
                $changed[] = $uuid;
            }
        }
        $removed = array_values(array_diff(array_keys($previous), array_keys($current)));

        $output->writeln(sprintf(
            '%d videos; %s%d changed, %d new, %d removed.',
            count($current),
            $baseline ? 'no previous run (baseline), ' : '',
            count($changed),
            count($added),
            count($removed)
        ), OutputInterface::VERBOSITY_NORMAL);
        foreach ([['changed', $changed], ['new', $added], ['removed', $removed]] as [$label, $uuids]) {
            foreach ($uuids as $uuid) {
                $output->writeln(sprintf('  %s: %s', $label, $uuid), OutputInterface::VERBOSITY_VERBOSE);
            }
        }

        $anything = $changed !== [] || $added !== [] || $removed !== [] || $baseline;
        if ($dryRun) {
            $output->writeln('Dry run: nothing flushed or stored.');

            return Command::SUCCESS;
        }

        // The list entry was just refreshed by refreshAllVideos(); the tag flush also drops other
        // list shapes (pages, per-library lists) and the page cache.
        foreach (array_merge($changed, $removed) as $uuid) {
            try {
                $this->client->flushVideo($uuid, $libraries[$uuid] ?? null);
            } catch (\Throwable $exception) {
                $output->writeln('<comment>Flush failed for ' . $uuid . ': ' . $exception->getMessage() . '</comment>');
            }
        }
        if ($anything) {
            $this->client->flushLists();
            // flushLists() dropped the freshly fetched full list as well; store it again.
            $this->client->storeAllVideos($videos);
        }

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $current);

        if ($warm) {
            $warmed = 0;
            foreach (array_keys($current) as $uuid) {
                try {
                    $this->client->getVideo($uuid);
                    $warmed++;
                } catch (VideoOptimizerException $exception) {
                    $output->writeln('<comment>Warm failed for ' . $uuid . ': ' . $exception->getMessage() . '</comment>');
                }
            }
            $output->writeln(sprintf('Warmed %d videos.', $warmed));
        }

        $output->writeln($anything ? 'Caches invalidated.' : 'No changes.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $video
     */
    private function fingerprint(array $video): string
    {
        $parts = [];
        foreach (['title', 'status', 'ready', 'error', 'duration', 'poster_url', 'thumbnail_url', 'embed_url', 'updated_at'] as $field) {
            $value = $video[$field] ?? null;
            $parts[$field] = is_scalar($value) || $value === null ? $value : json_encode($value);
        }

        return sha1((string)json_encode($parts));
    }
}
