<?php

namespace Consilience\XeroApi\Client\App;

/**
 * Xero Partner Application client.
 * A decorator for a PSR-18 HTTP client.
 * This client handles auto-renewals of OAuth 1.0a tokens.
 */

use Consilience\XeroApi\Client\Oauth1\Token;
use Consilience\XeroApi\Client\AbstractClient;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use Psr\Http\Client\ClientInterface;

use InvalidArgumentException;
use RuntimeException;
use Exception;

class Partner extends AbstractClient
{
    /**
     * TODO: before refreshing the token, check if any updates have been
     * stored then try the updated token. The Token::reload() method is now
     * available to do this. Should we check multiple times? probably not - if
     * the reload gives us a new token, then the refreshing of that was out of
     * our hands, so we won't be able to renew it with the details we have
     * anyway.
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

        // Up to two passes.
        // The second pass is for when the first pass discovers an expired token.

        foreach ([1, 2] as $pass) {
            if ($refreshRequired) {
                $this->refreshToken();
            }

            // Sign the request then send it, so long as it is not
            // already marked as expired locally.

            $request = $this->signRequest($request, static::REQUEST_METHOD_HEADER);
            $response = $this->getClient()->sendRequest($request);

            // Check if the token has expired remotely. If it has, it can be renewed.
            // This will arrive as a text/html content type but with a form params payload.

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
                    // Try a second pass, renewing the token on the way through.

                    $refreshRequired = true;
                    continue;
                } elseif ($oAuthProblem !== '') {
                    // Some other non-recoverable OAuth problem.
                    // TODO: handle this with custom exception.

                    throw new RuntimeException(sprintf(
                        'OAuth access error on pass %d: %s (%s)',
                        $pass,
                        $oAuthProblem,
                        $oAuthData['oauth_problem_advice'] ?? ''
                    ));
                } elseif ($otherProblem !== '') {
                    // TODO: handle this with custom exception.
                    // Perhaps we don't throw an exception (PSR-18 and all) but
                    // generate our own custom payload with the error details?

                    throw new RuntimeException(sprintf(
                        'Error on pass %d: %d (%s)',
                        $pass,
                        $response->getStatusCode(),
                        $otherProblem
                    ));
                }
            }

            // If we got here without finding an OAuth or other error,
            // then we have a usable response. Don't do another pass.

            break;
        }

        return $response;
    }

    /**
     * @return Token the new OAuth token; also sets the token
     */
    public function refreshToken(): Token
    {
        // Get the token and check if it can be reloaded from
        // storage with new details.

        $oAuth1Token = $this->getOAuth1Token()->reload();

        // If the reload gave us a new token, refreshed by
        // another thread, then use it.
        // Otherwise, get a new token and persist it.

        if (! $oAuth1Token->isRefreshed()) {
            // Create the refresh message.
            // Being a GET request, everything will be in the URL.

            // TODO: update the token from storage first, in case
            // another process has already happened to have refreshed it.

            $queryParameters = [
                'oauth_token' => $oAuth1Token->getOauthToken(),
                'oauth_session_handle' => $oAuth1Token->getOauthSessionHandle(),
                'oauth_consumer_key' => $this->getConfigItem('consumer_key'),
                'signature_method' => $this->getConfigItem('signature_method'),
            ];

            // OAuth1 Endpoint.

            $oauth1Endpoint = $this->getOauth1Endpoint();

            $refreshUri = $oauth1Endpoint
                ->getRefreshTokenUri()
                ->withQuery($this->buildQuery($queryParameters));

            // Create a PSR-7 request message.

            $request = $this->getRequestFactory()->createRequest('GET', $refreshUri);

            $request = $this->signRequest($request, static::REQUEST_METHOD_QUERY);

            $response = $this->getClient()->sendRequest($request);

            $oAuthData = $this->parseOAuthResponseData($response);

            // Regenerate the token object for further requests.

            $oAuth1Token = $oAuth1Token->withTokenData($oAuthData);

            // If the renewal failed, then throw an exception.

            if ($oAuth1Token->isError()) {
                throw new RuntimeException(sprintf(
                    'OAuth token refresh error: "%s" (%s)',
                    $oAuth1Token->getErrorCode(),
                    $oAuth1Token->getErrorReason()
                ));
            }

            // As the refresh looks good, update the local token details
            // and fire off persistent storage of it too.

            $oAuth1Token->persist();
        }

        $this->setOAuth1Token($oAuth1Token);

        return $oAuth1Token;
    }
}
