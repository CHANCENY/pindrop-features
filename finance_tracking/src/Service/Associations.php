<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Service;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;

class Associations
{
    public function __construct(protected readonly DatabaseService $database)
    {
        
    }

    /**
     * Get Users of finance manager role
     * @return User[]
     */
    public function getManagers(): array
    {
        $managers = $this->database->table('users')
        ->where('role','=', 'finance_manager')
        ->where('status', '=', 'active')
        ->where('profile_visibility', '=', 'public')
        ->where('is_verified', '=', true)
        ->get();

        return array_map(function ($user) {
            return User::loadById($user['id'], $this->database);
        }, $managers);
    }

    /**
     * Whether the given user is one of the public, verified finance managers.
     */
    public function isAvailableManager(int $userId): bool
    {
        foreach ($this->getManagers() as $manager) {
            if ($manager instanceof User && (int) $manager->getId() === $userId) {
                return true;
            }
        }
        return false;
    }

    public function isAssociated(User $manager, ActiveAccount $activeAccount): bool
    {
        return $this->database->table('finance__association')
            ->where('user_id', '=', $manager->getId())
            ->where('account_id', '=', $activeAccount->id())
            ->count() > 0;
    }

    /**
     * Managers that currently have access to the given account.
     * @return User[]
     */
    public function getAssociatedManagers(ActiveAccount $activeAccount): array
    {
        $rows = $this->database->table('finance__association')
            ->where('account_id', '=', $activeAccount->id())
            ->get();

        $managers = array_map(function (array $row) {
            return User::loadById((int) $row['user_id'], $this->database);
        }, $rows);

        return array_values(array_filter($managers, fn($m) => $m instanceof User));
    }

    public function grantAccountAccess(User $manager, ActiveAccount $activeAccount): bool
    {
        if ($this->isAssociated($manager, $activeAccount)) {
            return true;
        }

        return (bool) $this->database->table('finance__association')
            ->insert([
                'user_id' => $manager->getId(),
                'account_id' => $activeAccount->id()
            ]);
    }

    public function revokeAccountAccess(User $manager, ActiveAccount $activeAccount): bool
    {
        $this->database->table('finance__association')
            ->where('user_id', '=', $manager->getId())
            ->where('account_id', '=', $activeAccount->id())
            ->delete();

        return !$this->isAssociated($manager, $activeAccount);
    }

    /**
     * Accounts a manager has been given access to.
     * @return ActiveAccount[]
     */
    public function getMyAssociatedAccounts(User $manager): array
    {
        $rows = $this->database->table('finance__association')
            ->where('user_id', '=', $manager->getId())
            ->get();

        return array_values(array_filter(array_map(function (array $row) {
            try {
                return new ActiveAccount($this->database, (int) $row['account_id']);
            } catch (\Throwable) {
                return null;
            }
        }, $rows)));
    }
}
