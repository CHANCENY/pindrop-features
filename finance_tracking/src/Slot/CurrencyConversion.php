<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Slot;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Modules\signals_slots\src\Slot\SlotInterface;
use Simp\Pindrop\Plugin\PluginManager;
use Simp\Price\lib\Price;

class CurrencyConversion implements SlotInterface
{
    public function handle(array $payload): void
    {
        $base = $payload['base_currency'] ?? null;
        $new = $payload['new_currency'] ?? null;

        if (!empty($base) && !empty($new)) {
            /**
             * @var PluginManager
             */
            $pluginManager = getAppContainer()->get('plugin.manager');

            $conversionSettings = $pluginManager->getPluginsYamlContent('convertable.column');

            foreach ($conversionSettings as $plugin) {
                foreach ($plugin as $table => $columns) {

                    $primary = $columns['primary'];
                    $cols = $columns['columns'];

                    $this->runConversions($base, $new, $table,$primary,$cols);

                }
            }


        }
    }

    private function runConversions(string $base_currency, string $new_currency, string $table, string $primary, array $cols)
    {
        /**
         * @var DatabaseService
         */
        $database = getAppContainer()->get('database');

        $col_data = $database->table($table)->select([...$cols, $primary])->latest($primary)->get();

        foreach ($col_data as $data) {
            $prices = [];
            $primary_value = null;
            foreach ($data as $k => $c) {
                if ($k === $primary) {
                    $primary_value = $c;
                    continue;
                }
                $prices[$k] = (new Price((float) $c, $base_currency))->convert($new_currency)->getAmount();
            }

            if (!empty($primary_value)) {
                $database->table($table)->where($primary, '=', $primary_value)
                ->update($prices);
            }
        }
    }
}