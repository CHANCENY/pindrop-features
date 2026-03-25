<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\admin\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\FileSystem\FileSystemService;
use Simp\Pindrop\Modules\admin\src\Services\AdminService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Plugin Controller
 * 
 * Handles plugin management routes and operations.
 */
class PluginController extends ControllerBase
{
    private AdminService $adminService;
    
    public function __construct(AdminService $adminService)
    {
        $this->adminService = $adminService;
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new self($container->get('admin.service'));
    }

    /**
     * List all plugins
     */
    public function index(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig('admin/plugins.twig', [
            'page_title' => 'Plugins',
            'plugins' => $this->adminService->getPlugins()
        ]);
    }
    
    /**
     * Install a plugin
     */
    public function install(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $pluginName = $request->query->get('plugin');
            
            if ($pluginName) {
                $result = $this->adminService->installPlugin($pluginName);
                
                return $this->json([
                    'success' => $result['success'],
                    'message' => $result['message']
                ], $result['success'] ? 200 : 400);
            }
        }

        return $this->renderTwig('admin/plugins/install.twig', [
            'page_title' => 'Install Plugin',
            'available_plugins' => $this->getAvailablePlugins()
        ]);
    }
    
    /**
     * Get available plugins for installation
     */
    private function getAvailablePlugins(): array
    {
        $allPlugins = $this->adminService->getPlugins();
        $availablePlugins = [];
        
        foreach ($allPlugins as $plugin) {
            // Only show plugins that are not installed
            if (!($plugin['installed'] ?? false)) {
                $availablePlugins[] = $plugin;
            }
        }
        
        return $availablePlugins;
    }
    
    /**
     * Uninstall a plugin
     */
    public function uninstall(Request $request, string $route_name,array $options): Response
    {
        $plugin = $request->query->get('plugin');
        if (!$plugin) {
            return $this->json([
                'success' => false,
                'message' => 'Plugin name required'
            ], 400);
        }
        
        $result = $this->adminService->uninstallPlugin($plugin);
        
        return $this->json([
            'success' => $result['success'],
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }
    
    /**
     * Enable a plugin
     */
    public function enable(Request $request, string $route_name,array $options): Response
    {
        $plugin = $request->query->get('plugin');
        if (!$plugin) {
            return $this->json([
                'success' => false,
                'message' => 'Plugin name required'
            ], 400);
        }
        
        $result = $this->adminService->enablePlugin($plugin);
        
        return $this->json([
            'success' => $result['success'],
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }
    
    /**
     * Disable a plugin
     */
    public function disable(Request $request, string $route_name,array $options): Response
    {
        $plugin = $request->query->get('plugin');
        if (!$plugin) {
            return $this->json([
                'success' => false,
                'message' => 'Plugin name required'
            ], 400);
        }
        
        $result = $this->adminService->disablePlugin($plugin);
        
        return $this->json([
            'success' => $result['success'],
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }
    
    /**
     * Configure a plugin
     */
    public function config(Request $request, string $route_name,array $options): Response
    {
        $plugin = $request->query->get('plugin');
        if (!$plugin) {
            return $this->json([
                'success' => false,
                'message' => 'Plugin name required'
            ], 400);
        }
        
        if ($request->isMethod('POST')) {
            $config = $request->get('config', []);
            $result = $this->savePluginConfig($plugin, $config);
            
            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'message' => 'Plugin configuration saved'
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
        }
        
        return $this->renderTwig('admin/plugins/config.twig', [
            'page_title' => 'Configure Plugin',
            'plugin' => $plugin,
            'config' => $this->getPluginConfig($plugin)
        ]);
    }
    
    /**
     * Get plugin configuration
     */
    private function getPluginConfig(string $pluginName): array
    {
        return $this->adminService->getPlugin($pluginName)['configuration'] ?? [];
    }
    
    /**
     * Save plugin configuration
     */
    private function savePluginConfig(string $pluginName, array $config): array
    {
        // Placeholder - would save actual plugin configuration
        return [
            'success' => true,
            'message' => 'Configuration saved successfully'
        ];
    }
    
    /**
     * Scan for new plugins
     */
    public function scan(Request $request, string $route_name,array $options): Response
    {
        try {
            // Use PluginManager to re-discover plugins
            $newPlugins = $this->adminService->getPluginManager()->rediscoverPlugins();
            
            $installedPlugins = [];
            $failedPlugins = [];
            
            // Install newly discovered plugins
            foreach ($newPlugins as $pluginId) {
                try {
                    $result = $this->adminService->getPluginManager()->installPlugin($pluginId);
                    if ($result) {
                        $installedPlugins[] = $pluginId;
                    } else {
                        $failedPlugins[] = $pluginId;
                    }
                } catch (\Exception $e) {
                    $failedPlugins[] = $pluginId;
                }
            }
            
            return $this->json([
                'success' => true,
                'message' => 'Scan completed successfully',
                'found' => count($newPlugins),
                'installed' => count($installedPlugins),
                'failed' => count($failedPlugins),
                'plugins' => $newPlugins,
                'installed_plugins' => $installedPlugins,
                'failed_plugins' => $failedPlugins
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Scan failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Upload and install a plugin
     */
    public function upload(Request $request, string $route_name,array $options): Response
    {
        try {
            if ($request->isMethod('POST')) {

                /**@var UploadedFile  $uploadedFile **/
                $uploadedFile = $request->files->get('plugin_file');
                
                if (!$uploadedFile) {
                    return $this->json([
                        'success' => false,
                        'message' => 'No file uploaded'
                    ], 400);
                }
                
                // Validate file type
                $allowedExtensions = ['zip', 'tar', 'gz'];
                $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Invalid file type. Only ZIP, TAR, and GZ files are allowed.'
                    ], 400);
                }
                
                // Validate file size (max 10MB)
                $maxFileSize = 10 * 1024 * 1024; // 10MB
                if ($uploadedFile->getSize() > $maxFileSize) {
                    return $this->json([
                        'success' => false,
                        'message' => 'File too large. Maximum size is 10MB.'
                    ], 400);
                }
                
                // Create temporary directory for extraction
                $tempDir = "public://plugins/".uniqid();
                mkdir($tempDir, 0755, true);
                
                try {
                    // Move uploaded file to temp directory
                    $archivePath = "public://plugins/". $uploadedFile->getClientOriginalName();
                    $uploadedFile->move("public://plugins", $uploadedFile->getClientOriginalName());

                    // Extract archive
                    $pluginDir = $this->extractPluginArchive($archivePath, $tempDir);

                    if (!$pluginDir) {
                        throw new \Exception('Failed to extract plugin archive');
                    }
                    
                    // Validate plugin structure
                    $pluginInfo = $this->validatePluginStructure($pluginDir);
                    if (!$pluginInfo) {
                        throw new \Exception('Invalid plugin structure or missing info.yml');
                    }
                    
                    // Get plugin root directory
                    $pluginRoot = $this->adminService->getPluginManager()->getPluginRoot();
                    $list = explode('/', $pluginDir);
                    $name = end($list);
                    $targetDir = $pluginRoot . '/' . $name;
                    
                    // Check if plugin already exists
                    if (is_dir($targetDir)) {
                        throw new \Exception('Plugin already exists: ' . $name);
                    }
                    
                    // Move plugin to plugins directory
                    if (!rename($pluginDir, $targetDir)) {
                        throw new \Exception('Failed to move plugin to plugins directory');
                    }
                    
                    // Re-discover and install the plugin
                    $newPlugins = $this->adminService->getPluginManager()->rediscoverPlugins();
                    
                    if (in_array($name, $newPlugins)) {
                        return $this->json([
                            'success' => true,
                            'message' => 'Plugin uploaded and installed successfully: ' . $name,
                            'plugin' => $pluginInfo
                        ]);
                    } else {
                        return $this->json([
                            'success' => false,
                            'message' => 'Plugin uploaded but not recognized by system'
                        ], 500);
                    }
                    
                } finally {
                    // Clean up temporary directory
                    $this->removeDirectory($tempDir);
                }
            }
            
            return $this->json([
                'success' => false,
                'message' => 'Invalid request method'
            ], 405);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Extract plugin archive
     */
    private function extractPluginArchive(string $archivePath, string $extractTo): ?string
    {
        $extension = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));
        /**@var FileSystemService  $fileSystem **/
        $fileSystem = getAppContainer()->get('filesystem');
        $archivePath = $fileSystem->getFileSystem()->resolvedRealPath($archivePath);
        $extractTo   = $fileSystem->getFileSystem()->resolvedRealPath($extractTo);

        try {
            if ($extension === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($archivePath) === TRUE) {
                    $zip->extractTo($extractTo);
                    $zip->close();

                    $list = array_diff(scandir($extractTo), array('..', '.'));
                    // Find the extracted plugin directory
                    foreach ($list as $item) {
                        $fullPath = $extractTo . DIRECTORY_SEPARATOR . $item;
                        if (is_dir($fullPath)) {
                            $info_file = $fullPath . DIRECTORY_SEPARATOR ."info.yml";

                            if (is_file($info_file)) {
                                return $fullPath;
                            }
                        }
                    }
                }
                else{
                    throw new \Exception('Invalid zip file');
                }
            } elseif (in_array($extension, ['tar', 'gz'])) {
                // Handle tar/gz archives
                $phar = new \PharData($archivePath);
                $phar->extractTo($extractTo);

                $list = array_diff(scandir($extractTo), array('..', '.'));
                // Find the extracted plugin directory
                foreach ($list as $item) {
                    $fullPath = $extractTo . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($fullPath)) {
                        $info_file = $fullPath . DIRECTORY_SEPARATOR ."info.yml";

                        if (is_file($info_file)) {
                            return $fullPath;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            return null;
        }
        
        return null;
    }
    
    /**
     * Validate plugin structure and get info
     */
    private function validatePluginStructure(string $pluginDir): ?array
    {

        $infoFile = $pluginDir . '/info.yml';
        
        if (!file_exists($infoFile)) {
            return null;
        }

        try {
            $info = \Symfony\Component\Yaml\Yaml::parseFile($infoFile);
            
            // Validate required fields
            if (!isset($info['name']) || !isset($info['version'])) {
                return null;
            }
            
            return $info;
        } catch (\Exception $e) {

            return null;
        }
    }
    
    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }
    }
}
