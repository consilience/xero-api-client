<?php

namespace Consilience\XeroApi\Oauth1;

/**
 * Object for representing a complete endpoint on the Xero APIs.
 * It also handles special URLs that may be passed in, e.g.
 * OAuth related ones.
 */

use Psr\Http\Message\UriInterface;
use Psr\Http\Message\UriFactoryInterface;

use Consilience\XeroApi\Client\HttpTrait;

class Endpoint
{
    use HttpTrait;

    /**
     * The default base URL.
     */
    const BASE_URL = 'https://api.xero.com/oauth';

    /**
     * Resources for OAuth requests.
     */
    const PATH_REQUEST_TOKEN    = 'RequestToken';
    const PATH_REDIRECT_USER    = 'Authorize';
    const PATH_ACCESS_TOKEN     = 'AccessToken';
    const PATH_REFRESH_TOKEN    = 'AccessToken';

    /**
     * @var string
     */
    protected $baseUrl = self::BASE_URL;

    /**
     *
     */
    public function __construct(UriFactoryInterface $uriFactory = null)
    {
        if ($uriFactory) {
            $this->uriFactory = $uriFactory;
        }
    }

    /**
     * @param string $action
     * @return UriInterface
     */
    public function getUri(string $action): UriInterface
    {
        return $this->getUriFactory()->createUri(
            $this->getUrl($action)
        );
    }

    /**
     * @param string $action
     * @return string
     */
    public function getUrl(string $action): string
    {
        return sprintf('%s/%s', $this->baseUrl, $action);
    }

    // The following convenience methods make switching between endpoints
    // quick and convenient.

    /**
     * Get the OAuth Request Endpoint
     */
    public function getRequestTokenUri(): UriInterface
    {
        return $this->getUri(static::PATH_REQUEST_TOKEN);
    }

    /**
     * Get the OAuth Redirect user Endpoint
     */
    public function getRedirectUserUri(): UriInterface
    {
        return $this->getUri(static::PATH_REDIRECT_USER);
    }

    /**
     * Get the OAuth Access Token Endpoint
     */
    public function getAccessTokenUri(): UriInterface
    {
        return $this->getUri(static::PATH_ACCESS_TOKEN);
    }

    /**
     * Get the OAuth Refresh Endpoint
     */
    public function getRefreshTokenUri(): UriInterface
    {
        return $this->getUri(static::PATH_REFRESH_TOKEN);
    }
}
