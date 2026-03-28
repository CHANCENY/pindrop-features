<?php

namespace Simp\Pindrop\Modules\admin\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Message\Message;

class EventsSubscriber implements EventsSubscriberInterface
{

    public function getSubscribedEvents(): array
    {
        return [
            Events::ADMIN_LOGGED_IN => [$this, 'onAdminLoggedIn'],
            \Simp\Pindrop\Events\SystemEvents\Events::ENTITY_SAVED => [$this, 'onEntitySaved'],
        ];
    }

    public function onAdminLoggedIn(EventEmitter $event) {

    }

    public function onEntitySaved(EventEmitter $event) {
        Message::info($event->entity->getTitle() . ' saved successfully');
    }
}