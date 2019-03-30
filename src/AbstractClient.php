<?php

namespace Consilience\XeroApi;

/**
 *
 */

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Http\Message\UriFactory;
use InvalidArgumentException;

// Discovery php-http/discovery + adapters
use Http\Discovery\Psr18ClientDiscovery;

// Discovery http-interop/http-factory-discovery + adapters
// (Not used yet)
use Http\Factory\Discovery\HttpClient;

abstract class AbstractClient implements ClientInterface
{
    /**
     * See https://developer.xero.com/documentation/auth-and-limits/oauth-issues
     * @var string Values for the PARAM_OAUTH_PROBLEM parameter
     */
    // The most common two problems, the first recoverable through a refresh,
    // and the second not.
    const OAUTH_PROBLEM_TOKEN_EXPIRED       = 'token_expired';
    const OAUTH_PROBLEM_TOKEN_REJECTED      = 'token_rejected';

    // Some issues that may occur during refresh or authorisaion or
    // due to incorrect configuration.
    const OAUTH_PROBLEM_TOKEN_SIG_INVALID   = 'signature_invalid';
    const OAUTH_PROBLEM_TOKEN_NONCE_USED    = 'nonce_used';
    const OAUTH_PROBLEM_TOKEN_TIMESTAMP     = 'timestamp_refused';
    const OAUTH_PROBLEM_TOKEN_SIG_METHOD    = 'signature_method_rejected';
    const OAUTH_PROBLEM_TOKEN_PERM_DENIED   = 'permission_denied';
    const OAUTH_PROBLEM_TOKEN_KEY_UNKOWN    = 'consumer_key_unknown';
    const OAUTH_PROBLEM_TOKEN_XERO_ERROR    = 'xero_unknown_error';

    // Note: spaces and not underscores. Reason unknown.
    const OAUTH_PROBLEM_TOKEN_RATE_LIMIT    = 'rate limit exceeded';

    /**
     * @var string The name of the oauth problem code parameter.
     */
    const PARAM_OAUTH_PROBLEM           = 'oauthProblem';
    const PARAM_OAUTH_PROBLEM_ADVICE    = 'oauthProblemAdvice';

    const OAUTH_EXPIRES_IN  = 'oauthExpiresIn';
    const OAUTH_EXPIRES_AT  = 'oauthExpiresAt';
    const OAUTH_CREATED_AT  = 'oauthCreatedAt';
    const CREATED_AT        = 'createdAt';

    const REQUEST_METHOD_HEADER = 'header';
    const REQUEST_METHOD_QUERY  = 'query';

    const SIGNATURE_METHOD_HMAC      = 'HMAC-SHA1';
    const SIGNATURE_METHOD_RSA       = 'RSA-SHA1';
    const SIGNATURE_METHOD_PLAINTEXT = 'PLAINTEXT';

    /**
     * @var Psr\Http\Client\ClientInterface
     */
    protected $client;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var Consilience\XeroApi\OauthTokenInterface
     */
    protected $oauth1Token;

    /**
     * @var Http\Message\UriFactory
     */
    protected $uriFactory;

    /**
     * @param OauthTokenInterface $oauth1Token token instance with current credentials
     * @param array $config configuration details
     * @param ClientInterface $client a PSR-18 client or null for auto-discovery
     */
    public function __construct(
        OauthTokenInterface $oauth1Token,
        array $config,
        ?ClientInterface $client = null
    ) {
        $this->client = $client;
        $this->oauth1Token = $oauth1Token;
        $this->config = $config;
    }

    /**
     * Get the PSR-18 HTTP client.
     * The client is lazy-discovered.
     *
     * @return ClientInterface the supplied client or auto-discovered
     */
    public function getClient(): ClientInterface
    {
        return $this->client = $this->client ?? Psr18ClientDiscovery::find();
    }

    public function getOAuth1Token()
    {
        return $this->oauth1Token;
    }

    protected function setOAuth1Token(OAuthTokenInterface $oauth1Token)
    {
        $this->oauth1Token = $oauth1Token;
        return $this;
    }

    public function withOAuth1Token(OAuthTokenInterface $oauth1Token)
    {
        return (clone $this)->setOAuth1Token($oauth1Token);
    }

    public function getUriFactory()
    {
        return $this->uriFactory;
    }

    protected function setUriFactory(UriFactory $uriFactory)
    {
        $this->uriFactory = $uriFactory;
        return $this;
    }

    public function withUriFactory(UriFactory $uriFactory)
    {
        return (clone $this)->setUriFactory($uriFactory);
    }

    /**
     * Return a named option.
     *
     * @param string $name Name of config item.
     * @param mixed $default Value to return if the config is not set or null
     * @return mixed
     */
    protected function getConfigItem(string $name, $default = null)
    {
        return $this->config[$name] ?? $default;
    }

    /**
     * Parse a query string into an associative array.
     * Lifted from Guzzle 6.
     *
     * If multiple values are found for the same key, the value of that key
     * value pair will become an array. This function does not parse nested
     * PHP style arrays into an associative array (e.g., foo[a]=1&foo[b]=2 will
     * be parsed into ['foo[a]' => '1', 'foo[b]' => '2']).
     *
     * @param string $str Query string to parse
     * @param int $urlEncoding How the query string is encoded
     *
     * @return array
     */
    protected function parseQuery(string $str, int $urlEncoding = 0): array
    {
        $result = [];

        if ($str === '') {
            return $result;
        }

        if ($urlEncoding === 0) {
            $decoder = function ($value) {
                return rawurldecode(str_replace('+', ' ', $value));
            };
        } elseif ($urlEncoding === PHP_QUERY_RFC3986) {
            $decoder = 'rawurldecode';
        } elseif ($urlEncoding === PHP_QUERY_RFC1738) {
            $decoder = 'urldecode';
        } else {
            $decoder = function ($str) { return $str; };
        }

        foreach (explode('&', $str) as $kvp) {
            $parts = explode('=', $kvp, 2);
            $key = $decoder($parts[0]);
            $value = isset($parts[1]) ? $decoder($parts[1]) : null;
            if (!isset($result[$key])) {
                $result[$key] = $value;
            } else {
                if (!is_array($result[$key])) {
                    $result[$key] = [$result[$key]];
                }
                $result[$key][] = $value;
            }
        }

        return $result;
    }

    /**
     * Build a query string from an array of key value pairs.
     * Lifted from Guzzle 6.
     *
     * This function can use the return value of parse_query() to build a query
     * string. This function does not modify the provided keys when an array is
     * encountered (like http_build_query would).
     *
     * @param array     $params   Query string parameters.
     * @param int|false $encoding Set to false to not encode, PHP_QUERY_RFC3986
     *                            to encode using RFC3986, or PHP_QUERY_RFC1738
     *                            to encode using RFC1738.
     * @return string
     */
    protected function buildQuery(array $params, $encoding = PHP_QUERY_RFC3986): string
    {
        if (! $params) {
            return '';
        }

        if ($encoding === false) {
            $encoder = function ($str) {
                return $str;
            };
        } elseif ($encoding === PHP_QUERY_RFC3986) {
            $encoder = 'rawurlencode';
        } elseif ($encoding === PHP_QUERY_RFC1738) {
            $encoder = 'urlencode';
        } else {
            throw new InvalidArgumentException(sprintf(
                'Invalid encoding type: "%s"',
                $encoding
            ));
        }

        $qs = '';

        foreach ($params as $k => $v) {
            $k = $encoder($k);
            if (! is_array($v)) {
                $qs .= $k;
                if ($v !== null) {
                    $qs .= '=' . $encoder($v);
                }
                $qs .= '&';
            } else {
                foreach ($v as $vv) {
                    $qs .= $k;
                    if ($vv !== null) {
                        $qs .= '=' . $encoder($vv);
                    }
                    $qs .= '&';
                }
            }
        }

        return $qs ? (string) substr($qs, 0, -1) : '';
    }

    /**
     * @param RequestInterface $request
     * @param string $requestMethod indicates whether to sign the query string or a header
     */
    protected function signRequest(
        RequestInterface $request,
        string $requestMethod = self::REQUEST_METHOD_HEADER
    ): RequestInterface
    {
        // Get the base OAuth parameters.

        $oauthparams = $this->getOauthParams(
            $this->generateNonce($request)
        );

        // Add the signature.

        $oauthparams['oauth_signature'] = $this->getSignature($request, $oauthparams);
        uksort($oauthparams, 'strcmp');

        // Add the OAuth params to the request, either serialised into a single header,
        // or added to the current query string.

        switch ($requestMethod) {
            case static::REQUEST_METHOD_HEADER:
                list($header, $value) = $this->buildAuthorizationHeader($oauthparams);
                $request = $request->withHeader($header, $value);
                break;
            case static::REQUEST_METHOD_QUERY:
                $queryparams = $this->parseQuery($request->getUri()->getQuery());
                $preparedParams = $this->buildQuery($oauthparams + $queryparams);
                $request = $request->withUri($request->getUri()->withQuery($preparedParams));
                break;
            default:
                throw new InvalidArgumentException(sprintf(
                    'Invalid request method: "%s"',
                    $requestMethod
                ));
        }

        return $request;
    }

    /**
     * Builds the Authorization header for a request.
     * This builds the OAuth parameters into a CSV list.
     *
     * @param array $params Associative array of authorization parameters.
     * @return array
     */
    protected function buildAuthorizationHeader(array $params): array
    {
        foreach ($params as $key => $value) {
            $params[$key] = $key . '="' . rawurlencode($value) . '"';
        }

        if ($this->getConfigItem('realm') !== null) {
            array_unshift(
                $params,
                'realm="' . rawurlencode($this->getConfigItem('realm')) . '"'
            );
        }

        return ['Authorization', 'OAuth ' . implode(', ', $params)];
    }

    /**
     * Get the oauth parameters as named by the oauth spec
     *
     * @param string $nonce Unique nonce
     * @return array
     */
    protected function getOauthParams(string $nonce): array
    {
        $params = [
            'oauth_consumer_key'     => $this->getConfigItem('consumer_key'),
            'oauth_nonce'            => $nonce,
            'oauth_signature_method' => $this->getConfigItem('signature_method'),
            'oauth_timestamp'        => time(),
        ];

        // Optional parameters should only be set if they have been set as the
        // parameter may be considered invalid by the Oauth service.

        $optionalParams = [
            'callback'  => 'oauth_callback',
            'verifier'  => 'oauth_verifier',
            'version'   => 'oauth_version'
        ];

        if ($oauthToken = $this->getOAuth1Token()->getToken()) {
            $params['oauth_token'] = $oauthToken;
        }

        foreach ($optionalParams as $optionName => $oauthName) {
            $oauthValue = $this->getConfigItem($optionName);

            if ($oauthValue !== null) {
                $params[$oauthName] = $oauthValue;
            }
        }

        return $params;
    }

    /**
     * Calculate signature for request
     *
     * @param RequestInterface $request Request to generate a signature for
     * @param array $params Oauth parameters.
     * @return string
     *
     * @throws RuntimeException
     */
    protected function getSignature(RequestInterface $request, array $params): string
    {
        // Remove oauth_signature if present
        // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")

        unset($params['oauth_signature']);

        // Add POST fields if the request uses POST fields and no files.

        if ($request->getHeaderLine('Content-Type') === 'application/x-www-form-urlencoded') {
            $body = $this->parseQuery($request->getBody()->getContents());
            $params += $body;
        }

        // Parse and add query string parameters as base string parameters.

        $query = $request->getUri()->getQuery();
        $params += $this->parseQuery($query);

        $baseString = $this->createBaseString(
            $request,
            $this->prepareParameters($params)
        );

        // Implements double-dispatch to sign requests

        switch ($this->getConfigItem('signature_method', '')) {
            case self::SIGNATURE_METHOD_HMAC:
                $signature = $this->signUsingHmacSha1($baseString);
                break;
            case self::SIGNATURE_METHOD_RSA:
                $signature = $this->signUsingRsaSha1($baseString);
                break;
            case self::SIGNATURE_METHOD_PLAINTEXT:
                $signature = $this->signUsingPlaintext($baseString);
                break;
            default:
                throw new RuntimeException(sprintf(
                    'Unknown signature method: %s',
                    $$this->getConfigItem('signature_method', '')
                ));
                break;
        }

        return base64_encode($signature);
    }

    /**
     * Convert booleans to strings, removed unset parameters, and sorts the array
     *
     * @param array $data Data array
     * @return array
     */
    protected function prepareParameters(array $data): array
    {
        // Parameters are sorted by name, using lexicographical byte value
        // ordering. Ref: Spec: 9.1.1 (1).

        uksort($data, 'strcmp');

        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    // TODO: the following can be injected classes, allowing further
    // signing types to be added.

    /**
     * @param string $baseString
     * @return string
     */
    protected function signUsingHmacSha1(string $baseString): string
    {
        $key = rawurlencode($this->getConfigItem('consumer_secret'))
            . '&' . rawurlencode($this->getToken()->getConfigItem('token_secret'));

        return hash_hmac('sha1', $baseString, $key, true);
    }

    /**
     * @param string $baseString
     * @return string
     */
    protected function signUsingRsaSha1(string $baseString): string
    {
        if (!function_exists('openssl_pkey_get_private')) {
            throw new RuntimeException(
                'RSA-SHA1 signature method requires the OpenSSL extension.'
            );
        }

        $privateKey = openssl_pkey_get_private(
            file_get_contents($this->getConfigItem('private_key_file')),
            $this->getConfigItem('private_key_passphrase', '')
        );

        $signature = '';
        openssl_sign($baseString, $signature, $privateKey);
        openssl_free_key($privateKey);

        return $signature;
    }

    /**
     * @param string $baseString
     * @return string
     */
    protected function signUsingPlaintext(string $baseString): string
    {
        return $baseString;
    }

    /**
     * Creates the Signature Base String.
     *
     * The Signature Base String is a consistent reproducible concatenation of
     * the request elements into a single string. The string is used as an
     * input in hashing or signing algorithms.
     *
     * @param RequestInterface $request Request being signed
     * @param array            $params  Associative array of OAuth parameters
     * @return string Returns the base string
     * @link http://oauth.net/core/1.0/#sig_base_example
     */
    protected function createBaseString(RequestInterface $request, array $params): string
    {
        // Remove query params from URL. Ref: Spec: 9.1.2.

        $url = $request->getUri()->withQuery('');
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return strtoupper($request->getMethod())
            . '&' . rawurlencode($url)
            . '&' . rawurlencode($query);
    }

    /**
     * Returns a Nonce Based on the unique id and URL.
     *
     * This will allow for multiple requests in parallel with the same exact
     * timestamp to use separate nonce's.
     *
     * @param RequestInterface $request Request to generate a nonce for
     * @return string
     */
    protected function generateNonce(RequestInterface $request): string
    {
        return sha1(uniqid('', true) . $request->getUri()->getHost() . $request->getUri()->getPath());
    }

    /**
     * Extract OAuth payload values from a response.
     *
     * @param ResponseInterface $response Response from Xero
     * @return array
     */
    protected function parseOAuthResponseData(ResponseInterface $response): array
    {
        $result = [];

        [$contentType] = explode(';', $response->getHeaderLine('content-type'));

        // Two content types can indicate OAuth data is being returned.

        if ($contentType === 'text/html' || $contentType === 'application/x-www-form-urlencoded') {
            $body = (string)$response->getBody();

            // If there are any html tags, then this won't be OAuth data.

            if (strpos($body, '<') === false && strpos($body, '>') === false) {
                $result = $this->parseQuery($body);
            }
        }

        return $result;
    }
}
