<?php

namespace Simp\Pindrop\Modules\admin\src\Middleware;

use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Routing\Url;
use Simp\Router\middleware\access\Access;
use Simp\Router\middleware\interface\Middleware;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware implements Middleware
{


    public function __invoke(Request $request, Access $access_interface, $next)
    {
        $options = $access_interface->options;
        $required_permissions = $options['options']['_permissions'] ?? [];

        if (empty($required_permissions)) {
            $access_interface->access_granted = true;
            return $next($request, $access_interface);
        }

        /**
         * @var CurrentUser  $currentUser|null
         **/
        $currentUser = getAppContainer()->get('current_user');

        if ($currentUser === null) {

            if (empty($required_permissions)) {
                $access_interface->access_granted = true;
                return $next($request, $access_interface);
            }

            $access_interface->access_granted = false;
            $access_interface->redirect = new RedirectResponse(Url::routeByName('user.login'));
            return $next($request, $access_interface);
        }

        if ($currentUser->getUser()->isAdmin()) {
            $access_interface->access_granted = true;
            return $next($request, $access_interface);
        }

        // dd(empty(array_intersect($required_permissions, $currentUser->getUser()->getPermissions())));

        if (empty(array_intersect($required_permissions, $currentUser->getUser()->getPermissions()))) {
            $access_interface->access_granted = false;
            $access_interface->response = new Response("Access denied");
            return $next($request, $access_interface);
        }

        $access_interface->access_granted = true;
        return $next($request, $access_interface);
    }
}
