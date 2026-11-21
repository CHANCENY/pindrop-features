<?php

namespace Simp\Pindrop\Modules\finance_tracking\src\PreferenceActions;


use Simp\Pindrop\Modules\finance_tracking\src\ActiveAccount;
use Simp\Pindrop\Modules\finance_tracking\src\Preference;
use Simp\Pindrop\Modules\finance_tracking\src\PreferenceActions\PreferenceActionInterface;

class LowBalanceActionEmail implements PreferenceActionInterface
{
    public static function execute(Preference $preference): void
    {
        $account = new ActiveAccount(getAppContainer()->get("database"), $preference->getPreferenceOwner());

        $message = "<p>Dear {$account->getAccountOwner()->getFirstName()},</p>";
        $message .= "<p>We wanted to inform you that your account balance has fallen below the threshold you set in your preferences. Please review your account to ensure that you are aware of your current financial status.</p>";
        $message .= "<p>Thank you for using our services.</p>";

        $subject = "Low Balance Alert for Your Account";
        $plainTextMessage = "Dear {$account->getAccountOwner()->getFirstName()},\n\nWe wanted to inform you that your account balance has fallen below the threshold you set in your preferences. Please review your account to ensure that you are aware of your current financial status.\n\nThank you for using our services.";

        $mailService = getAppContainer()->get("mail.manager");
        $mailService->sendHtml(
            $account->getAccountEmail(),
            $subject,
            $message,
            $plainTextMessage,
        );
    }
}