<?php

namespace Simp\Pindrop\Modules\admin\src\Controller;


use Psr\Container\ContainerInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Entity\File\File;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\FileSystem\FileSystemService;
use Simp\Pindrop\Session\SessionStorage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class UploadFileController extends ControllerBase
{



    public function __construct(protected FileSystemService $file_system_service)
    {

        return parent::__construct();
    }

    public static function create(ContainerInterface $container)
    {
        return new static($container->get('filesystem'));
    }


    public function chunkUpload(Request $request, string $route_name, array $options)
    {


        $data = json_decode($request->getContent(), true);

        if (!empty($data["config"])) {

            $max_size = $data["config"]["maxSize"];
            $allowed_types = !empty($data["config"]["allowedTypes"]) ? $data["config"]["allowedTypes"] : ['image/png'];

            $position = $data["position"];
            $uploadKey = $data["uploadKey"];
            $content = $data["data"];
            $name = $data["name"];

            if (!empty($content) && !empty($max_size) && !empty($allowed_types) && !empty($position) && !empty($uploadKey) && !empty($name)) {

                $tempFilePath = "public://upload_tmp";
                if (!is_dir($tempFilePath)) {
                    mkdir($tempFilePath, 0777, true);
                }
                $tempFilePath .= DIRECTORY_SEPARATOR . $uploadKey;

                $result = $this->file_system_service->getFileSystem()->write($tempFilePath, base64_decode($content), FILE_APPEND);

                if ($position === 'start' || $position === 'middle') {
                    return new JsonResponse(['status' => $result]);
                } else {

                    try {
                        $mime_type = mime_content_type($tempFilePath);
                        if (!in_array($mime_type, $allowed_types)) {
                            return new JsonResponse(['msg' => "Validation failed file type is not allowed"]);
                        }

                        $size = filesize($tempFilePath);
                        if ($size > $max_size) {
                            return new JsonResponse(["msg" => "Validation failed file size exceed max size"]);
                        }

                        $date = new \DateTime();
                        $month_year = $date->format("m-Y");
                        $permanent_filename = "public://uploads/$month_year";


                        $ext = $this->file_system_service->getFileSystem()->getExtension($tempFilePath);

                        if (!is_dir($permanent_filename)) {
                            mkdir($permanent_filename, 0777, true);
                        }

                        $index = 0;
                        while(true){
                            $filename = $permanent_filename . DIRECTORY_SEPARATOR . pathinfo($name, PATHINFO_FILENAME) . "_$index." . trim($ext, ".");
                            if (!file_exists($filename)) {
                                $permanent_filename = $filename;
                                break;
                            }
                        }
                        
                        $result = $this->file_system_service->getFileSystem()->move($tempFilePath, $permanent_filename);

                        if ($result) {

                        /**
                         * @var CurrentUser $currentUser
                         */
                        $currentUser = \getAppContainer()->get("current_user");
                        $uid = !empty($currentUser) ? $currentUser->id() : 0;
                        $demission = $this->file_system_service->getFileSystem()->getImageDimession($permanent_filename);

                            $file = new File([
                                'filename' => pathinfo($permanent_filename, PATHINFO_FILENAME),
                                'uri'      => $permanent_filename,
                                'filemime' => mime_content_type($permanent_filename),
                                'filesize' => filesize($permanent_filename),
                                'timestamp' => $date->getTimestamp(),
                                'uid'       => $uid,
                                'title'      => $name,
                                'alt'        => $name,
                                'width'      => $demission['width'],
                                'height'     => $demission['height']

                            ], \getAppContainer()->get("database"));

                            $file->save();

                            $array = [
                                'file' => $file->getFilename(),
                                'size' => $file->getFilesize(),
                                'path' => $file->getPublicUrl(),
                                'id'   => $file->getId(),
                            ];

                            $uploadedFiles = SessionStorage::get($uploadKey) ?? [];
                            $uploadedFiles[] = $array;

                            SessionStorage::add($uploadKey, $uploadedFiles);

                            return new JsonResponse(['msg' => "File successfully saved"]);
                        }
                        unlink($tempFilePath);
                        return new JsonResponse(["msg" => "Failed to save file"]);

                    } catch (Throwable $e) {
                        return new JsonResponse(["msg" => $e->getMessage()]);
                    }

                }
            }


        }

        return new JsonResponse(['msg'=> "Sorry not all parameters are passed"]);
    }

    public function sessionFile(Request $request, string $route_name, array $options)
    {
        $uploadKey = $request->query->get("id");
        if (empty($uploadKey)) {
            return new JsonResponse(["msg"=> ""]);
        }

        $files = SessionStorage::get($uploadKey);
        return new JsonResponse($files);
    }

      public function deleteFile(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get("id");
        if (empty($id)) {
            return new JsonResponse(["msg"=> ""]);
        }

        $file = File::load($id, \getAppContainer()->get("database"));
        if ($file) {
            $file->permanentDelete();
            return new JsonResponse(['status'=>true]);
        }
        
        return new JsonResponse([]);
    }
}
