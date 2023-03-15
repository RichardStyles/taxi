<?php

namespace RichardStyles\Taxi;

use http\Exception\BadConversionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use function Taxi\git_branch;
use function Valet\info;

class Taxi
{
    public $taxiBin = BREW_PREFIX . '/bin/taxi';

    public function __construct(public CommandLine $cli, public Filesystem $files)
    {
    }

    /**
     * Symlink the Valet Bash script into the user's local bin.
     */
    public function symlinkToUsersBin(): void
    {
        $this->unlinkFromUsersBin();

        $this->cli->runAsUser('ln -s "' . realpath(__DIR__ . '/../../taxi') . '" ' . $this->taxiBin);
    }

    /**
     * Remove the symlink from the user's local bin.
     */
    public function unlinkFromUsersBin(): void
    {
        $this->cli->quietlyAsUser('rm ' . $this->taxiBin);
    }

    /**
     * Create the "sudoers.d" entry for running Taxi.
     */
    public function createSudoersEntry()
    {
        $this->files->ensureDirExists('/etc/sudoers.d');

        $this->files->put('/etc/sudoers.d/taxi', 'Cmnd_Alias TAXI = ' . BREW_PREFIX . '/bin/taxi *
        %admin ALL=(root) NOPASSWD:SETENV: TAXI' . PHP_EOL);
    }

    /**
     * Remove the "sudoers.d" entry for running Taxi.
     */
    public function removeSudoersEntry()
    {
        $this->cli->quietly('rm /etc/sudoers.d/taxi');
    }

    /**
     * Check to see if a taxi.json config exists in current directory
     */
    public function configExists(): bool
    {
        return $this->files->exists(getcwd() . '/taxi.json');
    }

    /**
     * put single or multi site taxi.json config in current directory
     */
    public function call(bool $single = false): void
    {
        $stub = $single ? 'taxi-single.json' : 'taxi-multisite.json';
        $contents = $this->files->getTaxiStub($stub);

        $this->files->putAsUser(
            getcwd() . '/taxi.json',
            $contents
        );
    }

    /**
     * validate taxi.json config in current directory
     */
    public function validate(): string|bool
    {
        if (!$this->taxiConfigExists()) {
            return 'taxi.json file does not exists. Please run `taxi call`';
        }

        try {
            $config = $this->getTaxiConfig();
        } catch (\BadConfigurationException $e) {
            return 'taxi.json file is not valid JSON';
        }

        if ($this->configDoesntHaveRequiredKeys($config)) {
            return 'taxi.json is missing required keys.';
        }

        if ($this->configDoesntHaveInvalidCommands($config)) {
            return 'taxi.json has invalid commands.';
        }

        return true;
    }

    /**
     * validate taxi.json config has required keys missing
     */
    protected function configDoesntHaveRequiredKeys(array $config): bool
    {
        if (!array_key_exists('repos', $config)) {
            return false;
        }

        $repos = collect($config['repos'])
            ->filter(function ($repo) {
                return array_key_exists('name', $repo) &&
                    array_key_exists('branch', $repo) &&
                    array_key_exists('url', $repo);
            })->count();

        return $repos !== count($config['repos']);
    }

    /**
     * validate taxi.json config has string commands
     */
    protected function configDoesntHaveInvalidCommands(array $config): bool
    {
        $invalid = false;

        if (array_key_exists('hooks', $config) && is_array($config['hooks']) && array_key_exists('build', $config['hooks'])) {
            $invalid = collect($config['hooks']['build'])->filter(function ($hook) {
                    return is_string($hook);
                })->count() !== count($config['hooks']['build']);
        }

        if ($invalid === false) {
            return false;
        }

        if (array_key_exists('hooks', $config) && is_array($config['hooks']) && array_key_exists('reset', $config['hooks'])) {
            $invalid = collect($config['hooks']['reset'])->filter(function ($hook) {
                    return is_string($hook);
                })->count() !== count($config['hooks']['reset']);
        }

        if ($invalid === false) {
            return false;
        }

        $repos = collect($config['repos'])->filter(function ($repo) {
            // check post-build commands are strings
            if (array_key_exists('post-build', $repo) && is_array($repo['post-build'])) {
                return collect($repo['post-build'])->filter(fn($cmd) => is_string($cmd))->count() === count($repo['post-build']);
            }
            // check post-reset commands are strings
            if (array_key_exists('post-reset', $repo) && is_array($repo['post-reset'])) {
                return collect($repo['post-reset'])->filter(fn($cmd) => is_string($cmd))->count() === count($repo['post-reset']);
            }
            return true;
        });

        return $repos->count() == count($config['repos']);
    }

    /**
     * does taxi.json config exist in the current directory
     */
    public function taxiConfigExists(): bool
    {
        return $this->files->exists(
            $this->taxiConfigPath()
        );
    }

    /**
     * get expected path for taxi.json in current directory
     */
    public function taxiConfigPath(): string
    {
        return $configPath = getcwd() . '/taxi.json';
    }

    /**
     * read taxi.json config to array
     */
    public function getTaxiConfig(): array
    {
        $config = $this->files->get(
            $this->taxiConfigPath()
        );

        if (!Str::isJson($config)) {
            throw new BadConversionException;
        }

        return json_decode($config, true);
    }

    /**
     * is current taxi.json setup as a single site
     */
    public function isSingle(): bool
    {
        $config = $this->getTaxiConfig();

        if (count($config['repo']) > 1) {
            return false;
        }

        return true;
    }

    /**
     * run taxi.json commands to reset sites
     */
    public function reset(): void
    {
        $root = getcwd();
        $config = $this->getTaxiConfig();

        collect($config['repos'])->each(function ($site) use ($root, $config) {
            $folder = Str::kebab($site['name']);
            $path = $root . '/' . $folder;

            $currentBranch = git_branch($path);
            if ($currentBranch === $site['branch']) {
                info('No change to ' . $site['name'] . PHP_EOL);
                return $site;
            }

            $response = $this->cli->path($root)->runAsUser('git stash && git checkout ' . $site['branch']);

            $action = "branch changed";

            if (str_contains($response, "No local changes to save")) {
                $action .= ' and stash created ' .
                    Str::after(
                        explode(PHP_EOL, $response)[0],
                        'Saved working directory and index state '
                    );
            }

            info($action);

            // run global install hooks
            info('Running reset commands');
            collect($config['hooks']['reset'])->each(function ($hook) use ($root, $folder) {
                $this->cli->path($root)->runAsUser($hook);
            });

            // run site reset hooks
            info('Running post-reset commands');
            collect($config['post-reset'])->each(function ($hook) use ($root, $folder) {
                $this->cli->path($root)->runAsUser($hook);
            });

            info('Site: ' . $site['name'] . ' installed');
        });
    }

    /**
     * run taxi.json commands to start sites
     */
    public function build(): void
    {
        $valet = false;
        $root = getcwd();

        $taxi = $this->getTaxiConfig();

        if (array_key_exists('valet', $taxi)) {
            $valet = filter_var($taxi['valet'], FILTER_VALIDATE_BOOLEAN);
        }


        collect($taxi['repos'])->map(function ($site) use ($root, $taxi, $valet) {
            $folder = Str::kebab($site['name']);
            $path = $root . '/' . $folder;
            // ensure start at root folder where config is
            info('Cloning repository: ' . $site['name']);
            $this->cli->path($root)->runAsUser('git clone ' . $site['url'] . ' ' . $folder);

            if ($valet) {
                info('  Linking as valet site');
                $this->cli->path($path)->runAsUser('valet link ' . $folder);
            }

            if ($valet && array_key_exists('php', $site)) {
                info('  Isolating PHP version for site');
                $this->cli->path($path)->runAsUser('valet isolate ' . $site['php']);
            }
            // ensure on default branch
            $this->cli->path($path)->runAsUser('git checkout ' . $site['branch']);

            if ($valet && array_key_exists('secure', $site) && $site['secure'] === true) {
                info('  Securing valet site');
                $this->cli->path($path)->runAsUser('valet secure');
            }

            // run global build hooks
            if(array_key_exists('hooks', $taxi) && array_key_exists('build', $taxi['hooks'])) {
                info('  Running build commands');
                collect($taxi['hooks']['build'])->each(function ($hook) use ($path) {
                    $this->cli->path($path)->runAsUser($hook);
                });
            }

            // run site build hooks
            if(array_key_exists('post-build', $site)) {
                info('  Running post-build commands');
                collect($site['post-build'])->each(function ($hook) use ($path) {
                    $this->cli->path($path)->runAsUser($hook);
                });
            }
            info($site['name'] . ' build completed');

            return $site;
        });

        info('build completed');
    }
}