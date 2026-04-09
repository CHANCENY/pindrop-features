<?php

namespace Simp\Pindrop\Modules\structure\src\Entity;

use Psr\Container\ContainerInterface;

class Node extends NodeEntity {

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
    }
}