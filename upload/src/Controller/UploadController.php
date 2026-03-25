<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\upload\src\Controller;

use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\File\File;
use Simp\Pindrop\Modules\upload\src\Services\UploadService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Upload Controller
 * 
 * Handles file upload routes and requests.
 */
class UploadController extends ControllerBase
{
    private UploadService $uploadService;

    public function __construct(UploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }

    public static function create(ContainerInterface $container): UploadController
    {
        return new self($container->get('upload.service'));
    }

    /**
     * Handle file upload
     */
    public function handleUpload(Request $request, string $route_name): Response
    {
        try {
            // Check if file was uploaded
            if (!$request->files->has('file')) {
                return new JsonResponse([
                    'status' => false,
                    'message' => 'No file uploaded'
                ], 400);
            }

            $file = $request->files->get('file');
            $fieldName = $request->request->get('field_name', 'upload');

            // Validate uploaded file
            if ($file->getError() !== UPLOAD_ERR_OK) {
                return new JsonResponse([
                    'status' => false,
                    'message' => $this->getUploadErrorMessage($file->getError())
                ], 400);
            }

            // Process upload using UploadService
            $result = $this->uploadService->handleUpload($file, $fieldName);

            if ($result['success']) {
                return new JsonResponse([
                    'status' => true,
                    'data' => $result['data'],
                    'message' => $result['message']
                ]);
            } else {
                return new JsonResponse([
                    'status' => false,
                    'message' => $result['message'],
                    'errors' => $result['errors'] ?? []
                ], 400);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove uploaded file
     */
    public function removeFile(Request $request, string $route_name): Response
    {
        try {
            $fileId = $request->request->get('file_id');
            $fieldName = $request->request->get('field_name', 'upload');

            if (!$fileId) {
                return new JsonResponse([
                    'status' => false,
                    'message' => 'File ID is required'
                ], 400);
            }

            // Try to load file by UUID first, then by ID
            $file = null;
            if (ctype_digit($fileId)) {
                // Try loading by numeric ID
                $file = File::load((int)$fileId);
            } else {
                // Try loading by UUID
                $file = File::loadByUuid($fileId);
            }

            if (!$file) {
                return new JsonResponse([
                    'status' => false,
                    'message' => 'File not found',
                    'debug' => [
                        'file_id' => $fileId,
                        'is_numeric' => ctype_digit($fileId)
                    ]
                ], 404);
            }

            // Delete file using FileSystem
            $deleted = $this->uploadService->getFileSystem()->delete($file->getUri());

            if ($deleted) {
                // Remove file record using File entity
                $file->delete();

                return new JsonResponse([
                    'status' => true,
                    'message' => 'File removed successfully',
                    'file_info' => [
                        'id' => $file->getId(),
                        'uuid' => $file->getUuid(),
                        'filename' => $file->getFilename(),
                        'uri' => $file->getUri()
                    ]
                ]);
            } else {
                return new JsonResponse([
                    'status' => false,
                    'message' => 'Failed to delete file from filesystem',
                    'file_info' => [
                        'id' => $file->getId(),
                        'uuid' => $file->getUuid(),
                        'filename' => $file->getFilename(),
                        'uri' => $file->getUri()
                    ]
                ], 500);
            }

        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => false,
                'message' => 'File removal failed: ' . $e->getMessage(),
                'debug' => [
                    'file_id' => $request->request->get('file_id'),
                    'field_name' => $request->request->get('field_name'),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error'
        };
    }
}
