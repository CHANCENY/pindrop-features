<?php

declare(strict_types=1);

use Faker\Factory;
use Simp\Pindrop\Modules\stockflow\src\Services\DummyJsonProductImporter;

return [
    'stockflow:products:dummyjson' => 'stockflowImportDummyJsonProducts',
];

function stockflowImportDummyJsonProducts(CLIPrinter $printer, ...$values): void
{
    $args = $values[1] ?? [];
    if (!is_array($args)) {
        $printer->printLine('Invalid command arguments.', RED);
        exit(2);
    }

    try {
        $options = stockflowDummyJsonOptions(array_slice($args, 2));
        if ($options['help']) {
            $printer->printLine(stockflowDummyJsonHelp(), GREEN);
            return;
        }
        if (!class_exists(Factory::class)) {
            throw new RuntimeException('FakerPHP is not installed. Run composer install first.');
        }
        $faker = Factory::create();
        if ($options['seed'] !== null) {
            $faker->seed($options['seed']);
        }
        $container = getAppContainer();
        $importer = new DummyJsonProductImporter(
            $container->get('database'),
            $container->get('stockflow.service'),
            $faker
        );
        $result = $importer->import(
            $options['count'],
            $options['variants'],
            $options['skip'],
            !$options['commit']
        );

        $printer->printLine(
            ($options['commit'] ? 'Importing' : 'Dry run: would import')
                . ' ' . count($result['products']) . ' of ' . $result['requested']
                . ' requested DummyJSON products.',
            GREEN
        );
        foreach ($result['products'] as $product) {
            $printer->printLine(
                sprintf('  #%d %s (%s; %d variants)', $product['source_id'], $product['name'], $product['category'], $product['variants']),
                GREEN
            );
            $printer->printLine('     Image: ' . $product['image_url'], YELLOW);
        }
        if ($options['commit']) {
            $printer->printLine(sprintf(
                'Created %d products and %d variants; skipped %d existing products.',
                $result['created'],
                $result['variants'],
                $result['skipped']
            ), GREEN);
        } else {
            $printer->printLine(sprintf(
                'No database records were written; %d existing products would be skipped. Add --commit to create the new products.',
                $result['skipped']
            ), YELLOW);
        }
        foreach ($result['failures'] as $failure) {
            $printer->printLine(
                sprintf('Failed product #%d (%s): %s', $failure['source_id'], $failure['name'], $failure['message']),
                RED
            );
        }
        if ($result['received'] < $result['requested']) {
            $printer->printLine(sprintf(
                'DummyJSON returned %d products for the requested offset; increase --skip to fetch another page.',
                $result['received']
            ), YELLOW);
        }
        if ($result['failures'] !== []) {
            exit(1);
        }
    } catch (Throwable $exception) {
        $printer->printLine('Stockflow DummyJSON import failed: ' . $exception->getMessage(), RED);
        $printer->printLine(stockflowDummyJsonHelp(), YELLOW);
        exit(1);
    }
}

function stockflowDummyJsonOptions(array $arguments): array
{
    $options = [
        'count' => 10,
        'variants' => 3,
        'skip' => 0,
        'seed' => null,
        'commit' => false,
        'help' => false,
    ];
    $flags = [
        '--count' => 'count',
        '-n' => 'count',
        '--variants' => 'variants',
        '-v' => 'variants',
        '--skip' => 'skip',
        '--seed' => 'seed',
    ];
    for ($index = 0; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if (in_array($argument, ['--help', '-h'], true)) {
            $options['help'] = true;
            continue;
        }
        if ($argument === '--commit') {
            $options['commit'] = true;
            continue;
        }
        [$flag, $inlineValue] = array_pad(explode('=', $argument, 2), 2, null);
        if (!isset($flags[$flag])) {
            throw new InvalidArgumentException('Unknown option: ' . $flag);
        }
        $value = $inlineValue;
        if ($value === null) {
            $value = $arguments[++$index] ?? null;
        }
        if (!is_string($value) || preg_match('/^\d+$/D', $value) !== 1) {
            throw new InvalidArgumentException($flag . ' requires a non-negative integer.');
        }
        $options[$flags[$flag]] = (int) $value;
    }
    if ($options['seed'] === 0) {
        $options['seed'] = 0;
    }
    if ($options['count'] < 1 || $options['count'] > 100) {
        throw new InvalidArgumentException('--count must be between 1 and 100.');
    }
    if ($options['variants'] < 1 || $options['variants'] > 8) {
        throw new InvalidArgumentException('--variants must be between 1 and 8.');
    }
    if ($options['skip'] > 100_000) {
        throw new InvalidArgumentException('--skip must be 100,000 or less.');
    }
    return $options;
}

function stockflowDummyJsonHelp(): string
{
    return <<<HELP
Usage: ./pindro stockflow:products:dummyjson [options]

Fetches sample products from https://dummyjson.com/products, creates Faker
variants and inventory, and assigns generated DummyJSON placeholder images.
Runs as a dry run unless --commit is supplied.

Options:
  --count, -n N       Number of DummyJSON products to fetch (1-100; default 10)
  --variants, -v N    Variants to create per product (1-8; default 3)
  --skip N            Product offset for DummyJSON pagination (default 0)
  --seed N            Seed Faker data for reproducible variants
  --commit            Write products, variants, inventory, and image records
  --help, -h          Show this help

Example:
  ./pindro stockflow:products:dummyjson --count 5 --variants 3 --seed 42 --commit
HELP;
}
