<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'videooptimizer:demo',
    description: 'Creates the VideoOptimizer integration samples on the root page (idempotent).',
)]
class DemoCommand extends Command
{
    /**
     * The sample content elements, in display order. A sample is identified by CType and header, so one
     * element type can appear several times with different options.
     * Samples flagged videoUuid seed the demo video into vo_video_uuid; grid/list show all videos;
     * `fields` are further tt_content columns.
     *
     * @var list<array{ctype: string, header: string, sorting: int, videoUuid?: bool, fields?: array<string, scalar>}>
     */
    private const SAMPLES = [
        ['ctype' => 'videooptimizer_videohero', 'header' => 'Full-bleed hero (background video)', 'sorting' => 128, 'videoUuid' => true],
        ['ctype' => 'videooptimizer_videosingle', 'header' => '1) Single video player', 'sorting' => 256, 'videoUuid' => true],
        ['ctype' => 'videooptimizer_videogrid', 'header' => '2) Video grid (multiple videos)', 'sorting' => 512],
        ['ctype' => 'videooptimizer_videolist', 'header' => '3) Video list (all videos)', 'sorting' => 768],
        ['ctype' => 'videooptimizer_videodetail', 'header' => '4) Video detail with metadata', 'sorting' => 1024, 'videoUuid' => true],
        [
            'ctype' => 'videooptimizer_videosplit', 'header' => '5) Video next to text', 'sorting' => 1280, 'videoUuid' => true,
            'fields' => [
                'vo_eyebrow' => 'Media split',
                'bodytext' => '<p>The video sits next to your text. Edit headline, copy and button in the content element; the side is a setting.</p><p>Native player, shown directly.</p>',
                'vo_cta_label' => 'Learn more',
                'vo_cta_link' => 'https://videooptimizer.eu/en/docs',
                'vo_player_mode' => 'native',
            ],
        ],
        [
            'ctype' => 'videooptimizer_videospotlight', 'header' => '6) Spotlight', 'sorting' => 1536, 'videoUuid' => true,
            'fields' => [
                'vo_eyebrow' => 'Spotlight',
                'vo_caption' => 'One prominent video, centered, with a caption.',
                'vo_player_mode' => 'native',
                'vo_presentation' => 'facade',
            ],
        ],
        [
            'ctype' => 'videooptimizer_videochapters', 'header' => '7) Video with chapters (native)', 'sorting' => 1792, 'videoUuid' => true,
            'fields' => ['vo_player_mode' => 'native', 'vo_scene_count' => 5, 'vo_scene_titles' => "Intro\nThe problem\nThe idea\nThe result\nOutro"],
        ],
        [
            'ctype' => 'videooptimizer_videochapters', 'header' => '8) Video with chapters (hosted)', 'sorting' => 2048, 'videoUuid' => true,
            'fields' => ['vo_scene_count' => 4],
        ],
        [
            'ctype' => 'videooptimizer_videosingle', 'header' => '9) Single video, 9:16 format (native, focal point left)', 'sorting' => 2304, 'videoUuid' => true,
            'fields' => ['vo_player_mode' => 'native', 'vo_format' => '9-16', 'vo_focus_x' => 25, 'vo_focus_y' => 50],
        ],
        [
            'ctype' => 'videooptimizer_videosingle', 'header' => '10) Single video, 1:1 format (hosted)', 'sorting' => 2560, 'videoUuid' => true,
            'fields' => ['vo_format' => '1-1'],
        ],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Environment::getContext()->isDevelopment()) {
            $output->writeln('<error>This command is for local development only and refuses to run outside TYPO3_CONTEXT=Development.</error>');

            return Command::FAILURE;
        }

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');

        if ((int)$pages->count('uid', 'pages', ['uid' => 1]) === 0) {
            $output->writeln('<error>Root page (uid 1) not found. Create a root page first.</error>');
            return Command::FAILURE;
        }

        $ttContent = $pool->getConnectionForTable('tt_content');
        $now = time();
        $created = 0;

        foreach (self::SAMPLES as $sample) {
            $exists = (int)$ttContent->count(
                'uid',
                'tt_content',
                ['pid' => 1, 'CType' => $sample['ctype'], 'header' => $sample['header']]
            ) > 0;

            if ($exists) {
                $output->writeln(sprintf('Already present: %s', $sample['header']));
                continue;
            }

            $data = [
                'pid' => 1,
                'header' => $sample['header'],
                'CType' => $sample['ctype'],
                'sorting' => $sample['sorting'],
                'crdate' => $now,
                'tstamp' => $now,
            ];

            if (!empty($sample['videoUuid'])) {
                $demo = getenv('VO_DEMO_VIDEO_UUID');
                if (is_string($demo) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($demo)) === 1) {
                    $data['vo_video_uuid'] = trim($demo);
                }
            }

            $ttContent->insert('tt_content', $data + ($sample['fields'] ?? []));
            $created++;
            $output->writeln(sprintf('Created: %s', $sample['header']));
        }

        if ($created === 0) {
            $output->writeln('All demo samples already exist on the root page. Nothing to do.');
        } else {
            $output->writeln(sprintf('Done. Created %d content element(s) on the root page.', $created));
        }

        return Command::SUCCESS;
    }
}
