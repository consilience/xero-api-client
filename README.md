# Xero API Client

Table of Contents
=================

   * [Xero API Client](#xero-api-client)
   * [Table of Contents](#table-of-contents)
      * [Simple Usage - Partner Application](#simple-usage---partner-application)
         * [Token Persistence](#token-persistence)
         * [Authorising an Application](#authorising-an-application)
            * [Factories](#factories)
         * [Accessing the Xero API.](#accessing-the-xero-api)
            * [Create OAuth1 Token Object](#create-oauth1-token-object)
      * [Configure HTTP Client](#configure-http-client)
      * [TODO](#todo)

API package for Xero authenticated access.
Leverages PSR-7, PSR-17 and PSR-18.

Handles the OAuth 1.0a requests, and token renewals for the *partner application*.
It does this by offering a decorated PSR-18 client.

Provides support for the authentication flow to capture the OAuth 1.0a tokens.

Features include:

* Support for *Partner Application* only at present.
  (Note: support for *Private Applications* is being added and will be tidied up soon.
* Automatic token renewal of a token by its local age, or on an expiry flagged by
  the remote Xero API.
* Hook to the application for persistence of the OAuth1 token credentails
  when they get renewed. This keeps the burden of renewals away from the application.

## Simple Usage - Partner Application

### Token Persistence

The Xero Partner tokens expire every 30 minutes and need to be renewed.
The renewed tokens then must be saved for use on the next API call.
To perform the persistence in the examples below, we will invoke the
imaginary class `TokenStorage`. It will have methods:

    TokenStorage::get($tokenKey): string;
    TokenStorage::save($tokenKey, string $tokenDetails);

The token details will be an array encoded to a JSON string.

The $token key just identifies a token amoung many in storage.
We'll just use `123` as our key.

### Authorising an Application

This stage is to allow a user to authorise the application to access their
Xero organisation.
The result will be a set of token credentials (Access Token) that can be used
to access the API.

You can use an alternative package for obtaining authorisation, such as Guzzle
and [Invoiced/oauth1-xero](https://github.com/Invoiced/oauth1-xero).
Or use this package to reduce dependencies, whatever fits your needs best.

There are a couple of steps in the OAuth 1.0a flow to get the tokens:

1. Get a temporary token.
2. Send the user to Xero to authorise the applicarion.
3. The user returns with a verification key (a CSRF token).
4. Use the temporary token and the verification key to exchange for the
   long-term Access Token.

Here are the details.
For each stage you will need the authorisation client:

```php
use Consilience\XeroApi\Client\Oauth1\Authorise;

// The public certificate for your RSA-SHA1 public/private key pair will
// be registered with the Partner application on the gateway.
// See: https://developer.xero.com/documentation/auth-and-limits/partner-applications

$authoriseClient = new Authorise([
    'consumer_key'      => 'S8IVZHU6...HUABRRK',
    'consumer_secret'   => 'PLWK9PBG...VHXAQOH',
    'callback_uri'      => 'your callback URL where the user will return to',
    'redirect_on_error' => true,
    'signature_method'  => Authorise::SIGNATURE_METHOD_RSA,
    'private_key_file'  => 'certs/privatekey.pem',
    'private_key_passphrase' => '',
]);
```

First get a temporary token from Xero:

```php
$temporaryToken = $authoriseClient->getTemporaryToken();

if ($temporaryToken->isError()) {
    throw new Exception(sprintf(
        'Failed to get temporary token; error %s (%s)',
        $temporaryToken->getErrorCode(),
        $temporaryToken->getErrorReason()
    ));
}

// Store the token object in the session for later.
// JSON serialise it; we will rebuild it.

Session::set('temporary_token', json_encode($temporaryToken));
```

Then use the temporary token to redirect the user to Xero:

```php
// Your framework will probably have its own way to do a redirect
// that allows it to exit cleanly.

$authoriseUrl = $authoriseClient->authoriseUrl($temporaryToken);
header('Location: ' . (string)$authoriseUrl);
exit;
```

The user will come back to the callback URL with a *verifier*:

```php
// The verifier will be supplied as a GET parameter.

$oauthVerifier = $_GET['oauth_verifier'];

// Retrieve (and rebuild) the temporary token object we saved earlier.

$temporaryToken = new Token(json_decode(Session::get('temporary_token'), true));

// Use these details to get the final Access Token.

$accessToken = $authoriseClient->getAccessToken(
    $temporaryToken,
    $oauthVerifier
);
```

Now the access token can be stored for using to access the Xero API.
We will store it against token key `123` so we can get it back later.

```php
$tokenKey = 123;
TokenStorage::save($tokenKey, json_encode($accessToken));
```

#### Factories

The `Authorise` client needs a few additional objects to operate:

* A PSR-17 HTTP factory (to generate Reqeusts and URIs).
* A PSR-18 client that it decorates.

These can be installed from Guzzle:

    composer require http-interop/http-factory-guzzle

```php
$authoriseClient->withUriFactory(new \Http\Factory\Guzzle\UriFactory);
$authoriseClient->withRequestFactory(new \Http\Factory\Guzzle\RequestFactory);
$authoriseClient->withClient(new \Http\Adapter\Guzzle6\Client);
```

or use diactoros:

    composer require http-interop/http-factory-diactoros

```php
$authoriseClient->withUriFactory(new \Http\Factory\Diactoros\UriFactory);
$authoriseClient->withRequestFactory(new \Http\Factory\Diactoros\RequestFactory);
```

Any other PSR-17 HTTP URI and Request factory, and PSR-18 client can be used.

Alternatively, you can enable auto-discovery and leave the `Authorise` client
to discover the installed factories and create a client for itself.

    composer require http-interop/http-factory-discovery
    composer require php-http/guzzle6-adapter

### Accessing the Xero API.

#### Create OAuth1 Token Object

To access the API, start by creating an OAuth 1.0a token object.

For the *Partner Application* this will be a renewable token that will
be updated in storage each time it gets renewed.

```php
use Consilience\XeroApi\Client\Oauth1\Token;
use Consilience\XeroApi\Client\OauthTokenInterface;

// Get the current token details from storage.

$tokenKey = 123;

$accessTokenData = TokenStorage::get($tokenKey);

$accessToken = new Token(json_decode($accessTokenData, true));

// Add a callback to persist any refresh to the token.

$onPersist = function (OauthTokenInterface $accessToken) use ($tokenKey) {
    TokenStorage::save($tokenKey, json_encode($accessToken));
};

// Tell the client how to persist token refreshes.

$oauth1Token = $oauth1Token->withOnPersist($onPersist);

// We will add a guard time of five minutes.
// The token will be renewed five minutes early each cycle just to
// cut down on the round-trip API accesses.

$oauth1Token = $oauth1Token->withGuardTimeSeconds(60 * 5);
```

For the *Private Application* the oauth token is a lot simpler.
Set the token to the consumer key you were given when setting
up the private application.

```php
// Example Private Application consumer key.

$oauth1Token = new Token ([
    'oauth_token' => 'PQ4351VSH4FHXTJTPN3JBBBNYSAYXM',
]);
```

## Configure HTTP Client

Now we set up a Partner or Private application client.

```php
use Consilience\XeroApi\Client\AbstractClient;

use Consilience\XeroApi\Client\App\Partner;
use Consilience\XeroApi\Client\App\AppPrivate;

// This will create a PSR-18 client decorator.
// Just use it like a PSR-18 client.

// $client can be a Psr\Http\Client\ClientInterface PSR-18 client
// of your choice, or `null` for auto-discovery.

$app = new AppPrivate($client, $oauth1Token, [
// or
$app = new Partner($client, $oauth1Token, [
    // The key and secret are needed for signing.
    'consumer_key'    => 'PQ4351VSH4FHXTJTPN3JBBBNYSAYXM',
    'consumer_secret' => '1FWE9NCU8SYB8S9ROFDTCUDCC3UXMF',
    // RSA is required for Xero.
    'signature_method' => AbstractClient::SIGNATURE_METHOD_RSA,
    // Key file.
    // Xero will already have the public part of your key.
    'private_key_file' => 'certs/privatekey.pem',
    'private_key_passphrase' => '',
]);
```

The application should be provided, which is used as a User Agent.
This helps Xero when they look at their logs.

    $app = $app->withApplicationName('My Ace Application');

To support Guzzle as the underlying PSR-18 client, and the
unserlying PSR-17 message factory through auto-discovery,
you will need to install the adapters through composer:

* guzzlehttp/psr7
* guzzlehttp/guzzle
* php-http/guzzle6-adapter
* http-interop/http-factory-guzzle [needed for the
  php-http/guzzle6-adapter adaper to work, no idea why]
* http-interop/http-factory-discovery [later]

Otherwise, the message factory and client can be passed in
when instantiating.

Now we can make a request of the API.
Any request wil work - GET, POST, PUT and to any Xero endpoint
that your application supports.

```php
use Http\Discovery\MessageFactoryDiscovery;

// The request factory is just an example.
// If you have a concrete request class, then just use that.
// We are fetching the organisation from the 2.0 Accounting
// API and requesting a JSON response.

$messageFactory = MessageFactoryDiscovery::find();

// This is a very simple request, with no parameters and no payload.
// Builing more complex requests is a job for another package, and
// that will be auto-generated from the Xero OpenAPI specs.

$request = $messageFactory->createRequest(
    'GET',
    'https://api.xero.com/api.xro/2.0/organisation'
)->withHeader('Accept', 'application/json');

$response = $app->sendRequest($request);

$payloadData = json_decode((string)$response->getBody(), true);
var_dump($payloadData);
```

```php
array(5) {
  ["Id"]=>
  string(36) "2f7b676d-2b01-4699-9148-f660b8331671"
  ["Status"]=>
  string(2) "OK"
  ["ProviderName"]=>
  string(25) "Acme Payments"
  ["DateTimeUTC"]=>
  string(21) "/Date(1553978254788)/"
  ["Organisations"]=>
  array(1) {
    [0]=>
    array(30) {
      ["APIKey"]=>
      string(30) "STVXGL8FXHG6VIYX1BVKBRGRICMF08"
      ["Name"]=>
      string(24) "Acme Payments Company"
      ["LegalName"]=>
      string(24) "Acme Payments Company"
      ["PaysTax"]=>
      bool(true)
      ["Version"]=>
      string(2) "UK"
      ["OrganisationType"]=>
      string(7) "COMPANY"
      ["BaseCurrency"]=>
      string(3) "GBP"
      ["CountryCode"]=>
      string(2) "GB"
      ...snip...
      ["Phones"]=>
      array(0) {
      }
      ["ExternalLinks"]=>
      array(0) {
      }
      ["PaymentTerms"]=>
      array(0) {
      }
    }
  }
}
```

That's it. With the correct method, URL, `Accept` header and payload
(if using `POST`) you can send requests to all parts of the Xero API.
Token renewals - for the Partner application at least - will be handled
for you automatically and invisibly to the application.

Another package will handle the payload parsing and request building.
This package is just concerned with the HTTP access with OAuth 1.0a
credentials.
The Xero API Operations package is in development (and usable now) here:
https://github.com/consilience/xero-api-sdk

## TODO

* Tests (as usual).
* Is there a better way of handling key files, perhaps as streams, so
  it can be supplied as a path, a string, a file resource etc?
* Some better exception handling, so we can catch a failed token, redacted
  authorisation, general network error etc and handle appropriately.

