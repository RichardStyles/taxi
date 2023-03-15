<?php

use Illuminate\Container\Container;
use Silly\Application;
use Silly\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use function Valet\info;
use function Valet\output;
use function Valet\table;
use function Valet\warning;
use function Valet\writer;
use function Taxi\git_branch;


$version = '0.9.0';

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../autoload.php')) {
    require_once __DIR__ . '/../../../autoload.php';
} else {
    require_once getenv('HOME') . '/.composer/vendor/autoload.php';
}

/**
 * Create the application.
 */
Container::setInstance(new Container);

$app = new Application('Taxi', $version);

$app->setDispatcher($dispatcher = new EventDispatcher());

$dispatcher->addListener(
    ConsoleEvents::COMMAND,
    function (ConsoleCommandEvent $event) {
        writer($event->getOutput());
    });

/*
 * build Taxi sites - run installation process for taxi.json
 */
$app->command('build', function (OutputInterface $output) {
    if (!Taxi::configExists()) {
        return warning('Taxi configuration missing - run `taxi call` to generate');
    }

    if (Taxi::validate() !== true) {
        return warning('Taxi configuration is invalid - run `taxi valid` for more information');
    }

    Taxi::build();
})->descriptions('Build all sites and initialise from config command');

/**
 * Call Taxi to install either in root directory or in a single app
 */
$app->command('call [--single] [--force]', function (OutputInterface $output, $single, $force) {
    if (!$force && Taxi::configExists()) {
        return warning('Taxi configuration file exists, use --force to overwrite');
    }

    Taxi::call($single);

    return info('Taxi configuration file added');
})->descriptions('Setup Taxi for multiple or single sites', [
    '--single' => 'Setup taxi config for a single site',
    '--force'  => 'Overwrite any existing taxi config',
]);

/**
 * Install Taxi
 */
$app->command('install', function (OutputInterface $output) {

    Taxi::symlinkToUsersBin();

    output(PHP_EOL . '<info>Taxi installed successfully!</info>' . PHP_EOL . '<info>Advised to run `taxi trust`</info>');
})->descriptions('Install Taxi');

/*
 * Reset Taxi sites
 */
$app->command('reset', function (OutputInterface $output) {
    if (Taxi::validate() !== true) {
        return warning('Taxi configuration is invalid - run `taxi valid` for more information');
    }
    Taxi::reset();
})->descriptions('Reset taxi sites back to default branch');

/*
 * List Taxi sites
 */
$app->command('sites', function (OutputInterface $output) {
    $sites = Site::links();

    $sites = $sites->filter(function (array $site) {
        return file_exists($site['path'] . '/taxi.json');
    })->map(function (array $site) {

        $site['branch'] = git_branch($site['path']);
        return $site;
    });

    table(['Site', 'SSL', 'URL', 'Path', 'PHP Version', 'Git Branch'], $sites->all());

//    output(PHP_EOL.'<info>Taxi configuration file added</info>');
})->descriptions('List all sites which currently use Taxi');

/**
 * Install the sudoers.d entries so password is no longer required.
 */
$app->command('trust [--off]', function (OutputInterface $output, $off) {
    if ($off) {
        Taxi::removeSudoersEntry();

        return info('Sudoers entries have been removed for Taxi.');
    }

    Taxi::createSudoersEntry();

    info('Sudoers entries have been added for Taxi.');
})->descriptions('Add sudoers file for Taxi to make Taxi commands run without passwords', [
    '--off' => 'Remove the sudoers files so normal sudo password prompts are required.',
]);

/**
 * Install the sudoers.d entries so password is no longer required.
 */
$app->command('type', function (OutputInterface $output) {
    if (Taxi::isSingle()) {
        return info('Local Taxi config is for a single site');
    }

    info('Taxi config is for multiple sites');
})->descriptions('Identify if local Taxi is for single or multiple sites');

/**
 * List sites in Valet + add Taxi state
 */
$app->command('valet', function (OutputInterface $output) {
    $sites = Site::links();

    $sites = $sites->map(function (array $site) {
        $local = file_exists($site['path'] . '/taxi.json');
        $multi = file_exists('../' . $site['path'] . '/taxi.json');

        $site['taxi'] = (!$local && !$multi) ? '' : ($local ? 'single' : 'multi');

        return $site;
    });

    table(['Site', 'SSL', 'URL', 'Path', 'PHP Version', 'Taxi'], $sites->all());

//    output(PHP_EOL.'<info>Taxi configuration file added</info>');
})->descriptions('List all sites which currently use Taxi');
/**
 * Validate a taxi configuration file
 */
$app->command('valid', function (OutputInterface $output) {

    $validated = Taxi::validate();

    if ($validated === true) {
        return info('Taxi configuration is valid');
    }

    if ($validated === false) {
        return warning('Taxi configuration is invalid - unable to identify issue');
    }

    warning('Taxi configuration error' . PHP_EOL . $validated);
})->descriptions('Validate taxi.json file');

return $app;