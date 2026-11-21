<?php

declare(strict_types=1);


namespace Simp\Pindrop\Modules\finance_tracking\src\Routes;

use DateTime;
use DI\Container;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\AccountType;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Category;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\Preference;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\TimezoneHelper;
use Simp\Pindrop\Modules\finance_tracking\src\Constant\TransactionCategory;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Account;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Budgets;
use Simp\Pindrop\Modules\finance_tracking\src\Service\Transactions;
use Simp\Pindrop\Modules\finance_tracking\src\Transaction;
use Simp\Pindrop\Routing\AttributeRoute;
use Simp\Price\lib\Currency;
use Symfony\Component\HttpFoundation\Request;


class FinanceController extends ControllerBase
{
    
    public function __construct(protected Account $account, protected Budgets $budgets, protected Transactions $transactions)
    {
        parent::__construct();
    }

    public static function create(Container $container): static
    {
        return new static(
            $container->get(Account::class),
            $container->get(Budgets::class),
            $container->get(Transactions::class)
        );
    }

    public static function nav(string $name): array
    {
        return [
            'Overview' => [
                'title' => 'Overview'
            ],
            'CreateAccount' => [
                'title' => 'Create finance account'
            ],
            'SelectorAccount' => [
                'title' => 'Account selector'
            ]
        ][$name];
    }


    #[AttributeRoute('/finances', ['GET', 'POST'], permission: ['can_access_overview'])]
    public function _financeIndex(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig("@finance_tracking/index.html.twig",[
            'nav' => self::nav('Overview')
        ]);
    }

    #[AttributeRoute('/finances/select-account', ['GET', 'POST'],permission: ['can_manage_own_finance_accounts','can_manage_other_finance_accounts'])]
    public function _financeAccountSelect(Request $request, string $route_name, array $options)
    {
        $accounts = $this->account->getAccounts();
        return $this->renderTwig("@finance_tracking/finance-accounts.html.twig",[
            'nav' => self::nav('SelectorAccount'),
            'accounts'  => $accounts
        ]);
    }

    #[AttributeRoute('/finances/selection/[id:int]',['GET'], permission: ['can_manage_own_finance_accounts','can_manage_other_finance_accounts'])]
    public function _financeAccountSelection(Request $request, string $route_name, array $options)
    {
        $account = (int) $request->query->get('id');

        // Only the owner or an associated manager may open an account.
        if (!$this->account->canAccessAccount($account)) {
            return $this->redirect('/finances/select-account');
        }

        $this->account->setActiveAccountSession($account);
        return $this->redirect('/finances');
    }

    #[AttributeRoute('/finances/accounts', ['GET'], permission: ['can_manage_own_finance_accounts','can_manage_other_finance_accounts'])]
    public function _financeAccounts(Request $request, string $route_name, array $options)
    {
        $accounts = $this->account->getAccountWithAssociations();
        return $this->renderTwig("@finance_tracking/accounts.html.twig",[
            'accounts' => $accounts
        ]);
    }

    #[AttributeRoute('finances/settings', ['GET', 'POST'], permission: ['can_manage_preferences'])]
    public function _setting(Request $request, string $route_name, array $options)
    {
        $account = $this->account->loadActiveAccount();
        $preferences = $account?->getPreferences();
        $preferencesList = Preference::all();
        $timezones    = TimezoneHelper::all();
        $currencies = new Currency();
        $list = $currencies->getCurrencyGroup();

        if ($request->isMethod(Request::METHOD_POST))
         {
            $submitted = $request->request->all();
            
            $account?->setFirstName($submitted['first_name']);
            $account?->setLastName($submitted['last_name']);
            $account?->setAccountEmail($submitted['email']);
            $account?->setAccountTimezone($submitted['timezone']);
            $account?->setAccountCurrency($submitted['currency']);
            $account?->addPreferences($submitted['preferences']);
            $account?->save();
            return $this->redirect('/finances/settings');
         }   
        
        return $this->renderTwig("@finance_tracking/settings.html.twig",[
            'account'  =>  $account,
            'preferences'  => $preferences,
            'preferences_list' => $preferencesList,
            'currencies' => $list,
            'timezones'  => $timezones
        ]);
    }

    #[AttributeRoute('finances/budgets',['GET'], permission: ['can_manage_own_budgets','can_manage_other_budgets'])]
    public function _budgets(Request $request, string $route_name, array $options)
    {
        $account = $this->account->loadActiveAccount();
        $budgets = $account?->getBudgets();
        return $this->renderTwig("@finance_tracking/budgets.html.twig",[
            'budgets'  =>  $budgets,
        
        ]);
    }


    #[AttributeRoute('/finances/new-budget',['GET', 'POST'], permission: ['can_manage_own_budgets'])]
    public function _budgetCreate(Request $request, string $route_name, array $options)
    {
        $categories = Category::all();
        $account = $this->account->loadActiveAccount();

        if ($request->isMethod(Request::METHOD_POST))
        {
            $submitted = $request->request->all();
            
            $budget = $this->budgets->createBudget(
                $account,
                $submitted['name'],
                $submitted['limit'],
                $submitted['category'],
                (new DateTime($submitted['month']))->format('d-m-y'),
                $submitted['cadence'],
                $submitted['alert']
            );
            return $this->redirect('/finances/budgets');
        }    
        return $this->renderTwig("@finance_tracking/new-budget.html.twig",[
            'categories'  => $categories
        ]);
    }

    #[AttributeRoute('/finances/transactions',['GET'], permission: ['can_manage_other_transactions','can_manage_own_transactions'])]
    public function _transactions(Request $request, string $route_name, array $options)
    {
        $transactions = $this->transactions->getTransactions();
        $stats = $this->transactions->getStatistics();
       
        return $this->renderTwig("@finance_tracking/transactions.html.twig",[
            'transactions' => $transactions,
            'stats' => $stats,
        ]);
    }

     #[AttributeRoute('/finances/transactions/add-transaction',['GET', 'POST'], permission: ['can_manage_other_transactions','can_manage_own_transactions'])]
    public function _transactionsAddNew(Request $request, string $route_name, array $options)
    {
        
        $categories = TransactionCategory::all();
        $budgets    = $this->budgets->getAccountBudgets($this->account->loadActiveAccount());

        if ($request->isMethod(Request::METHOD_POST)) {
            $submitted = $request->request->all();
            $t = $this->transactions->create([
                'transaction_type' => $submitted['type'],
                'merchant' => $submitted['merchant'],
                'amount' => (float) $submitted['amount'],
                'transaction_date' => $submitted['date'],
                'budget' => !empty($submitted['budget']) ? (int) $submitted['budget'] : null,
                'category' => $submitted['category'],
                'reference_note' => $submitted['reference'] ?? null,
                'notes'  => $submitted['notes'] ?? null

            ]);
        
            return $this->redirect('/finances/transactions');
        }

        return $this->renderTwig("@finance_tracking/add-transactions.html.twig",[
            'categories' => $categories,
            'budgets' => $budgets
        ]);
    }

    #[AttributeRoute('/finances/transaction/[id:int]', ['GET', 'POST'], permission: ['can_manage_other_transactions','can_manage_own_transactions'])]
    public function _transactionView(Request $request, string $route_name, array $options)
    {
        $id = $request->query->getInt('id');
        if (!$id) {
            return $this->redirect('/finances/transactions');
        }
        $transaction = new Transaction(getAppContainer()->get('database'), $id);
        return $this->renderTwig("@finance_tracking/view-transaction.html.twig",[
            'transaction' => $transaction,
            'account' => $transaction->account,
            'transactions' => $transaction->getTransactionWithSameReference()

        ]);
    }


    #[AttributeRoute('/finances/transaction/[id:int]/delete', ['GET', 'POST'], permission: ['can_manage_other_transactions','can_manage_own_transactions'])]
    public function _transactionDelete(Request $request, string $route_name, array $options)
    {
        $id = $request->query->getInt('id');
        if (!$id) {
            return $this->redirect('/finances/transactions');
        }
        $transaction = new Transaction(getAppContainer()->get('database'), $id);

        if ($request->query->get('a') === 'yes') {
            $transaction->delete();
            return $this->redirect('/finances/transactions');
        }
        return $this->renderTwig("@finance_tracking/delete-transaction.html.twig",[
            'transaction' => $transaction
        ]);
    }


}