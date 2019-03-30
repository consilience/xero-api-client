<?php

namespace Consilience\XeroApi\Client;

/**
 * Xero Partner Application client.
 * A dectorator for a PSR-18 HTTP client.
 * This client handles auto-renewals of OAuth 1.0a tokens.
 */

use Consilience\XeroApi\AbstractClient;
use Consilience\XeroApi\Oauth1\Endpoint;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use Psr\Http\Client\ClientInterface;

// Discovery php-http/discovery + adapters
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;

// Discovery http-interop/http-factory-discovery + adapters
// (Not used yet)
use Http\Factory\Discovery\HttpFactory;
use Http\Factory\Discovery\HttpClient;

use InvalidArgumentException;
use RuntimeException;
use Exception;

class Partner extends AbstractClient
{
    /**
     * TODO: before refreshing the token, check if any updates have been
     * stored then try the updated token.
     * While refreshing the token, set up a lock so other processes do
     * not update at the same time. The two could be combined, so the lock
     * checks for updates first.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Start by checking the expiry time.

        $refreshRequired = $this->getOAuth1Token()->isExpired();

        if (! $refreshRequired) {
            // Sign the request then send it, so long as it is not
            // already marked as expired locally.

            $request = $this->signRequest($request);
            $response = $this->getClient()->sendRequest($request);

            // Check if the token has expired remotely. If it has, it can be renewed.
            // This will arrive as a text/html content type but with a form params payload.

            $oAuthData = $this->parseOAuthResponseData($response);

            if (! empty($oAuthData)) {
                $oAuthProblem = $oAuthData['oauth_problem'] ?? '';

                if ($response->getStatusCode() == 401 && $oAuthProblem === static::OAUTH_PROBLEM_TOKEN_EXPIRED) {
                    // The token has expired and should be renewed.

                    $refreshRequired = true;
                } else {
                    // Some other non-recoverable OAuth problem.

                    throw new RuntimeException(sprintf(
                        'OAuth access error: %s (%s)',
                        $oAuthData['oauth_problem'],
                        $oAuthData['oauth_problem_advice'] ?? ''
                    ));
                }
            }
        }

        // TODO: For testing we may want to force a token refresh.
        //$refreshRequired = true;

        if ($refreshRequired) {
            $refreshTokenData = $this->refreshToken();

            // Retry the original request.
            // It needs signing again before sending as the nonce will
            // need changing.

            $request = $this->signRequest($request);
            $response = $this->getClient()->sendRequest($request);
        }

        return $response;
    }

    /**
     * TODO: set the HTTP agent, required for partner Xero apps.
     */
    public function refreshToken(): array
    {
        // Create the refresh message.
        // Being a GET request, everything will be in the URL.

        $oAuth1Token = $this->getOAuth1Token();

        // TODO: update the token first, refreshing it from storage if
        // another process has already happened to have refreshed it.

        $queryParameters = [
            'oauth_token' => $oAuth1Token->getToken(),
            'oauth_session_handle' => $oAuth1Token->getSessionHandle(),
            'oauth_consumer_key' => $this->getConfigItem('consumer_key'),
            'signature_method' => $this->getConfigItem('signature_method'),
        ];

        $oauth1Endpoint = new Endpoint(); // TODO maybe getAuth1Endpont()?

        if ($uriFactory = $this->getUriFactory()) {
            $oauth1Endpoint = $oauth1Endpoint->withUriFactory($uriFactory);
        }

        $refreshUri = $oauth1Endpoint
            ->getRefreshTokenUri()
            ->withQuery($this->buildQuery($queryParameters));

        // Create a PSR-7 request message.

        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $request = $requestFactory->createRequest('GET', $refreshUri);

        $request = $this->signRequest($request, self::REQUEST_METHOD_QUERY);

        $response = $this->getClient()->sendRequest($request);

        $oAuthData = $this->parseOAuthResponseData($response);

        // If the renewal failed, then throw an exception.

        if (! empty($oAuthData['oauth_problem']) || empty($oAuthData)) {
            throw new RuntimeException(sprintf(
                'OAuth token refresh error: %s (%s)',
                $oAuthData['oauth_problem'],
                $oAuthData['oauth_problem_advice'] ?? ''
            ));
        }

        // As the refresh looks good, then update the local token details
        // and fire off persistent storage of them too.

        $refreshedTokenData = [
            'token' => $oAuthData['oauth_token'],
            'token_secret' => $oAuthData['oauth_token_secret'],
            'expires_in' => (int)$oAuthData['oauth_expires_in'],
            'session_handle' => $oAuthData['oauth_session_handle'],
            'authorization_expires_in' => (int)$oAuthData['oauth_authorization_expires_in'],
            'xero_org_muid' => $oAuthData['xero_org_muid'],
        ];

        // Regenerate the token object for further requests.
        // TODO: persist the token data.

        $oAuth1Token = $oAuth1Token->withTokenData($refreshedTokenData);

        $oAuth1Token->persist();

        $this->setOAuth1Token($oAuth1Token);

        return $refreshedTokenData;
    }
}
