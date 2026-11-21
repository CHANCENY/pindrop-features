<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src;

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Signals;
use Simp\Pindrop\Modules\finance_tracking\src\Preference;
use Simp\Pindrop\Modules\signals_slots\src\Service\SignalBus;
use Simp\Price\lib\Price;
use Throwable;

class ActiveAccount
{
    protected array $account;
    protected User $user;

    protected ActiveAccount $originalActiveAccount;

    public function __construct(protected readonly DatabaseService $databaseService, ?int $account_d)
    {
        if (is_integer($account_d)){
        $this->account = $this->databaseService->table('finance__account')->where('id', '=', $account_d)->first();
        $this->user = User::loadById($this->account['account_owner'], $this->databaseService);
        $this->originalActiveAccount = clone ($this);
        }
    }

    public function getDisplayName(): string
    {
        return $this->account['display_name'];
    }

    public function getAccountType(): string
    {
        return $this->account['account_type'];
    }

    public function id(): int
    {
        return $this->account['id'];
    }

    public function getInstitutionName(): string
    {
        return $this->account['institution_name'];
    }

    public function getAccountEmail(): string
    {
        return $this->account['account_email'];
    }

    public function getAccountPhone(): string
    {
        return $this->account['account_phone'];
    }

    public function getAccountOwner(): User
    {
        return $this->user;
    }

    public function getFirstName(): ?string
    {
        return $this->account['account_first_name'] ?? $this->user->getFirstName();
    }

    public function getLastName(): ?string
    {
        return $this->account['account_last_name'] ?? $this->user->getLastName();
    }

    public function getAccountCurrency(): string
    {
        return $this->account['account_currency'];
    }

    public function getAccountBalance(): Price
    {
        return new Price((float) $this->account['account_balance'], $this->getAccountCurrency());
    }

    public function getAccountTimezone(): string
    {
        return $this->account['account_timezone'];
    }

    public function setAccountOwner(User|int $user): ActiveAccount
    {
        if ($user instanceof User) {
            $this->user = $user;
            $this->account['account_owner'] = $user->getId();
            return $this;
        }

        $this->account['account_owner'] = $user;
        return $this;
    }

    public function setAccountPhone(string $account_phone): ActiveAccount
    {
        $this->account['account_phone'] = $account_phone;
        return $this;
    }

    public function setAccountEmail(string $account_email): ActiveAccount
    {
        $this->account['account_email'] = $account_email;
        return $this;
    }

    public function setInstitutionName(string $institution_name): ActiveAccount
    {
        $this->account['institution_name'] = $institution_name;
        return $this;
    }

    public function setDisplayName(string $display_name): ActiveAccount
    {
        $this->account['display_name'] = $display_name;
        return $this;
    }

    public function setAccountBalance(Price $ammount): ActiveAccount
    {
        $this->account['account_balance'] = $ammount->getAmount();
        return $this;
    }

    public function setFirstName(string $name): ActiveAccount
    {
        $this->account['account_first_name'] = $name;
        return $this;
    }

    public function setLastName(string $name): ActiveAccount
    {
        $this->account['account_last_name'] = $name;
        return $this;
    }

    public function setAccountTimezone(string $zone): ActiveAccount
    {
        $this->account['account_timezone'] = $zone;
        return $this;
    }

    public function setAccountCurrency(string $curr): ActiveAccount
    {
        $this->account['account_currency'] = $curr;
        return $this;
    }

    public function setAccountType(string $type): ActiveAccount
    {
        $this->account['account_type'] = $type;
        return $this;
    }

    public function save(): bool|ActiveAccount
    {
        try {
            if (empty($this->account['id'])) {
                $id = $this->databaseService->table('finance__account')->
                    insert($this->account);
                $this->account['id'] = $id;
                return $this;
            }

            $conversionFlag = false;
            // We are trigger signal for coversion here if currency changes
            if ($this->originalActiveAccount->getAccountCurrency() !== $this->getAccountCurrency()) {
                $conversionFlag = true;
            }

            $this->databaseService->table('finance__account')
                ->where('id', '=', $this->account['id'])
                ->update([
                    'account_email' => $this->getAccountEmail(),
                    'display_name' => $this->getDisplayName(),
                    'institution_name' => $this->getInstitutionName(),
                    'account_phone' => $this->getAccountPhone(),
                    'account_type' => $this->getAccountType(),
                    'account_first_name' => $this->getFirstName(),
                    'account_last_name' => $this->getLastName(),
                    'account_currency' => $this->getAccountCurrency(),
                    'account_timezone' => $this->getAccountTimezone(),
                    'account_balance' => $this->getAccountBalance()

                ]);

            if ($conversionFlag) {
                /** @var SignalBus $bus */
                $bus = \getAppContainer()->get(SignalBus::class);
                $bus->emit(Signals::CURRENCY_CHANGED,[
                    'base_currency' => $this->originalActiveAccount->getAccountCurrency(),
                    'new_currency' => $this->getAccountCurrency()
                ]);
            }

            return $this;
        } catch (Throwable $e) {
            
            return false;
        }
    }

    public function delete(): bool|ActiveAccount
    {
        try {
            if (empty($this->account['id'])) {
                return $this;
            }

            return $this->databaseService->transaction(function () {
                return $this->databaseService->table('finance__account')->where('id', '=', $this->account['id'])->delete() > 0;
            });


        } catch (Throwable $e) {
            return $this;
        }
    }

    /**
     * Get Account Preferences.
     * @return Preference[]
     */
    public function getPreferences(): array
    {
        $preferences = $this->databaseService->table('finance__preference')
            ->where('preference_owner', '=', $this->account['id'])
            ->get();

        return array_map(function (array $preference) {
            return new Preference($this->databaseService, $preference);
        }, $preferences);
    }

    public function countBudgets(): int
    {
        return $this->databaseService->table('finance__budget')
            ->where('account', '=', $this->id())
            ->count();
    }

    public function getBudgets(): array
    {
        $budgets = $this->databaseService->table('finance__budget')
            ->select(['id'])
            ->where('account', '=', $this->id())
            ->orderBy('starting_date', 'ASC')
            ->get();

        return array_map(function ($item) {
            return new Budget($this->databaseService, $item['id']);
        }, $budgets);
    }

    public function isAccountActiveSelectedSession(?string $session_id = null): bool
    {
        $session_id = empty($session_id) ?
            getAppContainer()->get('current_user')?->getSessionId() : $session_id;

        return $this->databaseService->table('finance__account_session')
            ->where('account_id', '=', $this->id())
            ->where('sid', '=', $session_id)
            ->exists();
    }

    public function addPreferences(array $preferences): array|false
    {
        try {
            $this->databaseService->transaction(function () use ($preferences) {
                $this->databaseService->table('finance__preference')->delete();
                foreach ($preferences as $preference) {
                    $this->databaseService->table('finance__preference')
                        ->insert([
                            'preference_name' => $preference,
                            'is_enabled' => true,
                            'preference_owner' => $this->id(),
                        ]);
                }
            });
            return $this->getPreferences();
        } catch (Throwable) {
            return false;
        }
    }

    
}