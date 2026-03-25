<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\upload\src\Services;

use Simp\Pindrop\Entity\File\File;
use Simp\Pindrop\FileSystem\FileSystemInterface;
use Simp\Pindrop\Logger\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload Service
 * 
 * Handles file uploads using the FileSystem service and File entity.
 */
class UploadService
{
    private FileSystemInterface $fileSystem;
    private LoggerInterface $logger;
    private array $uploadConfig;

    public function __construct(FileSystemInterface $fileSystem, LoggerInterface $logger)
    {
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
        $this->uploadConfig = $this->getDefaultUploadConfig();
    }

    /**
     * Get file system instance
     */
    public function getFileSystem(): FileSystemInterface
    {
        return $this->fileSystem;
    }

    /**
     * Handle file upload
     */
    public function handleUpload(UploadedFile $file, string $fieldName): array
    {
        try {
            // Validate file
            $validation = $this->validateFile($file, $fieldName);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validation['errors']
                ];
            }

            // Generate destination path
            $destinationPath = $this->generateDestinationPath($file, $fieldName);

            // Upload file using FileSystem
            $uploadResult = $this->fileSystem->uploadFile(
                [
                    'name' => $file->getClientOriginalName(),
                    'tmp_name' => $file->getRealPath(),
                    'size' => $file->getSize(),
                    'error' => $file->getError(),
                    'type' => $file->getMimeType()
                ],
                $destinationPath,
                [
                    'allowed_types' => $this->uploadConfig['allowed_types'][$fieldName] ?? $this->uploadConfig['default_allowed_types'],
                    'max_size' => $this->uploadConfig['max_sizes'][$fieldName] ?? $this->uploadConfig['default_max_size'],
                    'unique' => true
                ]
            );

            if ($uploadResult['success']) {
                try {
                    // Create File entity and store in database
                    $fileEntity = $this->createFileEntity($uploadResult['data'][0], $fieldName);
                    
                    $this->logger->info("Attempting to save file entity", [
                        'field_name' => $fieldName,
                        'filename' => $fileEntity->getFilename(),
                        'uri' => $fileEntity->getUri(),
                        'filesize' => $fileEntity->getFilesize()
                    ]);
                    
                    if ($fileEntity->save()) {
                        $this->logger->info("File uploaded successfully", [
                            'field_name' => $fieldName,
                            'file_id' => $fileEntity->getUuid(),
                            'file_name' => $fileEntity->getFilename(),
                            'file_size' => $fileEntity->getFilesize()
                        ]);

                        return [
                            'success' => true,
                            'data' => [$fileEntity->toArray()],
                            'message' => 'File uploaded successfully'
                        ];
                    } else {
                        // If database save fails, delete the uploaded file
                        $this->logger->error("Failed to save file entity to database", [
                            'field_name' => $fieldName,
                            'filename' => $fileEntity->getFilename(),
                            'uri' => $fileEntity->getUri()
                        ]);
                        
                        $this->getFileSystem()->delete($uploadResult['data'][0]['uri']);
                        
                        return [
                            'success' => false,
                            'message' => 'Failed to save file record to database',
                            'errors' => ['Database save operation returned false']
                        ];
                    }
                } catch (\Exception $e) {
                    // If entity creation fails, delete the uploaded file
                    $this->logger->error("File entity creation failed", [
                        'field_name' => $fieldName,
                        'upload_result' => $uploadResult['data'][0],
                        'error' => $e->getMessage()
                    ]);
                    
                    $this->getFileSystem()->delete($uploadResult['data'][0]['uri']);
                    
                    return [
                        'success' => false,
                        'message' => 'File entity creation failed',
                        'errors' => [$e->getMessage()]
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => $uploadResult['message'],
                    'errors' => $uploadResult['errors'] ?? []
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error("File upload failed", [
                'field_name' => $fieldName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Remove uploaded file
     */
    public function removeFile(string $fileId, string $fieldName): array
    {
        try {
            // Get file record using File entity
            $fileEntity = File::loadByUuid($fileId);
            
            if (!$fileEntity) {
                return [
                    'success' => false,
                    'message' => 'File not found'
                ];
            }

            // Delete file using FileSystem
            $deleted = $this->fileSystem->delete($fileEntity->getUri());

            if ($deleted) {
                // Remove file record using File entity
                $fileEntity->delete();

                $this->logger->info("File removed successfully", [
                    'file_id' => $fileId,
                    'file_name' => $fileEntity->getFilename()
                ]);

                return [
                    'success' => true,
                    'message' => 'File removed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to delete file'
                ];
            }

        } catch (\Exception $e) {
            $this->logger->error("File removal failed", [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'File removal failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Create File entity from upload result
     */
    private function createFileEntity(array $uploadResult, string $fieldName): File
    {
        try {
            // Check if database service is available
            if (!function_exists('getAppContainer')) {
                throw new \Exception('App container function not available');
            }
            
            $container = getAppContainer();
            if (!$container->has('database')) {
                throw new \Exception('Database service not available in container');
            }
            
            $database = $container->get('database');
            
            // Test database connection
            try {
                $testResult = $database->fetch('SELECT 1 as test');
                if (!$testResult || $testResult['test'] !== 1) {
                    throw new \Exception('Database connection test failed');
                }
            } catch (\Exception $e) {
                throw new \Exception('Database connection failed: ' . $e->getMessage());
            }
            
            // Check if file_managed table exists
            try {
                $tableCheck = $database->fetch("SHOW TABLES LIKE 'file_managed'");
                if (!$tableCheck) {
                    throw new \Exception('file_managed table does not exist');
                }
            } catch (\Exception $e) {
                throw new \Exception('Failed to check file_managed table: ' . $e->getMessage());
            }
            
            $fileEntity = new File([
                'filename' => $uploadResult['name'],
                'uri' => $uploadResult['uri'],
                'filemime' => $uploadResult['mime_type'],
                'filesize' => $uploadResult['size'],
                'status' => File::STATUS_PERMANENT,
                'field_name' => $fieldName,
                'langcode' => 'en',
                'checksum' => $this->generateChecksum($uploadResult['uri']),
                'metadata' => [
                    'original_name' => $uploadResult['original_name'],
                    'extension' => $uploadResult['extension'],
                    'upload_config' => $this->uploadConfig
                ]
            ],
                $database,
                $this->logger
            );

            // Set user ID if available
            if ($container->has('current_user')) {
                $currentUser = $container->get('current_user');
                if ($currentUser && method_exists($currentUser, 'getId')) {
                    $fileEntity->setUid($currentUser->getId());
                }
            }

            // Extract image dimensions if it's an image
            if (str_starts_with($uploadResult['mime_type'], 'image/')) {
                $dimensions = $this->getImageDimensions($uploadResult['uri']);
                if ($dimensions) {
                    $fileEntity->setWidth($dimensions['width']);
                    $fileEntity->setHeight($dimensions['height']);
                }
            }

            return $fileEntity;
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to create File entity", [
                'field_name' => $fieldName,
                'upload_result' => $uploadResult,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw with more context
            throw new \Exception('File entity creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate checksum for file
     */
    private function generateChecksum(string $uri): ?string
    {
        try {
            $realPath = $this->getFileSystem()->realPath($uri);
            if ($realPath && file_exists($realPath)) {
                return hash_file('sha256', $realPath);
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to generate checksum", [
                'uri' => $uri,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Get image dimensions
     */
    private function getImageDimensions(string $uri): ?array
    {
        try {
            $realPath = $this->getFileSystem()->realPath($uri);
            if ($realPath && file_exists($realPath)) {
                $imageInfo = getimagesize($realPath);
                if ($imageInfo !== false) {
                    return [
                        'width' => $imageInfo[0],
                        'height' => $imageInfo[1]
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to get image dimensions", [
                'uri' => $uri,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(UploadedFile $file, string $fieldName): array
    {
        $errors = [];

        // Check file size
        $maxSize = $this->uploadConfig['max_sizes'][$fieldName] ?? $this->uploadConfig['default_max_size'];
        if ($maxSize && $file->getSize() > $maxSize) {
            $errors[] = "File size exceeds maximum allowed size of " . $this->formatBytes($maxSize);
        }

        // Check file type
        $allowedTypes = $this->uploadConfig['allowed_types'][$fieldName] ?? $this->uploadConfig['default_allowed_types'];
        if (!empty($allowedTypes)) {
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $allowedTypes) && !in_array('.' . $extension, $allowedTypes)) {
                $errors[] = "File type '{$extension}' is not allowed. Allowed types: " . implode(', ', $allowedTypes);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Generate destination path for uploaded file
     */
    private function generateDestinationPath(UploadedFile $file, string $fieldName): string
    {
        $datePath = date('Y/m/d');
        $originalName = $file->getClientOriginalName();
        $extension = '.' . $file->getClientOriginalExtension();
        
        // Generate safe base filename
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        
        // Try to create file with incremental numbering
        $counter = 0;
        $uniqueFilename = $safeFilename . $extension;
        $fullPath = "public://uploads/{$fieldName}/{$datePath}/{$uniqueFilename}";
        
        // Check if file exists and increment if needed
        while ($this->getFileSystem()->exists($fullPath)) {
            $counter++;
            $uniqueFilename = $safeFilename . '_' . $counter . $extension;
            $fullPath = "public://uploads/{$fieldName}/{$datePath}/{$uniqueFilename}";
        }
        
        return $fullPath;
    }

    /**
     * Get default upload configuration
     */
    private function getDefaultUploadConfig(): array
    {
        return [
            'default_allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'],
            'default_max_size' => 10 * 1024 * 1024, // 10MB
            'allowed_types' => [
                'avatar' => ['jpg', 'jpeg', 'png', 'gif'],
                'document' => ['pdf', 'doc', 'docx', 'txt'],
                'image' => ['jpg', 'jpeg', 'png', 'gif'],
                'video' => ['mp4', 'avi', 'mov'],
                'audio' => ['mp3', 'wav', 'ogg']
            ],
            'max_sizes' => [
                'avatar' => 2 * 1024 * 1024, // 2MB
                'document' => 20 * 1024 * 1024, // 20MB
                'image' => 5 * 1024 * 1024, // 5MB
                'video' => 100 * 1024 * 1024, // 100MB
                'audio' => 20 * 1024 * 1024 // 20MB
            ]
        ];
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 Bytes';
        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    /**
     * Get files by user ID
     */
    public function getFilesByUser(int $userId): array
    {
        try {
            $files = File::loadByUser($userId);
            return array_map(fn($file) => $file->toArray(), $files);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get files by user", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get files by entity
     */
    public function getFilesByEntity(string $entityType, int $entityId): array
    {
        try {
            $files = File::loadByEntity($entityType, $entityId);
            return array_map(fn($file) => $file->toArray(), $files);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get files by entity", [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get file by UUID
     */
    public function getFileByUuid(string $uuid): ?array
    {
        try {
            $file = File::loadByUuid($uuid);
            return $file ? $file->toArray() : null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get file by UUID", [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
