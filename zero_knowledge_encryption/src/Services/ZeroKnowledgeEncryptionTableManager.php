<?php

namespace Simp\Pindrop\Modules\zero_knowledge_encryption\src\Services;

use Simp\Pindrop\Plugin\PluginManager;

class ZeroKnowledgeEncryptionTableManager
{
    protected array $tables = [];

    public function __construct(protected PluginManager $plugin_manager)
    {
        if ($_ENV['APP_ENV'] === 'production') {
            $this->loadTablesFromManifest();
        } else {
            $this->loadTablesFromPluginManager();
        }
    }

    private function loadTablesFromManifest(): void
    {
        $manifest = $_ENV['ROOT']. DIRECTORY_SEPARATOR. 'var'. DIRECTORY_SEPARATOR. 'cache'. 
        DIRECTORY_SEPARATOR. 'plugins'. DIRECTORY_SEPARATOR. 'zero_knowledge_encryption'.
         DIRECTORY_SEPARATOR. 'manifest.php';

        if (file_exists($manifest)) {
            $this->tables = include $manifest;
            return;
        }

        $this->loadTablesFromPluginManager();
       
    }

    private function loadTablesFromPluginManager(): void
    {
        $data = $this->plugin_manager->getPluginsYamlContent('zero_knowledge_encryption');
        foreach ($data as $plugin) {
            $this->tables = array_merge($this->tables, $plugin ?? []);
        }

        // write the manifest file for production use
         $manifest = $_ENV['ROOT']. DIRECTORY_SEPARATOR. 'var'. DIRECTORY_SEPARATOR. 'cache'. 
        DIRECTORY_SEPARATOR. 'plugins'. DIRECTORY_SEPARATOR. 'zero_knowledge_encryption'.
         DIRECTORY_SEPARATOR. 'manifest.php';

        if ($_ENV['APP_ENV'] === 'production') {
            $dir = dirname($manifest);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($manifest, "<?php\n\nreturn " . var_export($this->tables, true) . ";\n");
        } 
    }

    public function getTables(): array
    {
        return $this->tables;
    }

    public function getTableColumns(string $tableName): ?array
    {
        return $this->tables[$tableName] ?? null;
    }

    public static function getIgnoredTables(): array
    {
        // Lists of tables that should be ignored by the Zero Knowledge Encryption plugin
        return [
            'file_managed',
            'general_permissions',
            'logs',
            'node_data',
            'php_sessions',
            'user_session',
            'site_identity_assets',
            'site_identity_settings',
            'site_settings',
            'system_information',
            'theme_library_assets',
            'users',
            'user_verification_tokens'
        ];
    }
}
