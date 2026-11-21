<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\Service;


use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Events\EventEmitter;
use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Exceptions\ActiveAccountException;
use Simp\Pindrop\Modules\finance_tracking\src\Plugin\Events\Events;
use Simp\Pindrop\Session\SessionStorage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class Account
{
    public function __construct(protected readonly DatabaseService $databaseService,
     protected readonly ?CurrentUser $currentUser){}


    public function loadActiveAccount(): ?ActiveAccount
    {
        $activeAccount = $this->databaseService->table("finance__account_session")
        ->where("sid", "=", $this->currentUser?->getSessionId())
        ->orderBy("created_at","DESC")
        ->first();

        if ($activeAccount) {
            return new ActiveAccount($this->databaseService,$activeAccount['account_id']);
        }

        // we need to fire event here of account not found if redirect can be ordered
        /**
         * @var EventEmitter
         */
        $event_response = appEvents()->invokeEvents(Events::ACCOUNT_NOT_FOUND);
        $redirect = $event_response->__get('redirect');
        if ($redirect instanceof RedirectResponse && SessionStorage::get('finance_tracking.force.redirect') === true &&
           !SessionStorage::get('finance_tracking.force.redirect.lock')) {
            SessionStorage::add('finance_tracking.force.redirect.lock', true);
            $redirect->send();
        }
        
        return null;
    }

    public function isAccountAvailable(): bool
    {
        return $this->databaseService->table('finance__account')
        ->where('account_owner','=', $this->currentUser->getUser()->getId())
        ->count() > 0;
    }

    public function isActiveAccountSet(): bool
    {
        return $this->databaseService->table('finance__account_session')
        ->where('sid','=', $this->currentUser->getSessionId())
        ->count() > 0;
    }

    /**
     * Accounts available to the current user as ActiveAccount objects:
     * accounts they own plus accounts they were associated with as a manager.
     * @return ActiveAccount[]
     */
    public function getAccounts(): array
    {
        $userId = $this->currentUser->getUser()->getId();

        $owned = $this->databaseService->table('finance__account')
        ->select(['id'])
        ->where('account_owner','=', $userId)
        ->get();

        $associated = $this->databaseService->table('finance__association')
        ->select(['account_id'])
        ->where('user_id','=', $userId)
        ->get();

        $ids = array_unique(array_merge(
            array_map(fn(array $row) => (int) $row['id'], $owned),
            array_map(fn(array $row) => (int) $row['account_id'], $associated)
        ));

        return array_values(array_filter(array_map(function(int $id){
            try {
                return new ActiveAccount($this->databaseService, $id);
            } catch (Throwable) {
                return null;
            }
        }, $ids)));
    }

    /**
     * Whether the current user owns the account or is an associated manager of it.
     */
    public function canAccessAccount(int $accountId): bool
    {
        $userId = $this->currentUser->getUser()->getId();

        $owned = $this->databaseService->table('finance__account')
        ->where('id', '=', $accountId)
        ->where('account_owner', '=', $userId)
        ->count();

        if ($owned > 0) {
            return true;
        }

        return $this->databaseService->table('finance__association')
        ->where('account_id', '=', $accountId)
        ->where('user_id', '=', $userId)
        ->count() > 0;
    }

    public function createAccount(string $display_name,
    string $account_currency, string $account_type, string $account_email, 
    string $account_phone, string $account_timezone, string $institution_name ): ActiveAccount|bool
    {
        $account = [
            'display_name' => $display_name,
            'institution_name' => $institution_name,
            'account_currency' => $account_currency,
            'account_email'    => $account_email,
            'account_phone'    => $account_phone,
            'account_timezone' => $account_timezone,
            'account_type'     => $account_type,
            'account_owner'    => $this->currentUser->getUser()->getId(),
        ];

        $id = $this->databaseService->table('finance__account')
        ->insert($account);
        return new ActiveAccount($this->databaseService, $id);
    }

    public function setActiveAccountSession(int $account): bool 
    {
        $this->databaseService->table('finance__account_session')
        ->where('sid', '=', $this->currentUser->getSessionId())
        ->delete();

        return $this->databaseService->table('finance__account_session')
        ->insert([
            'sid' => $this->currentUser->getSessionId(),
            'account_id' => $account
        ]) > 0;
    }

    public function getAccountWithAssociations(): array
    {
        $accounts = $this->databaseService->table('finance__account')
        ->select(['finance__account.id AS id'])
        ->leftJoin('finance__association', 'finance__association.account_id','=', 'finance__account.id')
        ->where('finance__account.account_owner', '=', $this->currentUser->getUser()->getId())
        ->orderBy('finance__account.account_balance', 'DESC')
        ->get();

        $ids = array_unique(array_map(fn(array $account) => (int) $account['id'], $accounts));

        return array_filter(array_map(function(int $id){
            try{
                return new ActiveAccount($this->databaseService, $id);
            }catch(Throwable){
                return null;
            }
        }, $ids));
    }
    
}