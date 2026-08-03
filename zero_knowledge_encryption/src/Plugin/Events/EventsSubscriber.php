<?php

namespace Simp\Pindrop\Modules\zero_knowledge_encryption\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\Logger\LoggerInterface;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\zero_knowledge_encryption\src\Plugin\Events\Events as PluginEvents;
use Simp\Pindrop\Modules\zero_knowledge_encryption\src\Services\ZeroKnowledgeEncryption;
use Simp\Pindrop\Modules\zero_knowledge_encryption\src\Services\ZeroKnowledgeEncryptionTableManager;
use Simp\Pindrop\Routing\Url;
use Simp\Pindrop\Settings\Settings;

class EventsSubscriber implements EventsSubscriberInterface
{
    protected LoggerInterface $logger;
    protected Settings $settings;

    protected ZeroKnowledgeEncryptionTableManager $zeroTableManager;
    protected ZeroKnowledgeEncryption $zeroKnowledgeEncryptionService;

    public function __construct()
    {
        $this->logger = getAppContainer()->get('logger');
        $this->settings = new Settings(getAppContainer()->get('database'));
        $this->zeroTableManager = new ZeroKnowledgeEncryptionTableManager(
            getAppContainer()->get('plugin.manager'));

        $this->zeroKnowledgeEncryptionService = new ZeroKnowledgeEncryption(
            getAppContainer()->get('current_user')
        );
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::PLUGIN_INSTALLED => [$this, 'onPluginInstalled'],
            Events::PLUGIN_UNINSTALLED => [$this, 'onPluginUninstalled'],
            PluginEvents::DB_SAVE_DATA => [$this, 'onDbSaveData'],
            PluginEvents::DB_GET_DATA => [$this, 'onDbGetData'],
        ];
    }

    public function onPluginInstalled(EventEmitter $event) {

        $data = $this->settings->getSetting('admin.settings')->getValue();
        $data['login_redirect'] ="/zero-encryption/login (zero_knowledge_encryption.login)";
        $this->settings->singleUpdate('admin.settings', $data);
        Message::info('Zero Knowledge Encryption plugin installed successfully.');
        $this->logger->info('Zero Knowledge Encryption plugin installed successfully.');
    }

    public function onPluginUninstalled(EventEmitter $event) {

        $data = $this->settings->getSetting('admin.settings')->getValue();
        $data['login_redirect'] = Url::routeByName('user.login'). "(user.login)";
        $this->settings->singleUpdate('admin.settings', $data);
        Message::info('Zero Knowledge Encryption plugin uninstalled successfully.');
        $this->logger->info('Zero Knowledge Encryption plugin uninstalled successfully.');

    }

    public function onDbSaveData(EventEmitter $event) {
        $data = $event->raw;
        $columns = [];
        $tables = $data['table'] ?? [];
        $values = $data['data'];

        foreach ($tables as $table) {
            $columns = array_merge($columns, $this->zeroTableManager->getTableColumns($table) ?? []);
        }

        
        if (empty($columns)) {
            return;
        }

        $data = $this->zeroKnowledgeEncryptionService->encryptDataColumnData($values, $columns);
       
       // dump($data);
        return $data;
    }

    public function onDbGetData(EventEmitter $event) {
        $data = $event->raw;
        $columns = [];
        $tables = $data['table'] ?? [];
        $values = $data['data'];

        foreach ($tables as $table) {
            $columns = array_merge($columns, $this->zeroTableManager->getTableColumns($table) ?? []);
        }

        
        if (empty($columns)) {
            return;
        }

        $data = $this->zeroKnowledgeEncryptionService->decryptDataColumnData($values, $columns);
        return $data;
    }
}
