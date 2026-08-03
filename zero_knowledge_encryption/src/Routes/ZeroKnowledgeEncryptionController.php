<?php

namespace Simp\Pindrop\Modules\zero_knowledge_encryption\src\Routes;

use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\Database;
use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\zero_knowledge_encryption\src\Services\ZeroKnowledgeEncryption;
use Simp\Pindrop\Routing\AttributeRoute;
use Symfony\Component\HttpFoundation\Request;

class ZeroKnowledgeEncryptionController extends ControllerBase
{
    #[AttributeRoute('/zero-encryption/login', ['POST', 'GET'],  name: 'zero_knowledge_encryption.login')]
    public function encryptionLogin(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $key = $request->request->get('encryption_key');
            $skip_encryption = $request->request->get('skip_encryption');

            if ($skip_encryption) {
                return $this->redirect('/');
            }

            if (empty($key)) {
                return $this->renderTwig("@zero_knowledge_encryption/zero/login.html.twig", [
                    'error' => 'Encryption key is required.',
                ]);
            }

            if (strlen($key) < 8) {
                return $this->renderTwig("@zero_knowledge_encryption/zero/login.html.twig", [
                    'error' => 'Encryption key must be at least 8 characters long.',
                ]);
            }

            /**
             * @var ZeroKnowledgeEncryption
             */
            $zeroKnowledgeEncryptionService = $this->getService('zero_knowledge_encryption.service');
            if ($zeroKnowledgeEncryptionService->setZeroKnowledgeEncryptionKey($key)) {
                Message::info('Zero Knowledge Encryption key set successfully.');
                return $this->redirect('/');
            } else {
                return $this->renderTwig("@zero_knowledge_encryption/zero/login.html.twig", [
                    'error' => 'Failed to set encryption key.',
                ]);
            }
           

        }
        return $this->renderTwig("@zero_knowledge_encryption/zero/login.html.twig");
    }

    #[AttributeRoute('/zero-encryption/sample', ['GET', 'POST'],  name: 'zero_knowledge_encryption.sample')]
    public function sampleAction(Request $request, string $route_name, array $options)
    {

        /**
         * @var DatabaseService
         */
        $databaseService = $this->getService('database');

        if ($request->isMethod('POST')) {
            $title = $request->request->get('title');
            $content = $request->request->get('content');

            
            $queryBuilder = $databaseService->table('zero_knowledge_encryption');
            $queryBuilder->delete();

            $data = [
                'name' => $title,
                'content' => $content,
                'uid' => $this->getService('current_user')->getUserId(),
            ];

            $queryBuilder->insert($data);

            // Here you would typically save the data to the database
            // For demonstration, we'll just display a success message
            Message::info('Data saved successfully. Title: ' . htmlspecialchars($title) . ', Content: ' . htmlspecialchars($content));
        }

        $queryBuilder = $databaseService->table('zero_knowledge_encryption');
        $result = $queryBuilder->first();
        return $this->renderTwig("@zero_knowledge_encryption/zero/sample.html.twig", ['result' => $result]);
    }

}
