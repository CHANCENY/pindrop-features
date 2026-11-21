<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\Modules\finance_tracking\src\Plugin\Events\Events as FinanceEvents;
use Simp\Pindrop\Session\SessionStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EventsSubscriber implements EventsSubscriberInterface
{
    
    public function getSubscribedEvents(): array
    {
        return [
            Events::REQUEST_RECEIVED => [$this, "accountCheck"],
            FinanceEvents::ACCOUNT_NOT_FOUND => [$this, 'accountNotFound']
        ];
    }

    public function accountCheck(EventEmitter $event)
    {
        
    }


    public function accountNotFound(EventEmitter $event)
    {
        $event->__set('redirect', new RedirectResponse('/finances/create-finance-account'));
        SessionStorage::add('finance_tracking.force.redirect', true);
        return $event;
    }
}