# Xero API

API package for Xero authenticated access, PSR-7, PSR-15, PSR-18.

Handles the OAuth 1.0a requests, and token renewals for the *partner application*.
It does this by offering a decorated PSR-18 

Features include:

* Support for *Partner Application* only at present.
* Automatic token renewal by time (including guard time) or on an expired token.
* Callback to the application for persistence of the OAuth1 token credentails
  when they gety renewed.

## Simple Usage

### Authorising an Application

This stage is to allow a user to authorise the application to access their
Xero organisation.
The result will be a set of token credentials (Access Token) that can be used
to access the API.

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

// Store it in the session for later.

Session::set('temporary_token', json_encode($temporaryToken));
```

Then use the temporary token to redirect the user to Xero:

```php
$authoriseUrl = $authoriseClient->authoriseUrl($temporaryToken);
header('Location: ' . (string)$authoriseUrl);
exit;
```

The user will come back to the callback URL with a verifier:

```php
    // The verifier will be supplied as a GET parameter.

    $oauthVerifier = $_GET['oauth_verifier'];

    // Retrieve the temporary token we saved earlier.

    $temporaryToken = new Token(json_decode(getSession('temporary_token'), true));

    // Use these details to get the final Access Token.

    $accessToken = $authoriseClient->getAccessToken(
        $temporaryToken,
        $oauthVerifier
    );
```

Now the access token can be stored so it can be used to access the API.
We will store it in a simple table in this example.

First create the database to store the current tokens.

```php
$db = new SQLite3('auth.db');
$db->exec('create table if not exists auth(id integer primary key, token text)');
```

Now store the credentials in the database.
We will store it against authentication ID 123.

```php
$authId = 123;

$db = new SQLite3('auth.db');

$statement = $db->prepare('replace into auth (id, token) values (:id, :token)');
$statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
$statement->bindValue(':token', json_encode($accessToken), \SQLITE3_TEXT);
$statement->execute();
```

#### Factories

The `Authotise` client needs a few additional objects to operate:

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

### Accessing the API.

So, to access the API, start by creating an OAuth 1.0a object.

```php
use Consilience\XeroApi\Client\Oauth1\Token;
use Consilience\XeroApi\Client\OauthTokenInterface;

// Get the current token details from the database.

$authId = 123;

$db = new SQLite3('auth.db');

$statement = $db->prepare('select token from auth where id = :id');
$statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
$result = $statement->execute();
$accessTokenData = json_decode($result->fetchArray()[0], true);

$accessToken = new Token($accessTokenData);

// Add a callback to persist any refresh to the token.

$onPersist = function (OauthTokenInterface $accessToken) use ($authId, $db) {
    $statement = $db->prepare('replace into auth (id, token) values (:id, :token)');
    $statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
    $statement->bindValue(':token', json_encode($accessToken), \SQLITE3_TEXT);
    $statement->execute();
};

// Tell the client how to persist token refreshes.

$oauth1Token = $oauth1Token->withOnPersist($onPersist);

// We will add a guard time of five minutes.
// The token will be renewed five minutes early each cycle just to
// cut down on the round-trip API accesses.

$oauth1Token = $oauth1Token->withGuardTimeSeconds(60 * 5);
```

Now we set up a Partner application client.

```php
use Consilience\XeroApi\Client\App\Partner;

// This will create a PSR-18 client decorator.
// Just use it like a PSR-18 client.

// $client can be a Psr\Http\Client\ClientInterface PSR-18 client
// of your choice, or `null` for auto-discovery.

$partner = new Partner($client, $oauth1Token, [
    // The key and secret are needed for signing.
    'consumer_key'    => 'PQ4351VSH4FHXTJTPN3JBBBNYSAYXM',
    'consumer_secret' => '1FWE9NCU8SYB8S9ROFDTCUDCC3UXMF',
    // RSA is required for Xero.
    'signature_method' => Partner::SIGNATURE_METHOD_RSA,
    // Partner key file
    'private_key_file' => 'certs/privatekey.pem',
    'private_key_passphrase' => '',
]);
```

The application should be provided, which is used as a User Agent.
This helps Xero when they look at their logs.

    $partner = $partner->withApplicationName('My Ace Application');

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
when instantiating (example TODO).

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

$response = $partner->sendRequest(
    $messageFactory->createRequest(
        'GET',
        'https://api.xero.com/api.xro/2.0/organisation'
    )->withHeader('Accept', 'application/json')
);

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
      ["IsDemoCompany"]=>
      bool(false)
      ["OrganisationStatus"]=>
      string(6) "ACTIVE"
      ["RegistrationNumber"]=>
      string(0) ""
      ["TaxNumber"]=>
      string(11) "12345678910"
      ["FinancialYearEndDay"]=>
      int(31)
      ["FinancialYearEndMonth"]=>
      int(3)
      ["SalesTaxBasis"]=>
      string(7) "ACCRUAL"
      ["SalesTaxPeriod"]=>
      string(9) "QUARTERLY"
      ["DefaultSalesTax"]=>
      string(13) "Tax Exclusive"
      ["DefaultPurchasesTax"]=>
      string(13) "Tax Exclusive"
      ["CreatedDateUTC"]=>
      string(21) "/Date(1436961673000)/"
      ["OrganisationEntityType"]=>
      string(7) "COMPANY"
      ["Timezone"]=>
      string(3) "UTC"
      ["ShortCode"]=>
      string(6) ""
      ["OrganisationID"]=>
      string(36) "UUID-REDACTED"
      ["Edition"]=>
      string(8) "BUSINESS"
      ["Class"]=>
      string(7) "STARTER"
      ["LineOfBusiness"]=>
      string(20) "Software Development"
      ["Addresses"]=>
      array(2) {
        [0]=>
        array(7) {
          ["AddressType"]=>
          string(6) "STREET"
          ["AddressLine1"]=>
          string(10) "1 No Place"
          ["City"]=>
          string(9) "Edinburgh"
          ["Region"]=>
          string(0) ""
          ["PostalCode"]=>
          string(0) ""
          ["Country"]=>
          string(2) "UK"
          ["AttentionTo"]=>
          string(0) ""
        }
      }
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
(if using `POST`) you can access all parts of the API.
Token renewals - for the Partner application at least - will be handled
for you automatically.

Another package will handle the payload parsing and request building.
This package is just concerned with the HTTP access with OAuth 1.0a
credentials.

## TODO

* Tests (as usual).
* Support multiple discovery packages.
* Is there a better way of handling key files, perhaps as streams?
* Some better exception handling, so we can catch a failed token, redacted
  authorisation, general network error etc and handle appropriately.
* Configuration should probably be an class rather than an array. It would
  be self documenting, validating, serialisable etc.

