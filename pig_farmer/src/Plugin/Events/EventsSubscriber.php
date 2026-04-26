<?php

namespace Simp\Pindrop\Modules\pig_farmer\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsManager;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\Menu\MenuManager;

class EventsSubscriber implements EventsSubscriberInterface
{

    public function getSubscribedEvents(): array
    {
       return [
           Events::MENUS_LOADED => [$this, 'onMenuLoaded'],
           Events::MENUS_ITEMS_RENDERER_READY => [$this, 'onMenuItemsRendererReady'],
       ];
    }

    public function onMenuLoaded(EventEmitter &$event) {

    }

    public function onMenuItemsRendererReady(EventEmitter $event) {
        $menus = &$event->options[0];
        if (is_array($menus)) {

            if (isset($menus['admin']['dashboard'])) {
                unset($menus['admin']['dashboard']);
            }
            if (isset($menus['admin']['content'])) {
                unset($menus['admin']['content']);
            }
            if (isset($menus['admin']['users'])) {
                unset($menus['admin']['users']);
            }
            if (isset($menus['admin']['settings'])) {
                unset($menus['admin']['settings']);
            }
            if (isset($menus['admin']['plugins'])) {
                unset($menus['admin']['plugins']);
            }
        }
        return $menus;
    }
}