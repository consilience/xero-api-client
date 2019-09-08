<?php

namespace Consilience\XeroApi\Client\App;

/**
 * Xero Private Application client.
 * A decorator for a PSR-18 HTTP client.
 * Uses 2-legged OAuth, so no user authentication
 * needed. Also no token renewals.
 */

//use Consilience\XeroApi\Client\Oauth1\Token;
use Consilience\XeroApi\Client\AbstractClient;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AppPrivate extends AbstractClient
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $this->signRequest($request, static::REQUEST_METHOD_HEADER);
        $response = $this->getClient()->sendRequest($request);

        return $response;
    }
}
