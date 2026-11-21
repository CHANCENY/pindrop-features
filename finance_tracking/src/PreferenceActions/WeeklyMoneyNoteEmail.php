<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\finance_tracking\src\PreferenceActions;

use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Preference;

class WeeklyMoneyNoteEmail implements PreferenceActionInterface
{
    
    public static function execute(Preference $preference): void
    {
        $message = "<p>Hello! This is your weekly money note email. Here are your financial updates for the week.</p>";
        $message .= "<h2>Account Details:</h2>";

        $account = new ActiveAccount(getAppContainer()->get("database"), $preference->getPreferenceOwner());

        $message .= "<p>Name: ". $account->getDisplayName() ."<br>";
        $message .= "Email: ". $account->getAccountEmail() ."<br>";
        $message .= "Phone: ". $account->getAccountPhone() ."<br>";
        $message .= "Account Type: ". $account->getAccountType() ."<br>";
        $message .= "Inst Name: ". $account->getInstitutionName() ."<br>";
        $message .= "Currency: ". $account->getAccountCurrency() ."<br>";
        $message .= "Balance: ". $account->getAccountBalance() ."<br>";
        $message .= "Timezone: ". $account->getAccountTimezone() ."<br>";
        $message .= "</p>";

        $message .= "<p>Thank you for using our finance tracking service!</p>";

        $plainTextMessage = strip_tags($message);

        $mail = getAppContainer()->get("mail.manager");
        $mail->sendHtml(
            $account->getAccountEmail(),
            "Weekly Money Note",
            $message,
            $plainTextMessage,
        );

    }
}