<?php

namespace App\Commands;

use Closure;
use DateTime;
use stdClass;
use Github\ResultPager;
use Illuminate\Support\Facades\Config;
use GrahamCampbell\GitHub\Facades\GitHub;
use LaravelZero\Framework\Commands\Command;

class Search extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'search
                            {query* : Search query in GitHub syntax}
                            {--token= : Personal access token}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * @var array
     */
    protected $candidates = [];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->setUpConnection($this->token());

        $this->displayLimits('core');
        $this->displayLimits('search');

        $this->paginateUsers($this->argument('query'), function (array $users, int $totalCount) {
            $this->info(sprintf('Found %d potential matches.', $totalCount), 'v');

            foreach ($users as $user) {
                $candidate = new stdClass();
                $candidate->login = $user['login'];
                $candidate->repos = [];

                $this->paginateOwnedRepos($user, $this->language(), function (array $repos) use ($user, $candidate) {
                    foreach ($repos as $repo) {
                        $candidate->repos[] = [
                            'full_name' => $repo['full_name'],
                            'description' => $repo['description'],
                            'updated_at' => $repo['updated_at'],
                            'stargazers_count' => $repo['stargazers_count'],
                            'forks' => $repo['forks'],
                        ];
                    }
                });

                if (count($candidate->repos)) {
                    $this->info(sprintf('Found %s with %d repos.', $candidate->login, count($candidate->repos)), 'v');
                }
            }
        });
    }

    /**
     * @param string|null $token
     */
    protected function setUpConnection(?string $token): void
    {
        $token = trim($token);
        if ($token) {
            Config::set('github.connections.main.token', $token);
            GitHub::setDefaultConnection('main');
        } else {
            GitHub::setDefaultConnection('none');
        }
    }

    /**
     * @param array $criteria
     * @param Closure $callback
     */
    protected function paginateUsers(array $criteria, Closure $callback): void
    {
        $paginator = new ResultPager(GitHub::connection());

        // Get the first page
        $results = $paginator->fetch(GitHub::search(), 'users', [implode(' ', $criteria)]);
        if (count($results['items'])) {
            $callback($results['items'], $results['total_count']);
        }

        // Cycle through the next pages
        while ($paginator->hasNext()) {
            $results = $paginator->fetchNext();
            $callback($results['items']);
        }
    }

    /**
     * @todo Make sure the user's commits are the majority
     * @param array $user
     * @param string|null $language
     * @param Closure $callback
     */
    protected function paginateOwnedRepos(array $user, ?string $language, Closure $callback): void
    {
        $paginator = new ResultPager(GitHub::connection());

        $repositories = $paginator->fetch(GitHub::user(), 'repositories', [$user['login']]);

        do {
            if (count($repositories)) {
                $repositories = $this->filterOwnedRepos($repositories, $language);

                // Pass back any original repos that are left
                if (count($repositories)) {
                    $callback($repositories);

                    // Clear results to exit the loop
                    $repositories = [];
                }

                if ($paginator->hasNext()) {
                    $repositories = $paginator->fetchNext();
                }
            }
        } while (count($repositories));
    }

    /**
     * @param array $repos
     * @param string $language
     * @return array
     */
    protected function filterOwnedRepos(array $repos, string $language): array
    {
        return array_filter($repos, function (array $repo) use ($language) {
            return $repo['language'] == $language && $repo['fork'] == false;
        });
    }

    /**
     * @param string $resource
     */
    protected function displayLimits(string $resource): void
    {
        $limits = GitHub::api('rate_limit')->getResource($resource);

        $this->line(sprintf(
            'You have %d/%d %s requests left and limit resets at %s.',
            $limits->getRemaining(),
            $limits->getLimit(),
            $limits->getName(),
            (new DateTime())->setTimestamp($limits->getReset())->format('Y-m-d H:i:s')
        ), null, 'vv');
    }

    /**
     * @return string|null
     */
    protected function token(): ?string
    {
        $token = null;
        if ($this->option('token')) {
            $token = $this->secret('Paste your personal GitHub access token');
        }

        return $token;
    }

    /**
     * @return string|null
     */
    protected function language(): ?string
    {
        $query = $this->argument('query');

        foreach ($query as $criterium) {
            if (strtok($criterium, ':') == 'language') {
                return strtok(':');
            }
        }

        return null;
    }
}
