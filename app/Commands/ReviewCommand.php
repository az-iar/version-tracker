<?php

namespace App\Commands;

use Cz\Git\GitException;
use Cz\Git\GitRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class ReviewCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'review {path} {--from} {--to}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Review current changes';

    /**
     * Execute the console command.
     *
     * @return mixed
     * @throws \Cz\Git\GitException
     */
    public function handle()
    {
        $path = $this->argument('path');
        $fromCommit = $this->option('from');
        $toCommit = $this->option('to');

        try {
            $repo = new GitRepository($path);
        } catch (GitException $e) {
            $this->error($e->getMessage());

            return;
        }

        $repo->fetch();

        if (!$fromCommit) {
            $tags = collect($repo->getTags())->filter(function ($tag) {
                return Str::startsWith($tag, 'v');
            })->sortBy(function ($tag) {
                return Str::substr($tag, 3);
            })->reverse()->take(10)->map(function ($tag) use ($repo) {
                $commitId = $repo->execute(['show-ref', '--hash', $tag])[0];

                return ['tag' => $tag] + Arr::except($repo->getCommitData($commitId), 'committer');
            });

            $this->line('');
            $this->info('Last 10 Tags:');

            $this->table([
                'Tag',
                'Commit ID',
                'Commit Title',
                'Commit Message',
                'Author',
                'Timestamp',
            ], $tags->toArray());

            $fromCommit = data_get($tags->last(), 'tag');
        }

        $commitsAfterTag = collect($repo->execute(['log', '--pretty=oneline', $fromCommit . '..' . $toCommit]));

        $fixes = $commitsAfterTag->filter(function ($commit) {
            return Str::contains($commit, 'fix');
        })->count();

        $features = $commitsAfterTag->filter(function ($commit) {
            return Str::contains($commit, 'feat');
        })->count();

        $refactor = $commitsAfterTag->filter(function ($commit) {
            return Str::contains($commit, 'refactor');
        })->count();

        $tests = $commitsAfterTag->filter(function ($commit) {
            return Str::contains($commit, 'test');
        })->count();

        $style = $commitsAfterTag->filter(function ($commit) {
            return Str::contains($commit, 'style');
        })->count();

        $this->line('');
        $this->info('Summary:');

        $this->table([
            'Fixes',
            'Features',
            'Refactors',
            'Tests',
            'Style',
        ], [
            [
                $fixes,
                $features,
                $refactor,
                $tests,
                $style,
            ],
        ]);

        $this->line('');

        $lastTagRevision = $repo->execute(['rev-list', '--tags', '--max-count=1'])[0];
        $current = $repo->execute(["describe", "--tags", $lastTagRevision])[0];
        $this->info('Current:' . $current);
        $this->info('Next:' . $this->getNextTag($current, $features, $fixes, $refactor, $tests, $style));
    }

    /**
     * @param int $features
     * @param int $fixes
     * @param int $refactor
     * @param int $tests
     * @param int $style
     * @return string
     */
    protected function getNextTag($current, int $features, int $fixes, int $refactor, int $tests, int $style): string
    {
        $version = str_replace('v', '', $current);
        $parts = explode('.', $version);

        $nextVersion = 'v' . $parts[0] . '.' . ($parts[1] + $features) . '.' . ($parts[2] + $fixes + $refactor + $tests + $style);

        return $nextVersion;
    }
}
