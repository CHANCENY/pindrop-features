<?php

namespace Simp\Pindrop\Modules\structure\src\Plugin\Session;

class SessionStorage
{
    public static function add(array|string $keyComponent, $value): void
    {
        $name = is_string($keyComponent) ? $keyComponent : implode('_',$keyComponent);
        $_SESSION['structures']['fields'][$name] = $value;
    }

    public static function get(array|string $keyComponent) {
        $name = is_string($keyComponent) ? $keyComponent : implode('_',$keyComponent);
        return !empty($_SESSION['structures']['fields'][$name]) ? $_SESSION['structures']['fields'][$name] : null;
    }

    public static function remove(array|string $keyComponent): void {
        $name = is_string($keyComponent) ? $keyComponent : implode('_',$keyComponent);
        if (!empty($_SESSION['structures']['fields'][$name])) {
            unset($_SESSION['structures']['fields'][$name]);
        }
    }
}