<?php

namespace Simp\Pindrop\Modules\admin\src\Routes;

use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Routing\AttributeRoute;
use Symfony\Component\HttpFoundation\Request;

class AdminAttributeRouteController extends ControllerBase
{
    #[AttributeRoute('/terms','GET',permission: [])]
    public function terms(Request $request, string $route_name, array $options){
        return $this->render("Terms of site");
    }
}
