<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\Plugin\Twig;

use Override;
use Simp\Pindrop\FileSystem\FileSystemService;
use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Preference as ConstantPreference;
use Simp\Pindrop\Modules\finance_tracking\src\Preference;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Account;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Transactions;
use Simp\Pindrop\Modules\finance_tracking\src\Transaction;
use Simp\Price\lib\Price;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    
    protected Account $account;

    public function __construct()
    {
        $this->account = new Account(getAppContainer()->get('database'), getAppContainer()->get('current_user'));
    }
    public function getFunctions()
    {
        return [
            new TwigFunction('session_acount', [$this, 'getSessionAccount']),
            new TwigFunction('is_account_selected', [$this,'isAccountSelected']),
            new TwigFunction('transaction_count', [$this, "transactionCount"])
        ];
    }

    #[Override]
    public function getFilters()
    {
        return [
            new TwigFilter('is_prefered', [$this, 'isPreferenceChecked']),
            new TwigFilter('price', [$this, 'price']),
            new TwigFilter('create_uri', [$this, 'create_uri'])
        ];
    }

    public function getSessionAccount(): ActiveAccount
    {
        return $this->account->loadActiveAccount();
    }

    public function isAccountSelected(): bool
    {
        return $this->account->isActiveAccountSet();
    }

    public function isPreferenceChecked(string $preference_name, ?array $preferences): bool
    {
        if (!$preferences) return false;
        /**
         * @var  Preference $preference
         */
        foreach ($preferences as $preference)
        {
            if ($preference->getIsEnabled() && $preference->getName() === $preference_name){
                return true;
            }
        }
        
        return false;
    }

    public function price(float|int|string $amount): string
    {
        return (new Price((float) $amount, $this->account->loadActiveAccount()->getAccountCurrency()))->format();
    }

    public function transactionCount(): int
    {
        return (new Transactions(getAppContainer()->get('database'), $this->account))->count();
    }

    public function create_uri(?string $uri): string
    {
        if (empty($uri)) return "";
        /**
         * @var FileSystemService
         */
        $file_service = getAppContainer()->get('filesystem');
        return $file_service->getFileSystem()->getPublicUrl($uri);
    }
}