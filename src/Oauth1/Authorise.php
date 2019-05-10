<?php

namespace Consilience\XeroApi\Client\Oauth1;

/**
 * Client for performing the initial authorisation.
 */

use Consilience\XeroApi\Client\Oauth1\Endpoint;
use Consilience\XeroApi\Client\AbstractClient;
use Consilience\XeroApi\Client\Oauth1\Token;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Authorise extends AbstractClient
{
    /**
     * @var Response
     */
    protected $lastOAuthResponse;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get Temporary Credentials aka Unauthorised Request Token
     * from the gateway.
     */
    public function getTemporaryCredentials()
    {
        // Query parameters for requesting the temporary credentials.
        // See https://oauth.net/core/1.0a/ section 6.1.1

        $queryParameters = [
            'oauth_consumer_key' => $this->getConfigItem('consumer_key'),
            'oauth_signature_method' => $this->getConfigItem('signature_method'),
            'oauth_callback' => $this->getConfigItem('callback_uri'),
        ];

        if ($this->getConfigItem('redirect_on_error')) {
            // This should force a cancel to come back to the consumer
            // site, but I'm not sure it is working.

            $queryParameters['redirectOnError'] = 'true';
        }

        // Construct URI.

        $oauth1Endpoint = $this->getOauth1Endpoint();

        $requestTokenUri = $oauth1Endpoint->getRequestTokenUri()
            ->withQuery($this->buildQuery($queryParameters));

        // Construct message.

        $request = $this
            ->getRequestFactory()
            ->createRequest('GET', $requestTokenUri);

        // Send the request.

        $this->lastOAuthResponse = $this->sendRequest($request);

        // The response content type will be text/html, so we will
        // just ignore it.
        // The $response->getStatusCode() should be 200, but there is
        // no guarantee.

        // Parse the response.

        $oAuthData = $this->parseOAuthResponseData($this->lastOAuthResponse);

        // These are the parameters that we can expect in the response.

        $oauthToken = $oAuthData['oauth_token'] ?? null;
        $oauthTokenSecret = $oAuthData['oauth_token_secret'] ?? null;
        $oauthCallbackConfirmed = $oAuthData['oauth_callback_confirmed'] ?? null;

        $oauthProblem = $oAuthData['oauth_problem'] ?? null;
        $oauthProblemAdvice = $oAuthData['oauth_problem_advice'] ?? null;

        return $oauthToken
            ? compact(['oauthToken', 'oauthTokenSecret', 'oauthCallbackConfirmed'])
            : compact(['oauthProblem', 'oauthProblemAdvice']);
    }

    /**
     * Construct the authorise (redirect) URL.
     * The OAuth 1.0a spec says that additional query parameter MAY
     * be added to the URL, and those parameters MUST be returned intact
     * on return to the consumer app. However, Xero does not seem to honour that.
     *
     * @return Psr\Http\Message\UriInterface
     */
    public function authoriseUrl(array $temporaryCredentials = [])
    {
        if (!empty($temporaryCredentials['oauthToken'])) {
            $queryParameters['oauth_token'] = $temporaryCredentials['oauthToken'];
        }

        $oauth1Endpoint = $this->getOauth1Endpoint();

        $redirectUserUri = $oauth1Endpoint->getRedirectUserUri()
            ->withQuery($this->buildQuery($queryParameters));

        return $redirectUserUri;
    }

    /**
     * Exchange the temporary token and verifier for the final tokens.
     *
     * @return Token
     */
    public function getTokenCredentials(
        array $temporaryCredentials,
        string $oauthToken,
        string $oauthVerifier
    ) {
        $queryParameters = [
            'oauth_consumer_key' => $this->getConfigItem('consumer_key'),
            'oauth_token' => $oauthToken,
            'oauth_signature_method' => $this->getConfigItem('signature_method'),
            'oauth_verifier' => $oauthVerifier,
        ];
        // Construct URI.

        $oauth1Endpoint = $this->getOauth1Endpoint();

        $accessTokenUri = $oauth1Endpoint->getAccessTokenUri()
            ->withQuery($this->buildQuery($queryParameters));

        // Construct message.

        $request = $this
            ->getRequestFactory()
            ->createRequest('GET', $accessTokenUri);

        // Send the request.

        $this->lastOAuthResponse = $this->sendRequest($request);

        // Parse the response.

        $oAuthData = $this->parseOAuthResponseData($this->lastOAuthResponse);

        return new Token($oAuthData);
        return $oAuthData;
    }

    /**
     * Send a PSR-7 request and get a PSR-7 response.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Sign the request on the query.

        $request = $this->signRequest($request, static::REQUEST_METHOD_QUERY);

        $response = $this->getClient()->sendRequest($request);

        return $response;
    }
}
