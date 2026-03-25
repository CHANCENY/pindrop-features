<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\admin\src\Services;

use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Plugin\PluginManager;

/**
 * Admin Service
 * 
 * Provides admin functionality and services.
 */
class AdminService
{
    private LoggerInterface $logger;
    private PluginManager $pluginManager;
    
    public function __construct(LoggerInterface $logger, PluginManager $pluginManager)
    {
        $this->logger = $logger;
        $this->pluginManager = $pluginManager;
    }
    
    /**
     * Get system statistics
     */
    public function getSystemStats(): array
    {
        return [
            'users_count' => $this->getUsersCount(),
            'content_count' => $this->getContentCount(),
            'media_count' => $this->getMediaCount(),
            'system_uptime' => $this->getSystemUptime()
        ];
    }
    
    /**
     * Get users count
     */
    private function getUsersCount(): int
    {
        // Placeholder - would query database
        return 150;
    }
    
    /**
     * Get content count
     */
    private function getContentCount(): int
    {
        // Placeholder - would query database
        return 45;
    }
    
    /**
     * Get media count
     */
    private function getMediaCount(): int
    {
        // Placeholder - would query database
        return 230;
    }
    
    /**
     * Get system uptime
     */
    private function getSystemUptime(): string
    {
        // Placeholder - would calculate actual uptime
        return '15 days, 3 hours';
    }
    
    /**
     * Log admin action
     */
    public function logAction(string $action, array $context = []): void
    {
        $this->logger->info("Admin action: {$action}", $context);
    }
    
    /**
     * Get all plugins
     */
    public function getPlugins(): array
    {
        return $this->pluginManager->getPlugins();
    }
    
    /**
     * Get plugin by name
     */
    public function getPlugin(string $name): ?array
    {
        return $this->pluginManager->getPlugin($name);
    }
    
    /**
     * Install plugin
     */
    public function installPlugin(string $pluginName): array
    {
        try {
            // Check if plugin exists
            $plugin = $this->pluginManager->getPlugin($pluginName);
            if (!$plugin) {
                return ['success' => false, 'message' => 'Plugin not found: ' . $pluginName];
            }
            
            // Check if already installed
            if ($plugin['installed'] ?? false) {
                return ['success' => false, 'message' => 'Plugin already installed: ' . $pluginName];
            }
            
            $result = $this->pluginManager->installPlugin($pluginName);
            
            if ($result) {
                $this->logAction('Plugin installed', ['plugin' => $pluginName]);
                return ['success' => true, 'message' => 'Plugin installed successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to install plugin'];
            }
        } catch (\Exception $e) {
            $this->logger->error('Plugin installation failed', [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'Installation failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Uninstall plugin
     */
    public function uninstallPlugin(string $pluginName): array
    {
        try {
            // Check if plugin exists
            $plugin = $this->pluginManager->getPlugin($pluginName);
            if (!$plugin) {
                return ['success' => false, 'message' => 'Plugin not found: ' . $pluginName];
            }
            
            // Don't allow uninstalling admin plugin
            if ($pluginName === 'admin') {
                return ['success' => false, 'message' => 'Cannot uninstall admin plugin'];
            }
            
            // Check if installed
            if (!($plugin['installed'] ?? false)) {
                return ['success' => false, 'message' => 'Plugin not installed: ' . $pluginName];
            }
            
            $result = $this->pluginManager->uninstallPlugin($pluginName);
            
            if ($result) {
                $this->logAction('Plugin uninstalled', ['plugin' => $pluginName]);
                return ['success' => true, 'message' => 'Plugin uninstalled successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to uninstall plugin'];
            }
        } catch (\Exception $e) {
            $this->logger->error('Plugin uninstallation failed', [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'Uninstallation failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Enable plugin
     */
    public function enablePlugin(string $pluginName): array
    {
        try {
            // Check if plugin exists
            $plugin = $this->pluginManager->getPlugin($pluginName);
            if (!$plugin) {
                return ['success' => false, 'message' => 'Plugin not found: ' . $pluginName];
            }
            
            // Check if installed
            if (!($plugin['installed'] ?? false)) {
                return ['success' => false, 'message' => 'Plugin not installed: ' . $pluginName];
            }
            
            // Check if already enabled
            if ($plugin['enabled'] ?? false) {
                return ['success' => false, 'message' => 'Plugin already enabled: ' . $pluginName];
            }
            
            $result = $this->pluginManager->enablePlugin($pluginName);
            
            if ($result) {
                $this->logAction('Plugin enabled', ['plugin' => $pluginName]);
                return ['success' => true, 'message' => 'Plugin enabled successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to enable plugin'];
            }
        } catch (\Exception $e) {
            $this->logger->error('Plugin enable failed', [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'Enable failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Disable plugin
     */
    public function disablePlugin(string $pluginName): array
    {
        try {
            // Check if plugin exists
            $plugin = $this->pluginManager->getPlugin($pluginName);
            if (!$plugin) {
                return ['success' => false, 'message' => 'Plugin not found: ' . $pluginName];
            }
            
            // Don't allow disabling admin plugin
            if ($pluginName === 'admin') {
                return ['success' => false, 'message' => 'Cannot disable admin plugin'];
            }
            
            // Check if installed
            if (!($plugin['installed'] ?? false)) {
                return ['success' => false, 'message' => 'Plugin not installed: ' . $pluginName];
            }
            
            // Check if already disabled
            if (!($plugin['enabled'] ?? false)) {
                return ['success' => false, 'message' => 'Plugin already disabled: ' . $pluginName];
            }
            
            $result = $this->pluginManager->disablePlugin($pluginName);
            
            if ($result) {
                $this->logAction('Plugin disabled', ['plugin' => $pluginName]);
                return ['success' => true, 'message' => 'Plugin disabled successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to disable plugin'];
            }
        } catch (\Exception $e) {
            $this->logger->error('Plugin disable failed', [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'Disable failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get plugin statistics
     */
    public function getPluginStats(): array
    {
        $plugins = $this->pluginManager->getPlugins();
        $enabled = 0;
        $disabled = 0;
        
        foreach ($plugins as $plugin) {
            if ($plugin['enabled'] ?? false) {
                $enabled++;
            } else {
                $disabled++;
            }
        }
        
        return [
            'total' => count($plugins),
            'enabled' => $enabled,
            'disabled' => $disabled
        ];
    }
    
    /**
     * Get the PluginManager instance
     */
    public function getPluginManager(): PluginManager
    {
        return $this->pluginManager;
    }
}
