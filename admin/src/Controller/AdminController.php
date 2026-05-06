<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\admin\src\Controller;

use DateInterval;
use DateTime;
use DI\DependencyException;
use DI\NotFoundException;
use Exception;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Shuchkin\SimpleCSV;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\File\File;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\UserVerification;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\admin\src\Address\AddressFormatter;
use Simp\Pindrop\Routing\RouteManager;
use Simp\Pindrop\Routing\Url;
use Simp\Pindrop\Settings\SettingsInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use ZipArchive;
use CommerceGuys\Addressing\Country\CountryRepository;

/**
 * Admin Controller
 * 
 * Handles admin dashboard and management routes.
 */
class AdminController extends ControllerBase
{
    private DatabaseService $database;
    
    public function __construct()
    {
        $this->database = getAppContainer()->get('database');
        parent::__construct();
    }

    public static function create(ContainerInterface $container): static
    {
        return new self($container->get('database'));
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function home(Request $request, string $route_name, array $options): Response
    {
        // This is the public home page - accessible to anonymous users only
        // Authenticated users will be redirected by middleware
        $homeRoute = \getAppContainer()->get('site.settings')->getSetting('site.home.route')?->get('site_home') ?? "";

        /**@var RouteManager $routeProvider**/
        $homeRouteArray = RouteManager::getRoute($homeRoute);
        if (!empty($homeRouteArray)) {
            $controller = $homeRouteArray['controller'];
            $method = $homeRouteArray['options']['controller_method'] ?? null;

            if (!empty($method) && !empty($controller)) {
                return call_user_func_array([$controller, $method], [$request, $route_name, $options]);
            }
        }
        
        return $this->renderTwig('admin/admin/home.twig', [
            'page_title' => 'Welcome',
            'is_public_page' => true
        ]);
    }
    
    /**
     * Admin dashboard
     */
    public function dashboard(Request $request, string $route_name, array $options): Response
    {
        return $this->renderTwig('admin/dashboard.twig', [
            'page_title' => 'Admin Dashboard',
            'user' => $this->getCurrentUser(),
            'stats' => $this->getDashboardStats()
        ]);
    }
    
    /**
     * Admin settings
     * @throws DatabaseException
     */
    public function settings(Request $request, string $route_name, array $options): Response
    {
        /**@var PluginManager $pluginManager**/
        $pluginManager = \getAppContainer()->get('plugin.manager');
        $settingsHandlers = $pluginManager->getPluginsYamlContent('settings.config');
        /**@var Settings $settings **/
        $settings = \getAppContainer()->get('site.settings');

        $handlersValidated = [];
        foreach ($settingsHandlers as $handler) {
            foreach ($handler as $handlerName => $handlerConfig) {
                if (isset($handlerConfig['class']) && isset($handlerConfig['name'])) {
                    $handlerClass = $handlerConfig['class'];
                    $handlerClassObject = new $handlerClass();
                    if ($handlerClassObject instanceof SettingsInterface) {
                        $handlersValidated[] = [
                            'name' => $handlerConfig['name'],
                            'key' => $handlerName,
                            'object' => $handlerClassObject,
                            'setting' => $settings->getSetting($handlerClassObject->settingKey()),
                        ];
                    }
                }
            }
        }

        $site_home = $settings->getSetting('site.home.route')?->get('actual') ?? "";

        if ($request->isMethod('POST')) {
            $site_home = $request->request->get('site_home');
            $site_home = substr($site_home, strrpos($site_home, '(') + 1, strlen($site_home));
            $site_home = trim($site_home, ')');

            $savable = [
                'site.home.route' => ['site_home'=> $site_home, 'actual' => $request->request->get('site_home')],
            ];

            foreach ($handlersValidated as $handler) {
                if (!empty($handler['object']) && $handler['object'] instanceof SettingsInterface) {
                    $savable[$handler['object']->settingKey()] = $handler['object']->savableValues($request);
                }
            }

            foreach ($savable as $key=>$value) {
                $settings->createSetting($key, $value);
            }
            Message::info("Settings saved");
            return $this->redirect(Url::routeByName('admin.settings'));
        }

        return $this->renderTwig('admin/settings.twig', [
            'page_title' => 'Admin Settings',
            'settings' => $this->getAdminSettings(),
            'site_home' => $site_home,
            'handlersValidateds' => $handlersValidated,
        ]);
    }

    /**
     * Users management
     */
    public function users(Request $request, string $route_name, array $options): Response
    {
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 20);
        
        $pagination = User::loadWithPagination($page, $limit, $this->database);
        
        return $this->renderTwig('admin/users.twig', [
            'page_title' => 'Users Management',
            'users' => $pagination['users'],
            'pagination' => [
                'current_page' => $pagination['page'],
                'total_pages' => $pagination['total_pages'],
                'total_items' => $pagination['total'],
                'per_page' => $pagination['limit'],
                'has_previous' => $pagination['page'] > 1,
                'has_next' => $pagination['page'] < $pagination['total_pages'],
                'previous_page' => $pagination['page'] - 1,
                'next_page' => $pagination['page'] + 1
            ]
        ]);
    }
    
    /**
     * Create user form
     */
    public function createUser(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            // Handle form submission
            $data = $request->request->all();
            
            try {
                // Validate required fields
                if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
                    throw new InvalidArgumentException('Username, email, and password are required');
                }
                
                // Check if user already exists
                $existingUser = User::loadByUsername($data['username'], $this->database);
                if ($existingUser) {
                    throw new InvalidArgumentException('Username already exists');
                }
                
                $existingEmail = User::loadByEmail($data['email'], $this->database);
                if ($existingEmail) {
                    throw new InvalidArgumentException('Email already exists');
                }
                
                // Create new user
                $user = new User([], $this->database);
                $user->setUsername($data['username']);
                $user->setEmail($data['email']);
                $user->setPassword($data['password']);
                $user->setRole($data['role'] ?? 'user');
                $user->setStatus($data['status'] ?? 'active');
                $user->setCreatedAt(new DateTime());
                
                if ($user->save()) {
                    return $this->redirect('/admin/users');
                } else {
                    throw new RuntimeException('Failed to create user');
                }
                
            } catch (Exception $e) {
                return $this->renderTwig('admin/users/create.twig', [
                    'page_title' => 'Create User',
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);
            }
        }
        
        return $this->renderTwig('admin/users/create.twig', [
            'page_title' => 'Create User',
            'roles' => [
                'super_admin' => 'Super Administrator',
                'admin' => 'Administrator',
                'moderator' => 'Moderator',
                'user' => 'User'
            ],
            'statuses' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'pending' => 'Pending',
                'suspended' => 'Suspended'
            ]
        ]);
    }
    
    /**
     * Edit user form
     */
    public function editUser(Request $request, string $route_name, array $options): Response
    {
        $user_id = $request->query->get('user_id');
        $user = User::loadById($user_id, $this->database);
        
        if (!$user) {
            return $this->redirect('/admin/users');
        }
        
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            
            try {
                // Update user fields
                if (!empty($data['username'])) {
                    $user->setUsername($data['username']);
                }
                
                if (!empty($data['email'])) {
                    $user->setEmail($data['email']);
                }
                
                if (!empty($data['password'])) {
                    $user->setPassword($data['password']);
                }
                
                if (isset($data['role'])) {
                    $user->setRole($data['role']);
                }
                
                if (isset($data['status'])) {
                    $user->setStatus($data['status']);
                }
                
                if ($user->save()) {
                    return $this->redirect('/admin/users');
                } else {
                    throw new RuntimeException('Failed to update user');
                }
                
            } catch (Exception $e) {
                return $this->renderTwig('admin/users/edit.twig', [
                    'page_title' => 'Edit User',
                    'user' => $user,
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);
            }
        }

        return $this->renderTwig('admin/users/edit.twig', [
            'page_title' => 'Edit User',
            'user' => $user,
            'roles' => [
                'super_admin' => 'Super Administrator',
                'admin' => 'Administrator',
                'moderator' => 'Moderator',
                'user' => 'User'
            ],
            'statuses' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'pending' => 'Pending',
                'suspended' => 'Suspended'
            ]
        ]);
    }
    
    /**
     * View user details
     */
    public function viewUser(Request $request, string $route_name, array $options): Response
    {
        $user_id = $request->query->get('user_id');
        $user = User::loadById($user_id, $this->database);
        
        if (!$user) {
            return $this->redirect('/admin/users');
        }
        
        return $this->renderTwig('admin/users/view.twig', [
            'page_title' => 'User Details',
            'user' => $user
        ]);
    }
    
    /**
     * Delete user
     */
    public function deleteUser(Request $request, string $route_name, array $options): Response
    {
        $user_id = $request->query->get('user_id');
        $user = User::loadById($user_id, $this->database);
        
        if (!$user) {
            return $this->redirect('/admin/users');
        }
        
        if ($request->isMethod('POST')) {
            try {
                if ($user->delete()) {
                    return $this->redirect('/admin/users');
                } else {
                    throw new RuntimeException('Failed to delete user');
                }
            } catch (Exception $e) {
                return $this->renderTwig('admin/users/delete.twig', [
                    'page_title' => 'Delete User',
                    'user' => $user,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $this->renderTwig('admin/users/delete.twig', [
            'page_title' => 'Delete User',
            'user' => $user
        ]);
    }
    
    /**
     * Toggle user status
     */
    public function toggleUserStatus(Request $request, string $route_name, array $options): Response
    {
        $user_id = $request->query->get('user_id');
        $user = User::loadById($user_id, $this->database);
        
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'User not found']);
        }
        
        try {
            // Toggle between active and inactive
            $newStatus = $user->getStatus() === User::STATUS_ACTIVE ? User::STATUS_INACTIVE : User::STATUS_ACTIVE;
            $user->setStatus($newStatus);
            
            if ($user->save()) {
                return $this->json([
                    'success' => true,
                    'message' => 'User status updated successfully',
                    'new_status' => $newStatus
                ]);
            } else {
                throw new RuntimeException('Failed to update user status');
            }
            
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function importTemplate(Request $request, string $route_name, array $options): Response
    {
        try {
            // Create temporary ZIP file
            $zipFileName = 'user_import_templates_' . date('Y-m-d_H-i-s') . '.zip';
            $tempZipPath = sys_get_temp_dir() . 'AdminController.php/' . $zipFileName;
            
            // Initialize ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
                throw new Exception("Cannot create ZIP file");
            }
            
            // Add template files to ZIP
            $csvTemplate = __DIR__ . "/../../templates/cs_template.csv";
            $xlsxTemplate = __DIR__ . "/../../templates/xlsx_template2.xlsx";
            
            if (file_exists($csvTemplate)) {
                $zip->addFile($csvTemplate, 'user_import_template.csv');
            }
            
            if (file_exists($xlsxTemplate)) {
                $zip->addFile($xlsxTemplate, 'user_import_template.xlsx');
            }
            
            // Add README file with instructions
            $readmeContent = $this->generateImportReadme();
            $zip->addFromString('README.txt', $readmeContent);
            
            // Close ZIP archive
            $zip->close();
            
            // Read ZIP file content
            $zipContent = file_get_contents($tempZipPath);
            
            // Clean up temporary file
            unlink($tempZipPath);
            
            // Return ZIP file as download
            return new Response(
                $zipContent,
                200,
                [
                    'Content-Type' => 'application/zip',
                    'Content-Disposition' => 'attachment; filename="' . $zipFileName . '"',
                    'Content-Length' => strlen($zipContent)
                ]
            );
            
        } catch (Exception $e) {
            getAppContainer()->get('logger')->error('Failed to create import template ZIP', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->json([
                'success' => false,
                'message' => 'Failed to generate template file: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Generate README content for import templates
     */
    private function generateImportReadme(): string
    {
        return "User Import Templates
====================

This ZIP file contains templates for importing users into the system.

Files Included:
--------------
- user_import_template.csv - CSV template for user import
- user_import_template.xlsx - Excel template for user import
- README.txt - This instruction file

Required Fields:
---------------
- username: Unique username for the user (required)
- email: Valid email address (required)

Optional Fields:
---------------
- first_name: User's first name
- last_name: User's last name
- role: User role (user, moderator, admin, super_admin)
- status: Account status (pending, active, inactive)

Role Values:
------------
- user: Regular user with basic access
- moderator: Can manage content and moderate users
- admin: Full administrative access
- super_admin: System administrator with all permissions

Status Values:
--------------
- pending: Account created but not yet activated
- active: Account is active and can login
- inactive: Account is disabled and cannot login

Import Instructions:
-------------------
1. Open the template file in your preferred spreadsheet application
2. Replace the sample data with your user data
3. Ensure all required fields (username, email) are filled
4. Use valid values for role and status fields
5. Save the file in CSV or Excel format
6. Upload the file using the Import Users feature

Important Notes:
----------------
- Usernames must be unique
- Email addresses must be valid and unique
- Duplicate users (by username or email) will be skipped if 'Skip duplicates' option is enabled
- New users will receive welcome emails if 'Send welcome email' option is enabled
- Password reset will be required on first login if 'Require password reset' option is enabled

For support, contact your system administrator.

Generated: " . date('Y-m-d H:i:s') . "
";
    }

    /**
     * Preview user import data
     */
    public function importPreview(Request $request, string $route_name, array $options): Response
    {
        try {
            $uploadedFile = $request->files->get('import_file');
            if (!$uploadedFile) {
                return $this->json(['success' => false, 'message' => 'No file uploaded']);
            }

            $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
            if (!in_array($fileExtension, ['csv', 'xlsx', 'xls'])) {
                return $this->json(['success' => false, 'message' => 'Invalid file format. Please upload CSV or Excel file.']);
            }

            // Parse file based on extension
            $data = $this->parseImportFile($uploadedFile->getPathname(), $fileExtension);

            if (empty($data)) {
                return $this->json(['success' => false, 'message' => 'No data found in file or file is empty']);
            }

            // Validate and analyze data
            $preview = $this->analyzeImportData($data);
            
            return $this->json([
                'success' => true,
                'preview' => $preview
            ]);

        } catch (Exception $e) {
            getAppContainer()->get('logger')->error('Import preview failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Failed to preview file: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Import users from file
     */
    public function import(Request $request, string $route_name, array $options): Response
    {
        try {
            $uploadedFile = $request->files->get('import_file');
            if (!$uploadedFile) {
                return $this->json(['success' => false, 'message' => 'No file uploaded']);
            }

            $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
            if (!in_array($fileExtension, ['csv', 'xlsx', 'xls'])) {
                return $this->json(['success' => false, 'message' => 'Invalid file format. Please upload CSV or Excel file.']);
            }

            // Get import options
            $sendWelcomeEmail = $request->request->get('send_welcome_email') === '1';
            $requirePasswordReset = $request->request->get('require_password_reset') === '1';
            $skipDuplicates = $request->request->get('skip_duplicates') === '1';

            // Parse file
            $data = $this->parseImportFile($uploadedFile->getPathname(), $fileExtension);
            
            if (empty($data)) {
                return $this->json(['success' => false, 'message' => 'No data found in file or file is empty']);
            }

            // Process import
            $results = $this->processImportData($data, $sendWelcomeEmail, $requirePasswordReset, $skipDuplicates);
            
            return $this->json([
                'success' => true,
                'results' => $results
            ]);

        } catch (Exception $e) {
            getAppContainer()->get('logger')->error('Import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Parse import file based on extension
     */
    private function parseImportFile(string $filePath, string $extension): array
    {
        $data = [];
        try {
            switch ($extension) {
                case 'csv':
                    if (class_exists(SimpleCSV::class)) {
                        $data = SimpleCSV::import($filePath);
                    } else {
                        // Fallback to native CSV parsing
                        $data = $this->parseNativeCSV($filePath);
                    }
                    break;

                case 'xlsx':
                    $xlsx = SimpleXLSX::parse($filePath);
                    if ($xlsx) {
                        $data = $xlsx->rows();
                        // Remove header row if present
                        if (!empty($data) && $this->isHeaderRow($data[0])) {
                            array_shift($data);
                        }
                    }
                    break;

                case 'xls':
                    $xls = SimpleXLS::parse($filePath);
                    if ($xls) {
                        $data = $xls->rows();
                        // Remove header row if present
                        if (!empty($data) && $this->isHeaderRow($data[0])) {
                            array_shift($data);
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            dump($e);
            throw new Exception("Failed to parse {$extension} file: " . $e->getMessage());
        }

        return $data;
    }

    /**
     * Parse CSV file using native PHP (fallback)
     */
    private function parseNativeCSV(string $filePath): array
    {
        $data = [];
        $header = null;
        
        if (($handle = fopen($filePath, 'r')) !== FALSE) {
            $rowIndex = 0;
            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                if ($rowIndex === 0 && $this->isHeaderRow($row)) {
                    $header = $row;
                } else {
                    if ($header) {
                        $data[] = array_combine($header, $row);
                    } else {
                        $data[] = $row;
                    }
                }
                $rowIndex++;
            }
            fclose($handle);
        }
        
        return $data;
    }

    /**
     * Check if row is a header row
     */
    private function isHeaderRow(array $row): bool
    {
        $expectedHeaders = ['username', 'email', 'first_name', 'last_name', 'role', 'status'];
        $rowLower = array_map('strtolower', $row);
        
        return count(array_intersect($expectedHeaders, $rowLower)) >= 2; // At least 2 expected headers
    }

    /**
     * Analyze import data for preview
     */
    private function analyzeImportData(array $data): array
    {
        $totalRows = count($data);
        $validUsers = 0;
        $duplicates = 0;
        $errors = [];
        $sampleData = [];

        foreach ($data as $index => $row) {
            $rowNumber = $index + 1;
            
            // Convert associative array to indexed if needed
            if (isset($row['username'])) {
                $rowData = [
                    'username' => $row['username'] ?? '',
                    'email' => $row['email'] ?? '',
                    'first_name' => $row['first_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'role' => $row['role'] ?? 'user',
                    'status' => $row['status'] ?? 'pending'
                ];
            } else {
                // Indexed array - map by position
                $rowData = [
                    'username' => $row[0] ?? '',
                    'email' => $row[1] ?? '',
                    'first_name' => $row[2] ?? '',
                    'last_name' => $row[3] ?? '',
                    'role' => $row[4] ?? 'user',
                    'status' => $row[5] ?? 'pending'
                ];
            }

            // Validate required fields
            $validation = $this->validateUserData($rowData, $rowNumber);
            
            if ($validation['valid']) {
                $validUsers++;
                
                // Check for duplicates
                if ($this->isDuplicateUser($rowData['username'], $rowData['email'])) {
                    $duplicates++;
                }
                
                // Add to sample data (first 5 valid users)
                if (count($sampleData) < 5) {
                    $sampleData[] = $rowData;
                }
            } else {
                $errors = array_merge($errors, $validation['errors']);
            }
        }

        return [
            'total_rows' => $totalRows,
            'valid_users' => $validUsers,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'sample_data' => $sampleData
        ];
    }

    /**
     * Process import data and create users
     */
    private function processImportData(array $data, bool $sendWelcomeEmail, bool $requirePasswordReset, bool $skipDuplicates): array
    {
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($data as $index => $row) {
            $rowNumber = $index + 1;
            
            // Convert associative array to indexed if needed
            if (isset($row['username'])) {
                $userData = [
                    'username' => trim($row['username'] ?? ''),
                    'email' => trim($row['email'] ?? ''),
                    'first_name' => trim($row['first_name'] ?? ''),
                    'last_name' => trim($row['last_name'] ?? ''),
                    'role' => trim($row['role'] ?? 'user'),
                    'status' => trim($row['status'] ?? 'pending')
                ];
            } else {
                $userData = [
                    'username' => trim($row[0] ?? ''),
                    'email' => trim($row[1] ?? ''),
                    'first_name' => trim($row[2] ?? ''),
                    'last_name' => trim($row[3] ?? ''),
                    'role' => trim($row[4] ?? 'user'),
                    'status' => trim($row[5] ?? 'pending')
                ];
            }

            // Validate data
            $validation = $this->validateUserData($userData, $rowNumber);
            if (!$validation['valid']) {
                $failed++;
                $errors = array_merge($errors, $validation['errors']);
                continue;
            }

            // Check for duplicates
            if ($this->isDuplicateUser($userData['username'], $userData['email'])) {
                if ($skipDuplicates) {
                    $skipped++;
                    continue;
                } else {
                    $failed++;
                    $errors[] = "Row {$rowNumber}: User with username '{$userData['username']}' or email '{$userData['email']}' already exists";
                    continue;
                }
            }

            // Create user
            try {
                $this->createUserFromImport($userData, $sendWelcomeEmail, $requirePasswordReset);
                $imported++;
            } catch (Exception $e) {
                $failed++;
                $errors[] = "Row {$rowNumber}: Failed to create user - " . $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors
        ];
    }

    /**
     * Validate user data
     */
    private function validateUserData(array $userData, int $rowNumber): array
    {
        $errors = [];
        $valid = true;

        // Validate required fields
        if (empty($userData['username'])) {
            $errors[] = "Row {$rowNumber}: Username is required";
            $valid = false;
        }

        if (empty($userData['email'])) {
            $errors[] = "Row {$rowNumber}: Email is required";
            $valid = false;
        } elseif (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Row {$rowNumber}: Invalid email format";
            $valid = false;
        }

        // Validate role
        $validRoles = ['user', 'moderator', 'admin', 'super_admin'];
        if (!empty($userData['role']) && !in_array($userData['role'], $validRoles)) {
            $errors[] = "Row {$rowNumber}: Invalid role '{$userData['role']}'. Valid roles: " . implode(', ', $validRoles);
            $valid = false;
        }

        // Validate status
        $validStatuses = ['pending', 'active', 'inactive'];
        if (!empty($userData['status']) && !in_array($userData['status'], $validStatuses)) {
            $errors[] = "Row {$rowNumber}: Invalid status '{$userData['status']}'. Valid statuses: " . implode(', ', $validStatuses);
            $valid = false;
        }

        return [
            'valid' => $valid,
            'errors' => $errors
        ];
    }

    /**
     * Check if user is duplicate
     */
    private function isDuplicateUser(string $username, string $email): bool
    {
        try {
            // Check by username
            $existingUser = User::loadByUsername($username, $this->database);
            if ($existingUser) {
                return true;
            }

            // Check by email
            $existingEmail = User::loadByEmail($email, $this->database);
            if ($existingEmail) {
                return true;
            }

            return false;
        } catch (Exception $e) {
            return false; // Assume not duplicate on error
        }
    }

    /**
     * Create user from import data
     */
    private function createUserFromImport(array $userData, bool $sendWelcomeEmail, bool $requirePasswordReset): void
    {
        // Generate random password
        $password = bin2hex(random_bytes(8));
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Create new user instance
        $user = new User([], $this->database, getAppContainer()->get('logger'));
        
        // Set user data
        $user->setUsername($userData['username']);
        $user->setEmail($userData['email']);
        $user->setPasswordHash($hashedPassword);
        $user->setFirstName($userData['first_name']);
        $user->setLastName($userData['last_name']);
        $user->setRole($userData['role']);
        $user->setStatus($userData['status']);
        
        // Save user to database
        if (!$user->save()) {
            throw new Exception('Failed to save user to database');
        }

        if ($requirePasswordReset) {
            // Create password reset token
            UserVerification::createPasswordResetToken(
                $this->database,
                getAppContainer()->get('logger'),
                $user->getId(),
                $user->getEmail(),
                '127.0.0.1', // Import IP
                'Import System'
            );
        }

        if ($sendWelcomeEmail) {
            // TODO: Implement welcome email sending
            getAppContainer()->get('logger')->info('Welcome email would be sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail()
            ]);
        }

        getAppContainer()->get('logger')->info('User created from import', [
            'user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()
        ]);
    }

    /**
     * Bulk user actions
     */
    public function bulkUserAction(Request $request, string $route_name, array $options): Response
    {
        $action = $request->request->get('action');
        $userIds = $request->request->get('user_ids', []);
        
        if (empty($action) || empty($userIds)) {
            return $this->json(['success' => false, 'message' => 'Invalid request']);
        }
        
        try {
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            foreach ($userIds as $userId) {
                $user = User::loadById((int) $userId, $this->database);
                
                if (!$user) {
                    $errorCount++;
                    $errors[] = "User ID {$userId} not found";
                    continue;
                }
                
                switch ($action) {
                    case 'activate':
                        $user->setStatus(User::STATUS_ACTIVE);
                        break;
                    case 'deactivate':
                        $user->setStatus(User::STATUS_INACTIVE);
                        break;
                    case 'delete':
                        if (!$user->delete()) {
                            throw new RuntimeException('Failed to delete user');
                        }
                        break;
                    default:
                        throw new InvalidArgumentException('Invalid action');
                }
                
                if ($action !== 'delete' && !$user->save()) {
                    $errorCount++;
                    $errors[] = "Failed to update user ID {$userId}";
                } else {
                    $successCount++;
                }
            }
            
            return $this->json([
                'success' => true,
                'message' => "Action completed: {$successCount} successful, {$errorCount} failed",
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    /**
     * Content management
     */
    public function content(Request $request, string $route_name, array $options): Response
    {
        $page = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 20);
        $type = $request->query->get('type', '');
        $status = $request->query->get('status', '');
        $search = $request->query->get('search', '');
        
        return $this->renderTwig('admin/content.twig', [
            'page_title' => 'Content Management',
            'content' => $this->getContentList($page, $limit, $type, $status, $search),
            'pagination' => $this->getContentPagination($page, $limit, $type, $status, $search),
            'filters' => [
                'type' => $type,
                'status' => $status,
                'search' => $search,
                'limit' => $limit
            ],
            'content_types' => $this->getContentTypes()
        ]);
    }

    /**
     * Content creation page - show available content types
     */
    public function createContent(Request $request, string $route_name, array $options): Response
    {
        try {
            $container = getAppContainer();
            $repository = $container->get('content.repository');
            $contentTypes = $this->getContentTypes();
            
            return $this->renderTwig('admin/content/create.twig', [
                'page_title' => 'Create Content',
                'content_types' => $contentTypes
            ]);
            
        } catch (Exception $e) {
            $container->get('logger')->error('Failed to load content creation page', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->redirect('/admin/content');
        }
    }
    
    /**
     * Add content of specific type
     */
    public function addContent(Request $request, string $route_name, array $options): Response
    {
        $type = $request->query->get('type');
        
        if (empty($type)) {
            return $this->redirect('/admin/content/create');
        }
        
        try {
            $container = getAppContainer();
            $repository = $container->get('content.repository');
            
            // Validate content type
            if (!$repository->has($type)) {
                return $this->redirect('/admin/content/create');
            }
            
            $contentTypeInfo = $repository->get($type);
            $className = $contentTypeInfo['class'];

            /**@var StorageEntity $storageEntity **/
            $storageEntity = $container->get('content.factory')->storage($type);
           
            $formHtml = $storageEntity->getEntityForm();

            if ($request->isMethod('POST')) {
                try {
                    // Get form data and files
                    $formData = $request->request->all();
                    $files = $request->files->all();
                    
                    // Handle file uploads using FileSystem service
                    $fileSystem = $container->get('filesystem');
                    
                    foreach ($files as $fieldName => $file) {
                        if ($file && $file->isValid()) {
                            // Create destination URI using public:// stream wrapper
                            $extension = $file->getClientOriginalExtension();
                            $filename = uniqid() . 'Controller' . $extension;
                            $destinationUri = 'public://content/' . date('Y/m') . '/' . $filename;
                            
                            // Upload file using FileSystem service
                            $uploadResult = $fileSystem->uploadFile([
                                'name' => $file->getClientOriginalName(),
                                'tmp_name' => $file->getPathname(),
                                'size' => $file->getSize(),
                                'error' => $file->getError()
                            ], $fileSystem->resolvedRealPath($destinationUri), [
                                'unique' => true,
                                'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt']
                            ]);
                            
                            if ($uploadResult['success']) {
                                // Create File entity record
                                $fileEntity = new \Simp\Pindrop\Entity\File\File([
                                    'filename' => $uploadResult['data'][0]['name'],
                                    'uri' => $destinationUri,
                                    'filemime' => $uploadResult['data'][0]['mime_type'],
                                    'filesize' => $uploadResult['data'][0]['size'],
                                    'status' => \Simp\Pindrop\Entity\File\File::STATUS_PERMANENT,
                                    'uid' => $container->get('current_user')->getId(),
                                    'fieldname' => $fieldName,
                                    'entity_type' => $type,
                                    'entity_id' => $storageEntity->getId() ?? 0,
                                    'bundle' => $type,
                                    'langcode' => 'en'
                                ], $container->get('database'), $container->get('logger'));
                                
                                if ($fileEntity->save()) {
                                    // Store file URI in form data
                                    $formData[$fieldName] = $destinationUri;
                                } else {
                                    throw new \Exception('Failed to save file entity record');
                                }
                            } else {
                                throw new \Exception('File upload failed: ' . $uploadResult['message']);
                            }
                        }
                    }
                    
                    // Set core entity properties using proper setters
                    if (isset($formData['title'])) $storageEntity->setTitle($formData['title']);
                    if (isset($formData['slug'])) $storageEntity->setValue('slug', $formData['slug']);
                    if (isset($formData['content'])) $storageEntity->setContent($formData['content']);
                    if (isset($formData['excerpt'])) $storageEntity->setValue('excerpt', $formData['excerpt']);
                    
                    // Set publication status
                    if (isset($formData['status'])) {
                        $storageEntity->setStatus($formData['status']);
                    }
                    if (isset($formData['is_published'])) {
                        $storageEntity->setPublished((bool) $formData['is_published']);
                        if ($formData['is_published']) {
                            $storageEntity->setPublishedAt(new \DateTime());
                        }
                    }
                    
                    // Set boolean fields
                    $storageEntity->setValue('featured', isset($formData['featured']) ? (bool) $formData['featured'] : false);
                    $storageEntity->setValue('sticky', isset($formData['sticky']) ? (bool) $formData['sticky'] : false);
                    $storageEntity->setValue('allow_comments', isset($formData['allow_comments']) ? (bool) $formData['allow_comments'] : true);
                    
                    // Set metadata fields
                    $storageEntity->setValue('password', $formData['password'] ?? null);
                    $storageEntity->setValue('template', $formData['template'] ?? null);
                    $storageEntity->setValue('language', $formData['language'] ?? 'en');
                    
                    // Set SEO fields
                    $storageEntity->setValue('meta_title', $formData['meta_title'] ?? null);
                    $storageEntity->setValue('meta_description', $formData['meta_description'] ?? null);
                    $storageEntity->setValue('meta_keywords', $formData['meta_keywords'] ?? null);
                    $storageEntity->setValue('canonical_url', $formData['canonical_url'] ?? null);
                    $storageEntity->setValue('redirect_url', $formData['redirect_url'] ?? null);
                    
                    // Set author and timestamps
                    $storageEntity->setAuthorId($container->get('current_user')->getId());
                    $storageEntity->setCreatedAt(new \DateTime());
                    $storageEntity->setUpdatedAt(new \DateTime());
                    
                    // Set dynamic fields (entity-specific fields)
                    $coreFields = [
                        'entity_type', 'id', 'title', 'slug', 'content', 'excerpt',
                        'status', 'is_published', 'featured', 'sticky', 'allow_comments',
                        'password', 'template', 'language', 'meta_title', 'meta_description',
                        'meta_keywords', 'canonical_url', 'redirect_url', 'submit'
                    ];
                    
                    foreach ($formData as $key => $value) {
                        if (!in_array($key, $coreFields)) {
                            $storageEntity->setValue($key, $value);
                        }
                    }
                    
                    // Save the entity
                    if ($storageEntity->save()) {
                        // Update file entities with the new entity ID
                        foreach ($files as $fieldName => $file) {
                            if ($file && $file->isValid() && isset($formData[$fieldName])) {
                                $fileEntity = \Simp\Pindrop\Entity\File\File::loadByUri($formData[$fieldName], $container->get('database'));
                                if ($fileEntity) {
                                    $fileEntity->setEntityId($storageEntity->getId());
                                    $fileEntity->save();
                                }
                            }
                        }

                        // Redirect to edit page
                        return $this->redirect("/admin/content");
                    } else {
                        throw new \Exception('Failed to save entity');
                    }
                    
                } catch (\Exception $e) {
                    $container->get('logger')->error('Failed to save content', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'type' => $type,
                        'data' => $request->request->all()
                    ]);
                    
                    // Add error message
                    $container->get('session')->getFlashBag()->add('error', 'Failed to save ' . $type . ': ' . $e->getMessage());
                    
                    // Re-throw exception to let Whoops handle it in development
                    $environment = getenv('APP_ENV') ?: 'development';
                    if ($environment !== 'production') {
                        throw $e;
                    }
                }
            }
            
            return $this->renderTwig('admin/content/add.twig', [
                'page_title' => 'Create ' . ucfirst($type),
                'content_type' => ucfirst($type),
                'type' => $type,
                'form_html' => $formHtml,
                'description' => $contentTypeInfo['config']['description'] ?? "",
                'entity' => $storageEntity,
            ]);
            
        } catch (\Exception $e) {
            // Re-throw exception to let Whoops handle it in development
            $environment = getenv('APP_ENV') ?: 'development';
            if ($environment !== 'production') {
                throw $e;
            }
            
            // In production, show generic error and redirect
            $container = getAppContainer();
            $container->get('session')->getFlashBag()->add('error', 'Failed to load content creation page');
            return $this->redirect('/admin/content/create');
        }
    }

    public function editContent(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');

        if (empty($id)) {
            return $this->redirect('/admin/content');
        }

        try {
            $container = getAppContainer();
            $repository = $container->get('content.repository');

            /**@var ContentEntityInterface $entity **/
            $entity = $repository->find($id);

            $contentTypeInfo = $repository->get($entity->getNodeType());
            $className = $contentTypeInfo['class'];

            $formHtml = $entity->getEntityForm();

            if ($request->isMethod('POST')) {
                try {
                    // Get form data and files
                    $formData = $request->request->all();
                    $files = $request->files->all();

                    // Handle file uploads using FileSystem service
                    $fileSystem = $container->get('filesystem');

                    foreach ($files as $fieldName => $file) {
                        if ($file && $file->isValid()) {
                            // Create destination URI using public:// stream wrapper
                            $extension = $file->getClientOriginalExtension();
                            $filename = uniqid() . 'Controller' . $extension;
                            $destinationUri = 'public://content/' . date('Y/m') . '/' . $filename;

                            // Upload file using FileSystem service
                            $uploadResult = $fileSystem->uploadFile([
                                'name' => $file->getClientOriginalName(),
                                'tmp_name' => $file->getPathname(),
                                'size' => $file->getSize(),
                                'error' => $file->getError()
                            ], $fileSystem->resolvedRealPath($destinationUri), [
                                'unique' => true,
                                'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt']
                            ]);

                            if ($uploadResult['success']) {
                                // Create File entity record
                                $fileEntity = new \Simp\Pindrop\Entity\File\File([
                                    'filename' => $uploadResult['data'][0]['name'],
                                    'uri' => $destinationUri,
                                    'filemime' => $uploadResult['data'][0]['mime_type'],
                                    'filesize' => $uploadResult['data'][0]['size'],
                                    'status' => \Simp\Pindrop\Entity\File\File::STATUS_PERMANENT,
                                    'uid' => $container->get('current_user')->getId(),
                                    'fieldname' => $fieldName,
                                    'entity_type' => $entity->getNodeType(),
                                    'entity_id' => $entity->getId() ?? 0,
                                    'bundle' => $entity->getNodeType(),
                                    'langcode' => 'en'
                                ], $container->get('database'), $container->get('logger'));

                                if ($fileEntity->save()) {
                                    // Store file URI in form data
                                    $formData[$fieldName] = $destinationUri;
                                } else {
                                    throw new \Exception('Failed to save file entity record');
                                }
                            } else {
                                throw new \Exception('File upload failed: ' . $uploadResult['message']);
                            }
                        }
                    }

                    // Set core entity properties using proper setters
                    if (isset($formData['title'])) $entity->setTitle($formData['title']);
                    if (isset($formData['slug'])) $entity->setValue('slug', $formData['slug']);
                    if (isset($formData['content'])) $entity->setContent($formData['content']);
                    if (isset($formData['excerpt'])) $entity->setValue('excerpt', $formData['excerpt']);

                    // Set publication status
                    if (isset($formData['status'])) {
                        $entity->setStatus($formData['status']);
                    }
                    if (isset($formData['is_published'])) {
                        $entity->setPublished((bool) $formData['is_published']);
                        if ($formData['is_published']) {
                            $entity->setPublishedAt(new \DateTime());
                        }
                    }

                    // Set boolean fields
                    $entity->setValue('featured', isset($formData['featured']) ? (bool) $formData['featured'] : false);
                    $entity->setValue('sticky', isset($formData['sticky']) ? (bool) $formData['sticky'] : false);
                    $entity->setValue('allow_comments', isset($formData['allow_comments']) ? (bool) $formData['allow_comments'] : true);

                    // Set metadata fields
                    $entity->setValue('password', $formData['password'] ?? null);
                    $entity->setValue('template', $formData['template'] ?? null);
                    $entity->setValue('language', $formData['language'] ?? 'en');

                    // Set SEO fields
                    $entity->setValue('meta_title', $formData['meta_title'] ?? null);
                    $entity->setValue('meta_description', $formData['meta_description'] ?? null);
                    $entity->setValue('meta_keywords', $formData['meta_keywords'] ?? null);
                    $entity->setValue('canonical_url', $formData['canonical_url'] ?? null);
                    $entity->setValue('redirect_url', $formData['redirect_url'] ?? null);

                    // Set author and timestamps
                    $entity->setAuthorId($container->get('current_user')->getId());
                    $entity->setCreatedAt(new \DateTime());
                    $entity->setUpdatedAt(new \DateTime());

                    // Set dynamic fields (entity-specific fields)
                    $coreFields = [
                        'entity_type', 'id', 'title', 'slug', 'content', 'excerpt',
                        'status', 'is_published', 'featured', 'sticky', 'allow_comments',
                        'password', 'template', 'language', 'meta_title', 'meta_description',
                        'meta_keywords', 'canonical_url', 'redirect_url', 'submit'
                    ];

                    $fields = $entity->fieldDefinitions();

                    foreach ($formData as $key => $value) {
                        if (!in_array($key, $coreFields) && array_key_exists($key, $fields['fields'])) {
                            $field = $fields['fields'][$key];
                            if (!empty($field['type'])) {
                                switch ($field['type']) {
                                    case 'array':
                                        $value = json_decode($value, true);
                                }
                            }
                            $entity->setValue($key, $value);
                        }
                    }

                    // Save the entity
                    if ($entity->save()) {
                        // Update file entities with the new entity ID
                        foreach ($files as $fieldName => $file) {
                            if ($file && $file->isValid() && isset($formData[$fieldName])) {
                                $fileEntity = \Simp\Pindrop\Entity\File\File::loadByUri($formData[$fieldName], $container->get('database'));
                                if ($fileEntity) {
                                    $fileEntity->setEntityId($entity->getId());
                                    $fileEntity->save();
                                }
                            }
                        }

                        // Redirect to edit page
                        return $this->redirect("/admin/content");
                    } else {
                        throw new \Exception('Failed to save entity');
                    }

                } catch (\Exception $e) {
                    $container->get('logger')->error('Failed to save content', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'type' => $entity->getNodeType(),
                        'data' => $request->request->all()
                    ]);

                    // Add error message
                    $container->get('session')->getFlashBag()->add('error', 'Failed to save ' . $entity->getNodeType() . ': ' . $e->getMessage());

                    // Re-throw exception to let Whoops handle it in development
                    $environment = getenv('APP_ENV') ?: 'development';
                    if ($environment !== 'production') {
                        throw $e;
                    }
                }
            }

            return $this->renderTwig('admin/content/add.twig', [
                'page_title' => 'Edit ' . ucfirst($entity->getNodeType()),
                'content_type' => ucfirst($entity->getNodeType()),
                'type' => $entity->getNodeType(),
                'form_html' => $formHtml,
                'description' => $contentTypeInfo['config']['description'] ?? "",
                'entity' => $entity,
            ]);

        } catch (\Exception $e) {
            // Re-throw exception to let Whoops handle it in development
            $environment = getenv('APP_ENV') ?: 'development';
            if ($environment !== 'production') {
                throw $e;
            }

            // In production, show generic error and redirect
            $container = getAppContainer();
            $container->get('session')->getFlashBag()->add('error', 'Failed to load content creation page');
            return $this->redirect('/admin/content/create');
        }
    }

    public function viewContent(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');

        if (empty($id)) {
            return $this->redirect('/admin/content');
        }

        try{
            $container = getAppContainer();
            $repository = $container->get('content.repository');

            $addAdminTheme = in_array($this->getCurrentUser()['role'], ['super_admin', 'admin', 'moderator']);

            $theme = $addAdminTheme ? "admin/content/view_admin.twig" : "admin/content/view.twig";

            /**@var ContentEntityInterface $entity **/
            $entity = $repository->find($id);
            return $this->renderTwig($theme, [
                'entity' => $entity,
                'admin_theme' => $addAdminTheme,

            ]);
        }catch (\Throwable $exception) {

        }

        return $this->redirect('/admin/content');
    }
    
    /**
     * Get dashboard statistics
     */
    private function getDashboardStats(): array
    {
        return [
            'total_users' => User::count($this->database),
            'total_content' => 45,
            'total_media' => File::count($this->database),
            'recent_logins' => UserVerification::currentRecentsCount($this->database),
        ];
    }
    
    /**
     * Get admin settings
     */
    private function getAdminSettings(): array
    {
        return [
            'site_name' => 'Pindrop CMS',
            'site_email' => 'admin@pindrop.dev',
            'maintenance_mode' => false,
            'debug_mode' => true
        ];
    }
    
    /**
     * Get users list
     */
    private function getUsersList(): array
    {
        try {
            $users = User::loadAll($this->database);
            
            // Convert User objects to arrays for template
            $usersArray = [];
            foreach ($users as $user) {
                $usersArray[] = [
                    'id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'display_name' => $user->getDisplayName(),
                    'role' => $user->getRole(),
                    'status' => $user->getStatus(),
                    'created_at' => $user->getCreatedAt() ? $user->getCreatedAt()->format('Y-m-d H:i:s') : null,
                    'last_login_at' => $user->getLastLoginAt() ? $user->getLastLoginAt()->format('Y-m-d H:i:s') : null,
                    'email_verified' => $user->isEmailVerified(),
                    'is_admin' => $user->isAdmin()
                ];
            }
            
            return $usersArray;
        } catch (Exception $e) {
            // Return empty array if there's an error
            return [];
        }
    }
    
    /**
     * Get content list
     */
    private function getContentList(int $page = 1, int $limit = 20, string $type = '', string $status = '', string $search = ''): array
    {
        try {
            $container = getAppContainer();
            $factory = $container->get('content.factory');
            $repository = $container->get('content.repository');
            $contentList = [];
            
            // Get all registered entity types
            $allEntities = $repository->getAll();
            
            foreach ($allEntities as $entityName => $entityData) {
                $className = $entityData['class'];
                
                // Check if class exists and has the all() method
                if (class_exists($className) && method_exists($className, 'all')) {
                    try {
                        // Build options for filtering
                        $options = ['limit' => $limit, 'page' => $page];
                        
                        if ($type && strtolower($entityName) !== strtolower($type)) {
                            continue; // Skip if type doesn't match filter
                        }
                        
                        // Load all entities of this type
                        $entities = $className::all($options);
                        
                        foreach ($entities as $entity) {
                            // Apply status filter
                            if ($status && strtolower($entity->getStatus()) !== strtolower($status)) {
                                continue;
                            }
                            
                            // Apply search filter
                            if ($search) {
                                $searchLower = strtolower($search);
                                $titleLower = strtolower($entity->getTitle());
                                $contentLower = strtolower($entity->getContent());
                                
                                if (strpos($titleLower, $searchLower) === false && 
                                    strpos($contentLower, $searchLower) === false) {
                                    continue;
                                }
                            }
                            
                            $contentList[] = [
                                'id' => $entity->getId(),
                                'title' => $entity->getTitle(),
                                'type' => ucfirst($entityName),
                                'status' => ucfirst($entity->getStatus()),
                                'slug' => $entity->getSlug(),
                                'created_at' => $entity->getCreatedAt()->format('Y-m-d H:i:s'),
                                'updated_at' => $entity->getUpdatedAt()->format('Y-m-d H:i:s'),
                                'author' => $entity->getAuthor() ? $entity->getAuthor()->getUsername() : 'Unknown',
                                'published' => $entity->isPublished() ? 'Yes' : 'No',
                                'entity_class' => $className,
                                'entity_name' => $entityName,
                                'excerpt' => substr(strip_tags($entity->getContent()), 0, 150) . '...'
                            ];
                        }
                    } catch (Exception $e) {
                        // Log error for this entity type but continue with others
                        $container->get('logger')->warning("Failed to load entities of type: {$entityName}", [
                            'error' => $e->getMessage(),
                            'class' => $className
                        ]);
                        continue;
                    }
                }
            }
            
            // Sort by created_at descending
            usort($contentList, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            // Apply pagination to the combined results
            $offset = ($page - 1) * $limit;
            return array_slice($contentList, $offset, $limit);
            
        } catch (Exception $e) {
            $container->get('logger')->error('Failed to get content list', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return empty array on error
            return [];
        }
    }
    
    /**
     * Get content pagination data
     */
    private function getContentPagination(int $page = 1, int $limit = 20, string $type = '', string $status = '', string $search = ''): array
    {
        try {
            $container = getAppContainer();
            $factory = $container->get('content.factory');
            $repository = $container->get('content.repository');
            $totalCount = 0;
            
            // Get all registered entity types and count total items
            $allEntities = $repository->getAll();
            
            foreach ($allEntities as $entityName => $entityData) {
                $className = $entityData['class'];
                
                if (class_exists($className) && method_exists($className, 'count')) {
                    try {
                        // Apply type filter
                        if ($type && strtolower($entityName) !== strtolower($type)) {
                            continue;
                        }
                        
                        // Count entities of this type
                        $count = $className::count();
                        $totalCount += $count;
                    } catch (Exception $e) {
                        $container->get('logger')->warning("Failed to count entities of type: {$entityName}", [
                            'error' => $e->getMessage(),
                            'class' => $className
                        ]);
                        continue;
                    }
                }
            }
            
            $totalPages = ceil($totalCount / $limit);
            
            return [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalCount,
                'per_page' => $limit,
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
                'previous_page' => $page > 1 ? $page - 1 : null,
                'next_page' => $page < $totalPages ? $page + 1 : null,
                'showing_start' => ($page - 1) * $limit + 1,
                'showing_end' => min($page * $limit, $totalCount)
            ];
            
        } catch (Exception $e) {
            $container->get('logger')->error('Failed to get content pagination', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'current_page' => $page,
                'total_pages' => 1,
                'total_items' => 0,
                'per_page' => $limit,
                'has_previous' => false,
                'has_next' => false,
                'previous_page' => null,
                'next_page' => null,
                'showing_start' => 0,
                'showing_end' => 0
            ];
        }
    }
    
    /**
     * Get available content types from repository
     */
    private function getContentTypes(): array
    {
        try {
            $container = getAppContainer();
            $repository = $container->get('content.repository');
            $allEntities = $repository->getAll();
            $contentTypes = [];
            
            foreach ($allEntities as $entityName => $entityData) {
                $contentTypes[] = [
                    'name' => $entityName,
                    'label' => ucfirst($entityName),
                    'class' => $entityData['class'],
                    'description' => $entityData['config']['description'] ?? '',
                    'category' => $entityData['config']['category'] ?? 'content'
                ];
            }
            
            // Sort by label
            usort($contentTypes, function($a, $b) {
                return strcmp($a['label'], $b['label']);
            });
            
            return $contentTypes;
            
        } catch (Exception $e) {
            $container->get('logger')->error('Failed to get content types', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [];
        }
    }
    
    /**
     * Get current user (placeholder)
     */
    private function getCurrentUser(): array
    {
        /**@var CurrentUser $currentUser**/
        $currentUser = getAppContainer()->get('current_user');
        return $currentUser->getUser()->toArray();
    }

    /**
     * User registration page
     */
    public function register(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            
            try {
                // Validate required fields
                $requiredFields = ['username', 'email', 'password', 'password_confirm'];
                foreach ($requiredFields as $field) {
                    if (empty($data[$field])) {
                        throw new InvalidArgumentException("Field '{$field}' is required");
                    }
                }

                // Validate password match
                if ($data['password'] !== $data['password_confirm']) {
                    throw new InvalidArgumentException("Passwords do not match");
                }

                // Validate email format
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException("Invalid email format");
                }

                // Check if user already exists
                $existingUser = User::loadByEmail($data['email'],$this->database);
                if ($existingUser) {
                    throw new InvalidArgumentException("User with this email already exists");
                }

                $existingUsername = User::loadByUsername($data['username'], $this->database);
                if ($existingUsername) {
                    throw new InvalidArgumentException("Username already taken");
                }

                // Create new user
                $user = new User([], $this->database);
                $user->setUsername($data['username']);
                $user->setEmail($data['email']);
                $user->setPassword($data['password']);
                $user->setRole(User::ROLE_USER);
                $user->setStatus(User::STATUS_PENDING);

                if ($user->save()) {
                    // Create email verification token
                    $verification = UserVerification::createEmailVerificationToken(
                        $this->database,
                        getAppContainer()->get('logger'),
                        $user->getId(),
                        $user->getEmail(),
                        $request->getClientIp(),
                        $request->headers->get('User-Agent')
                    );

                    if ($verification) {
                        // TODO: Send verification email
                        getAppContainer()->get('logger')->info('Registration successful, verification email sent', [
                            'user_id' => $user->getId(),
                            'email' => $user->getEmail()
                        ]);
                    }

                    return new RedirectResponse('/user/login?message=registration_success');
                } else {
                    throw new RuntimeException("Failed to create user");
                }

            } catch (Exception $e) {
                getAppContainer()->get('logger')->error('Registration failed', [
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);

                return $this->renderTwig('admin/auth/register.twig', [
                    'page_title' => 'Register',
                    'error' => $e->getMessage(),
                    'old_input' => $data
                ]);
            }
        }

        return $this->renderTwig('admin/auth/register.twig', [
            'page_title' => 'Register',
            'error' => null,
            'old_input' => []
        ]);
    }

    /**
     * User login page
     */
    public function login(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            
            try {
                // Validate required fields
                if (empty($data['email']) || empty($data['password'])) {
                    throw new InvalidArgumentException("Email and password are required");
                }

                // Find user by email
                $user = User::loadByEmail( $data['email'], $this->database);
                if (!$user) {
                    throw new InvalidArgumentException("Invalid credentials");
                }

                // Verify password
                if (!$user->verifyPassword($data['password'])) {
                    throw new InvalidArgumentException("Invalid credentials");
                }

                // Check user status
                if ($user->getStatus() === User::STATUS_BANNED) {
                    throw new InvalidArgumentException("Account banned");
                }

                if ($user->getStatus() === User::STATUS_SUSPENDED) {
                    throw new InvalidArgumentException("Account suspended");
                }

                // Create session
                $sessionId = session_id();
                $session = new CurrentUser($this->database, getAppContainer()->get('logger'));
                $session->setUserId($user->getId());
                $session->setSessionId($sessionId);
                $session->setIpAddress($request->getClientIp());
                $session->setUserAgent($request->headers->get('User-Agent'));
                $session->setExpiresAt((new DateTime())->add(new DateInterval('PT24H')));

                if ($session->create()) {
                    // Set session cookie
                    $response = new RedirectResponse(Url::routeByName('users.view.user',['user_id' => $user->getId()]));
                    $response->headers->setCookie(
                        new Cookie(
                            'session_id',
                            $sessionId,
                            new DateTime('+24 hours'),
                            '/',
                            null,
                            true,
                            true,
                        )
                    );
                    getAppContainer()->get('logger')->info('User logged in successfully', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail(),
                        'ip' => $request->getClientIp()
                    ]);

                    return $response;
                } else {
                    throw new RuntimeException("Failed to create session");
                }

            } catch (Exception $e) {
                getAppContainer()->get('logger')->error('Login failed', [
                    'error' => $e->getMessage(),
                    'email' => $data['email'] ?? 'unknown',
                    'ip' => $request->getClientIp()
                ]);

                return $this->renderTwig('admin/auth/login.twig', [
                    'page_title' => 'Login',
                    'error' => $e->getMessage(),
                    'old_input' => $data
                ]);
            }
        }

        return $this->renderTwig('admin/auth/login.twig', [
            'page_title' => 'Login',
            'error' => null,
            'old_input' => [],
            'message' => $request->query->get('message')
        ]);
    }

    /**
     * User logout
     */
    public function logout(Request $request, string $route_name, array $options): Response
    {
        $sessionId = session_id();

        if ($sessionId) {
            $session = CurrentUser::findBySessionId($this->database, getAppContainer()->get('logger'), $sessionId);

            if ($session) {
                $session->delete();
                getAppContainer()->get('logger')->info('User logged out', [
                    'user_id' => $session->getUserId(),
                    'session_id' => $sessionId
                ]);
            }
        }

        $response = new RedirectResponse('/user/login');
        $response->headers->clearCookie('session_id');
        
        return $response;
    }

    /**
     * Forgot password page
     */
    public function forgotPassword(Request $request, string $route_name, array $options): Response
    {
        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            
            try {
                if (empty($data['email'])) {
                    throw new InvalidArgumentException("Email is required");
                }

                $user = User::loadByEmail($data['email'], $this->database);
                if (!$user) {
                    // Don't reveal if email exists or not
                    return $this->renderTwig('admin/auth/forgot-password.twig', [
                        'page_title' => 'Forgot Password',
                        'success' => 'If an account with that email exists, a password reset link has been sent.',
                        'old_input' => $data
                    ]);
                }

                // Create password reset token
                $verification = UserVerification::createPasswordResetToken(
                    $this->database,
                    getAppContainer()->get('logger'),
                    $user->getId(),
                    $user->getEmail(),
                    $request->getClientIp(),
                    $request->headers->get('User-Agent')
                );

                if ($verification) {
                    // TODO: Send password reset email
                    getAppContainer()->get('logger')->info('Password reset token created', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail()
                    ]);
                }

                return $this->renderTwig('admin/auth/forgot-password.twig', [
                    'page_title' => 'Forgot Password',
                    'success' => 'If an account with that email exists, a password reset link has been sent.',
                    'old_input' => $data
                ]);

            } catch (Exception $e) {
                getAppContainer()->get('logger')->error('Forgot password failed', [
                    'error' => $e->getMessage(),
                    'email' => $data['email'] ?? 'unknown'
                ]);

                return $this->renderTwig('admin/auth/forgot-password.twig', [
                    'page_title' => 'Forgot Password',
                    'error' => $e->getMessage(),
                    'old_input' => $data
                ]);
            }
        }

        return $this->renderTwig('admin/auth/forgot-password.twig', [
            'page_title' => 'Forgot Password',
            'error' => null,
            'success' => null,
            'old_input' => []
        ]);
    }

    /**
     * Reset password page
     */
    public function resetPassword(Request $request, string $route_name, array $options, string $token): Response
    {
        // Find verification token
        $verification = UserVerification::findByToken($this->database, getAppContainer()->get('logger'), $token);
        
        if (!$verification || !$verification->isValid()) {
            return $this->renderTwig('admin/auth/reset-password.twig', [
                'page_title' => 'Reset Password',
                'error' => 'Invalid or expired reset token',
                'token' => $token
            ]);
        }

        if ($request->isMethod('POST')) {
            $data = $request->request->all();
            
            try {
                // Validate required fields
                $requiredFields = ['password', 'password_confirm'];
                foreach ($requiredFields as $field) {
                    if (empty($data[$field])) {
                        throw new InvalidArgumentException("Field '{$field}' is required");
                    }
                }

                // Validate password match
                if ($data['password'] !== $data['password_confirm']) {
                    throw new InvalidArgumentException("Passwords do not match");
                }

                // Get user and update password
                $user = $verification->getUser();
                if (!$user) {
                    throw new InvalidArgumentException("User not found");
                }

                $user->setPassword($data['password']);
                
                if ($user->save()) {
                    // Mark token as used
                    $verification->markAsUsed();
                    
                    // Revoke all other sessions for security
                    CurrentUser::revokeAllUserSessions($this->database, getAppContainer()->get('logger'), $user->getId());

                    \appEvents()->invokeEvents(\Simp\Pindrop\Events\SystemEvents\Events::AUTH_PASSWORD_RESET, ['user' => $user]);

                    getAppContainer()->get('logger')->info('Password reset successful', [
                        'user_id' => $user->getId(),
                        'email' => $user->getEmail()
                    ]);

                    return new RedirectResponse('/user/login?message=password_reset_success');
                } else {
                    throw new RuntimeException("Failed to update password");
                }

            } catch (Exception $e) {
                getAppContainer()->get('logger')->error('Password reset failed', [
                    'error' => $e->getMessage(),
                    'token' => $token
                ]);

                return $this->renderTwig('admin/auth/reset-password.twig', [
                    'page_title' => 'Reset Password',
                    'error' => $e->getMessage(),
                    'token' => $token,
                    'old_input' => $data
                ]);
            }
        }

        return $this->renderTwig('admin/auth/reset-password.twig', [
            'page_title' => 'Reset Password',
            'error' => null,
            'token' => $token,
            'old_input' => []
        ]);
    }

    /**
     * Verify email
     */
    public function verifyEmail(Request $request, string $route_name, array $options, string $token): Response
    {
        // Find verification token
        $verification = UserVerification::findByToken($this->database, getAppContainer()->get('logger'), $token);
        
        if (!$verification || !$verification->isValid()) {
            return $this->renderTwig('admin/auth/verify-email.twig', [
                'page_title' => 'Verify Email',
                'error' => 'Invalid or expired verification token',
                'success' => false
            ]);
        }

        if ($verification->getTokenType() !== UserVerification::TOKEN_TYPE_EMAIL_VERIFICATION) {
            return $this->renderTwig('admin/auth/verify-email.twig', [
                'page_title' => 'Verify Email',
                'error' => 'Invalid token type',
                'success' => false
            ]);
        }

        try {
            // Get user and mark email as verified
            $user = $verification->getUser();
            if (!$user) {
                throw new InvalidArgumentException("User not found");
            }

            $user->setEmailVerifiedAt(new DateTime());
            
            // Activate user if status is pending
            if ($user->getStatus() === User::STATUS_PENDING) {
                $user->setStatus(User::STATUS_ACTIVE);
            }
            
            if ($user->save()) {
                // Mark token as used
                $verification->markAsUsed();

                getAppContainer()->get('logger')->info('Email verified successfully', [
                    'user_id' => $user->getId(),
                    'email' => $user->getEmail()
                ]);

                return $this->renderTwig('admin/auth/verify-email.twig', [
                    'page_title' => 'Verify Email',
                    'error' => null,
                    'success' => true,
                    'user' => $user
                ]);
            } else {
                throw new RuntimeException("Failed to verify email");
            }

        } catch (Exception $e) {
            getAppContainer()->get('logger')->error('Email verification failed', [
                'error' => $e->getMessage(),
                'token' => $token
            ]);

            return $this->renderTwig('admin/auth/verify-email.twig', [
                'page_title' => 'Verify Email',
                'error' => $e->getMessage(),
                'success' => false
            ]);
        }
    }

    public function viewUserDisplay(Request $request, string $route_name, array $options): Response
    {
        // Display page for user this page can be viewed by logged in user only
        // so show just enough data for security reasons
        $user_id = $request->query->get('user_id');
        $container = getAppContainer();
        $currentUser = $container->get('current_user');
        
        // Security check: user can only view their own profile or admin can view any
        if (!$currentUser || ($currentUser->getUser()->getId() !== $user_id && !in_array($currentUser->getUser()->getRole(), ['admin', 'super_admin']))) {
            return new RedirectResponse('/admin/login?redirect=' . urlencode($request->getRequestUri()));
        }
        
        try {
            // Get user data
            $user = $container->get('user_repository')->findById($user_id);
            
            if (!$user) {
                return new RedirectResponse('/admin/users');
            }
            
            // Get user's content/posts
            $contentFactory = $container->get('content.repository');
            $userContent = $contentFactory->findBy(['author_id' => $user_id], ['created_at' => 'DESC'], 10);
            
            // Prepare user data for display (security-conscious)
            $userData = [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'role' => $user->getRole(),
                'created_at' => $user->getCreatedAt(),
                'updated_at' => $user->getUpdatedAt(),
                'status' => $user->getStatus() ?? 'active',
                'content_count' => count($userContent),
                'recent_content' => array_map(function($content) {
                    return [
                        'id' => $content->getId(),
                        'title' => $content->getTitle(),
                        'type' => $content->getNodeType(),
                        'status' => $content->getStatus(),
                        'created_at' => $content->getCreatedAt(),
                        'url' => '/admin/content/edit/' . $content->getId()
                    ];
                }, $userContent)
            ];
            
            // Add additional admin-only data if current user is admin
            if (in_array($currentUser->getUser()->getRole(), ['admin', 'super_admin'])) {
                $userData['admin_info'] = [
                    'last_login' => $user->getLastLoginAt(),
                    'login_count' => $user->getLoginCount() ?? 0,
                    'ip_address' => $user->getLastLoginIp(),
                    'is_verified' => $user->isVerified() ?? false
                ];
            }
            
            return $this->renderTwig('admin/users/view_display.twig', [
                'page_title' => 'User Profile: ' . $user->getUsername(),
                'user' => $userData,
                'current_user' => $currentUser->getUser(),
                'is_admin' => in_array($currentUser->getUser()->getRole(), ['admin', 'super_admin']),
                'is_own_profile' => $currentUser->getUser()->getId() === $user_id
            ]);
            
        } catch (\Exception $e) {
            // Log error and show user-friendly message
            if ($container->has('logger')) {
                $container->get('logger')->error('Error viewing user profile: ' . $e->getMessage());
            }
            
            return new RedirectResponse('/admin/users');
        }
    }

    /**
     * Handle autocomplete requests
     */
    public function autocomplete(Request $request, string $route_name, array $options): JsonResponse
    {
        $source = $request->query->get('source');
        $query = $request->query->get('q', '');
        $limit = intval($request->query->get('limit', 10));
        $sort = $request->query->get('sort', 'DESC');
        $sort_by = $request->query->get('sort_by', null);

        try {
            $results = [];

            /**@var AutoCompleteService $autocompleteService **/
            $autocompleteService = \getAppContainer()->get('internal.autocomplete');

            $configs = [
                'source' => $source,
                'limit'  => $limit,
                'sort'   => $sort,
                'sort_by' => $sort_by
            ];

            $autocompleteService->setConfig($configs);

            $results = $autocompleteService->matches($query);


            return new JsonResponse([
                'results' => $results
            ]);

        } catch (Exception $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'results' => []
            ], 400);
        }
    }


    public function addressFieldBuild(Request $request, string $route_name, array $options): JsonResponse
    {
        $code = $request->query->get('code');
        $name = $request->query->get('name');
        if (empty($name) || empty($code)) {
            return new JsonResponse(['status' => false]);
        }

        $addressFormatter = new AddressFormatter($code);

        return new JsonResponse(['status' => true, 'address' => $addressFormatter->getAddressTemplate($name)]);
    }

    public function csrfTokenGenerator(Request $request, string $route_name, array $options): Response
    {
        return new JsonResponse(['token' => Url::generateToken($request, $_ENV['CSRF_TOKEN_SECRET'])]);
    }

    public function test(Request $request, string $route_name, array $options) {
        return $this->renderTwig("@admin/test.twig");
    }

    public function countries(Request $request, string $route_name, array $options){
        $countryRepository = new CountryRepository();
        $countries = $countryRepository->getAll();
        $countriesList = [];
        foreach ($countries as $code=>$country) {
            $country = $countryRepository->get($code);
            $countriesList[$code] = $country->getName();

        }
        return new JsonResponse($countriesList);
    }
}
