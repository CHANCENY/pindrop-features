<?php

namespace Simp\Pindrop\Modules\streamer\src\Services;

use Simp\Pindrop\Modules\streamer\src\Config\Config;
use Simp\Pindrop\Modules\streamer\src\Http\Http;

class Authentication
{
    protected Config $config;
    public function __construct(protected Http $http)
    {
        $this->config = $this->http->getConfig();
    }

    public function isAuthenticated(): bool
    {
        $this->http->setHeaders($this->config->get('endpoint.auth.headers'));
        $this->http->request($this->config->get('endpoint.auth.path'));
        $results = $this->http->getResponseBody();
        return $results['success'] ?? false;
    }
}