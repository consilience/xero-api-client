<?php

namespace Consilience\XeroApi\Client\Oauth1;

/**
 * Client for performing the initial OAUth1.0 authorisation
 * for the Partner application.
 */

use Consilience\XeroApi\Client\Oauth1\Endpoint;
use Consilience\XeroApi\Client\AbstractClient;
use Consilience\XeroApi\Client\OauthTokenInterface;
use Consilience\XeroApi\Client\Oauth1\Token;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Authorise extends AbstractClient
{
    /**
     * @var Response
     */
    protected $lastOAuthResponse;

    /**
     * @param array $config configuration details
     * @param ClientInterface $client a PSR-18 client or null for auto-discovery
     */
    public function __construct(
        array $config,
        ?ClientInterface $client = null
    ) {
        if ($client) {
            $this->setClient($client);
        }

        $this->config = $config;
    }

    /**
     * Get Temporary Credentials aka Unauthorised Request Token
     * from the gateway.
     *
     * @return Token
     */
    public function getTemporaryToken()
    {
        // Query parameters for requesting the temporary credentials.
        // See https://oauth.net/core/1.0a/ section 6.1.1

        $queryParameters = [
            'oauth_consumer_key' => $this->getConfigItem('consumer_key'),
            'oauth_signature_method' => $this->getConfigItem('signature_method'),
            'oauth_callback' => $this->getConfigItem('callback_uri'),
        ];

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

        return new Token($oAuthData);
    }

    /**
     * Construct the authorise (redirect) URL.
     * The OAuth 1.0a spec says that additional query parameter MAY
     * be added to the URL, and those parameters MUST be returned intact
     * on return to the consumer app. However, Xero does not seem to honour that.
     *
     * @param OauthTokenInterface the temporary access token
     * @return Psr\Http\Message\UriInterface
     */
    public function authoriseUrl(OauthTokenInterface $temporaryToken)
    {
        if ($oauthToken = $temporaryToken->oauthToken) {
            $queryParameters['oauth_token'] = $oauthToken;
        }

        if ($this->getConfigItem('redirect_on_error')) {
            // This should force a cancel to come back to the consumer
            // site, but I'm not sure it is working.

            $queryParameters['redirectOnError'] = 'true';
        }

        $oauth1Endpoint = $this->getOauth1Endpoint();

        $redirectUserUri = $oauth1Endpoint->getRedirectUserUri()
            ->withQuery($this->buildQuery($queryParameters));

        return $redirectUserUri;
    }

    /**
     * Exchange the temporary token and verifier for the final tokens.
     * Long-lived credetials, AKA Access Token.
     * The temporary token secret is only used for HMAC SHA1 signing.
     * Xero uses RSA SHA1 with pre-shared keys so the token secret
     * will be ignored in the signing algorithm.
     *
     * @param OauthTokenInterface temporary token
     * @param string $oauthTokenVerifier the CSRF verifier
     * @return Token long-term Access Token
     */
    public function getAccessToken(
        OauthTokenInterface $temporaryToken,
        string $oauthVerifier
    ) {
        $queryParameters = [
            'oauth_consumer_key' => $this->getConfigItem('consumer_key'),
            'oauth_token' => $temporaryToken->oauthToken,
            'oauth_signature_method' => $this->getConfigItem('signature_method'),
            'oauth_verifier' => $oauthVerifier,
        ];

        // Set the temporary token (with its secret) in case it is needed for
        // HMAC SHA1 signing.

        $this->setOAuth1Token($temporaryToken);

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

        // If the caller wants the raw data, it can be dug out of the
        // getLastOAuthResponse()

        return new Token($oAuthData);
    }

    /**
     * @return Response the last OAuth PSR-7 response
     */
    public function getLastOAuthResponse()
    {
        return $this->lastOAuthResponse;
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
