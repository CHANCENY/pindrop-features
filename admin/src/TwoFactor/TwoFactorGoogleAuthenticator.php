<?php

namespace Simp\Pindrop\Modules\admin\src\TwoFactor;

use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\FactorAuthentication\TwoFactorAuthentication;
use Simp\Pindrop\FactorAuthentication\TwoFactorAuthenticationInterface;
use Simp\Pindrop\FactorAuthentication\TwoFactorInterface;
use Simp\Pindrop\Routing\Url;
use Twig\Markup;

class TwoFactorGoogleAuthenticator implements TwoFactorInterface
{
    public function getName(): string
    {
        return 'Google Authenticator';
    }

    public function getDescription(): string
    {
        return 'Use Google Authenticator for two-factor authentication.';
    }

    public function key(): string
    {
        return 'google_authenticator';
    }

    public function redirectLink(): string
    {
        return Url::routeByName('admin.twofactor',[], true);
    }

    public function form(User $user): Markup
    {
        return new Markup(getAppContainer()->get('twig')->
        render("@admin/twofactor/twofactor_form.html.twig"), 'UTF-8');
    }

    public function userEnablingForm(User $user, array $options = []): Markup
    {
        return new Markup(getAppContainer()->get('twig')->
        render("@admin/twofactor/user_enabling_form.html.twig", ['user' => $user,...$options]), 'UTF-8');
    }

    public function twoFactor(): TwoFactorAuthenticationInterface
    {
        return new TwoFactorAuthentication();
    }

    public function verify(\Symfony\Component\HttpFoundation\Request $request, User $user): bool
    {
        $code = $request->request->get('code');
        $secret = $user->getTwoFactorSecret();
        $two = $this->twoFactor();

        $two->setSecret($secret);
        $two->setEmail($user->getEmail());

        return $this->twoFactor()->verify($code);
    }

}
