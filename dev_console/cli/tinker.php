<?php

use Psy\Output\Theme;
use Simp\Pindrop\Modules\dev_console\src\Plugin\DevConsoleManager;

return [
    'tinker' => 'devConsoleTinkerCommand',
];

/**
 * ./pindro tinker
 *
 * Drops into an interactive PsySH REPL with the app's service container
 * (and a couple of commonly-needed services) pre-loaded as scope variables —
 * the same idea as Laravel's `artisan tinker`.
 *
 * Deliberately CLI-only. This plugin does not register any HTTP route for
 * this functionality — running arbitrary PHP through a web endpoint is a
 * fundamentally different risk category (effectively an RCE backdoor if
 * not extremely tightly gated) than a REPL that only runs for someone who
 * already has shell/SSH access to the server, which is the same trust
 * boundary every other `./pindro` command relies on. If you need remote
 * access to this, tunnel in over SSH rather than asking this plugin to
 * expose it over HTTP.
 *
 * Requires `psy/psysh` in the project's root composer.json — this plugin
 * does not (and structurally cannot, per Pindrop's shared-vendor plugin
 * model) bundle its own dependencies. See README.md for the install step.
 */
function devConsoleTinkerCommand(CLIPrinter $printer, ...$values): void
{
    if (!class_exists(\Psy\Shell::class)) {
        $printer->printLine('PsySH is not installed.', 'red');
        $printer->printLine('Run this at your Pindrop project root, then try again:', 'yellow');
        $printer->printLine('  composer require --dev psy/psysh', 'yellow');
        return;
    }

    $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';

    if ($env === 'production') {
        $printer->printLine('You are about to open an interactive PHP shell against a PRODUCTION environment.', 'red');
        $printer->printLine('Anything you run here executes for real, against real data — there is no sandbox.', 'red');
        $confirmation = $printer->ask('Type "yes" to continue, anything else to cancel');

        if (strtolower(trim((string) $confirmation)) !== 'yes') {
            $printer->printLine('Cancelled.', 'yellow');
            return;
        }
    }

    $container = null;
    $database = null;

    try {
        $container = \getAppContainer();
        $database = $container->get('database');
    } catch (\Throwable $e) {
        // Non-fatal — tinker is still useful as a plain PHP REPL even if
        // the app container isn't reachable for some reason (e.g. running
        // outside a fully bootstrapped request context). Just warn.
        $printer->printLine('Warning: could not resolve the app container (' . $e->getMessage() . ').', 'yellow');
        $printer->printLine('Continuing anyway — $container/$database will be unavailable.', 'yellow');
    }

    $config = new \Psy\Configuration();
    
    $theme = new Theme(Theme::CLASSIC_THEME);
    $theme->setPrompt('pindrop [' . $env . ']> ');
    $config->setTheme($theme);

    /**
     * @var DevConsoleManager
     */
    $tinkerManager = getAppContainer()->get('tinker.manager');
    $includeScripts = $tinkerManager->getTinkerIncludeScripts();
        
    $shell = new \Psy\Shell($config);
    $shell->setScopeVariables(array_filter([
        'container' => $container,
        'db'        => $database,
    ]));
    $shell->setIncludes($includeScripts);

    $printer->printLine('Pindrop tinker — environment: ' . $env, 'green');
    $printer->printLine('Available: $container (service container), $db (DatabaseService, if resolved).', 'green');
    $printer->printLine('Example: $db->table(\'qa_questions\')->count();', 'green');
    $printer->printLine('Type `exit` or press Ctrl+D to quit.', 'green');

    $shell->run();
}
