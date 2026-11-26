<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Plugin\Events;

use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Events\EventsSubscriberInterface;
use Simp\Pindrop\Events\SystemEvents\Events;
use Simp\Pindrop\Modules\finance_tracking\src\Plugin\Events\Events as FinanceEvents;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Account;
use Simp\Pindrop\Session\SessionStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class EventsSubscriber implements EventsSubscriberInterface
{
    
    public function getSubscribedEvents(): array
    {
        return [
            Events::REQUEST_RECEIVED => [$this, "accountCheck"],
            FinanceEvents::ACCOUNT_NOT_FOUND => [$this, 'accountNotFound'],
            FinanceEvents::ACCOUNT_NOT_SELECTED => [$this, 'accountNotSelected']
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

    public function accountNotSelected(EventEmitter $event)
    {
        try{
        $account = new Account(getAppContainer()->get('database'), getAppContainer()->get('current_user'));
        $active_account = $account->loadActiveAccount();
        if (!$active_account) {
            $redirect = new RedirectResponse('/finances/select-account');
            $redirect->send();
        }
        }catch(Throwable){}

    }
}