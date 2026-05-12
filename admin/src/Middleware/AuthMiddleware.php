<?php

namespace Simp\Pindrop\Modules\admin\src\Middleware;

use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Router\middleware\access\Access;
use Simp\Router\middleware\interface\Middleware;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class AuthMiddleware implements Middleware
{


    public function __invoke(Request $request, Access $access_interface, $next)
    {
        $options = $access_interface->options;
        $security_permissions = $options['options']['_permissions'] ?? [];
        $security_permissions = array_filter($security_permissions, function ($permission) {
            return $permission === true;
        });
        $permitted_roles = array_map(function ($permission) { 
            return substr($permission, 0, strpos($permission, '___'));  
        }, array_keys($security_permissions));

        /**@var CurrentUser  $currentUser|null **/
        $currentUser = getAppContainer()->get('current_user');
        
        // Handle case where CurrentUser service is not available
        if ($currentUser === null) {
            // If no permissions are required, allow anonymous access
            if (empty($permitted_roles) || in_array('guest', $permitted_roles)) {
                $access_interface->access_granted = true;
                return $next($request, $access_interface);
            }

            // If permissions are required but no CurrentUser service, deny access
            $access_interface->access_granted = false;
            $access_interface->redirect = new RedirectResponse('/user/login');
            return $next($request, $access_interface);
        }
        
        $user = $currentUser->getUser();

        // If no permissions are required, only allow anonymous users
        if (empty($permitted_roles)) {
            if (!$user || !$user?->getId()) {
                // Anonymous user - grant access to public routes
                $access_interface->access_granted = true;
                return $next($request, $access_interface);
            } else {
                // Authenticated user - deny access to public-only routes
                $access_interface->access_granted = false;
                $access_interface->redirect = new RedirectResponse('/');
                return $next($request, $access_interface);
            }
        }

        // For routes with permissions, check if user is authenticated
        if (!$user || !$user->getId()) {
            $access_interface->access_granted = false;
            $access_interface->redirect = new RedirectResponse('/user/login');
            return $next($request, $access_interface);
        }

        // Check if user has any of the required roles
        if (!empty($permitted_roles)) {
            $user_roles = $user->getRole();
            $has_permission = false;

            foreach ($permitted_roles as $role) {
                if (in_array($role, [$user_roles])) {
                    $has_permission = true;
                    break;
                }
            }

            if (!$has_permission) {
                $access_interface->access_granted = false;
                $access_interface->redirect = new RedirectResponse('/user/login');
                return $next($request, $access_interface);
            }
        }

        // User is authenticated and has required permissions
        $access_interface->access_granted = true;
        return $next($request, $access_interface);
    }
}