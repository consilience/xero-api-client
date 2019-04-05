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

        // Set the User Agent to be the application name.

        $applicationName = $this->getApplicationName();

        if ($applicationName !== null && $applicationName !== $request->getHeaderLine('User-Agent')) {
            $request = $request->withHeader('User-Agent', $applicationName);
        }

        if (! $refreshRequired) {
            // Sign the request then send it, so long as it is not
            // already marked as expired locally.

            $request = $this->signRequest($request);
            $response = $this->getClient()->sendRequest($request);

            // Check if the token has expired remotely. If it has, it can be renewed.
            // This will arrive as a text/html content type but with a form params payload.

            // FIXME: change the logic here. Check for an expired token and renew
            // it immediately and retry the original request before looking for
            // any other errors. Then check the response code and payload after
            // that for further OAuth errors or general API errors.
            // Maybe we want to avoid all exceptions (being a PSR-18 client) and
            // decode OAuth errors into a different payload that can be pulled
            // into the API models.

            $oAuthData = $this->parseOAuthResponseData($response);

            if (! empty($oAuthData)) {
                $oAuthProblem = $oAuthData['oauth_problem'] ?? '';
                $otherProblem = $oAuthData['error'] ?? '';

                if ($response->getStatusCode() == 401
                    && $oAuthProblem === static::OAUTH_PROBLEM_TOKEN_EXPIRED
                ) {
                    // The token has expired and should be renewed.

                    $refreshRequired = true;
                } elseif ($oAuthProblem !== '') {
                    // Some other non-recoverable OAuth problem.
                    // TODO: handle this with custom exception.

                    throw new RuntimeException(sprintf(
                        'OAuth access error: %s (%s)',
                        $oAuthProblem,
                        $oAuthData['oauth_problem_advice'] ?? ''
                    ));
                } elseif ($otherProblem !== '') {
                    // TODO: handle this with custom exception.

                    throw new RuntimeException(sprintf(
                        'Error: %d (%s)',
                        $response->getStatusCode(),
                        $otherProblem
                    ));
                }
            }
        }

        if ($refreshRequired) {
            $refreshTokenData = $this->refreshToken();

            // Retry the original request.
            // It needs signing again before sending as the nonce will
            // need changing.

            $request = $this->signRequest($request);
            $response = $this->getClient()->sendRequest($request);

            // TODO: we will still want to catch further OAuth or permission
            // errors that can occur with non-20x responses.
            // If we don't, then handling of errors will be different if the
            // token has just been renewed, compared to if it has not been
            // renewed.
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

        // TODO: update the token from storage first, in case
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
                'OAuth token refresh error: "%s" (%s)',
                $oAuthData['oauth_problem'] ?? '',
                $oAuthData['oauth_problem_advice'] ?? ''
            ));
        }

        // As the refresh looks good, update the local token details
        // and fire off persistent storage of it too.

        $refreshedTokenData = [
            'token' => $oAuthData['oauth_token'],
            'token_secret' => $oAuthData['oauth_token_secret'],
            'expires_in' => (int)$oAuthData['oauth_expires_in'],
            'session_handle' => $oAuthData['oauth_session_handle'],
            'authorization_expires_in' => (int)$oAuthData['oauth_authorization_expires_in'],
            'xero_org_muid' => $oAuthData['xero_org_muid'],
        ];

        // Regenerate the token object for further requests.

        $oAuth1Token = $oAuth1Token->withTokenData($refreshedTokenData);

        $oAuth1Token->persist();

        $this->setOAuth1Token($oAuth1Token);

        return $refreshedTokenData;
    }
}
