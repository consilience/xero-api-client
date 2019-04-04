# Xero API

API package for Xero authenticated access, PSR-7, PSR-15, PSR-18.

Handles the OAuth 1.0a requests, and token renewals for the *partner application*.
It does this by offering a decorated PSR-18 

Features include:

* Support for *Partner Application* only at present.
* Automatic token renewal by time (including guard time) or on an expired token.
* Callback to the application for persistence of the OAuth1 token object.
* This package does not include the user authorisation flow. It is for back-end
  processes to use the authorisation tokens in an agnostic way.

## Simple Usage

This example is for a Partner application with authentication token details persisted
in a simple SQLite3 database.

First create the database.

```php
$db = new SQLite3('auth.db');
$db->exec('create table if not exists auth(id integer primary key, token text)');
```

They get authenticated and use the token details to initialise the auth session.
This will be covered later, but for now, save the following details.

```php
// The minimum details to capture from the authorisation.

$tokenCredentials = [
    'token'             => 'SFH1YSOS1QGZ8LAY3UTA4AXPLXAEH6',
    'token_secret'      => 'APUQGGU3FDAV6YUYZAYKA5RDPZ9SBQ',
    'session_handle'    => 'D1QQAZDCP28VLCGVUVWO',
    'expires_at'        => 1868781168,
];

// Now store it in the database.
// We will store it against authentication ID 123.

$authId = 123;

$db = new SQLite3('auth.db');

$statement = $db->prepare('replace into auth (id, token) values (:id, :token)');
$statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
$statement->bindValue(':token', $tokenCredentials, \SQLITE3_TEXT);
$statement->execute();
```

That gets the ball rolling.
Each time we want to access the API, or update the token, we will do so
in this example against authentication 123.

So, to access the API, start by creating an OAuth 1.0 object.

```php
use Consilience\XeroApi\Oauth1\Token;

// Get the current token details from the database.

$authId = 123;

$db = new SQLite3('auth.db');

$statement = $db->prepare('select token from auth where id = :id');
$statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
$result = $statement->execute();
$tokenCredentials = json_decode($result->fetchArray()[0], true);

$oauth1Token = new Oauth1Token($tokenCredentials);

// Add a callback to persist any refresh to the token.

$onPersist = function (OauthTokenInterface $oauth1Token) use ($authId, $db) {
    $statement = $db->prepare('replace into auth (id, token) values (:id, :token)');
    $statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
    $statement->bindValue(':token', json_encode($oauth1Token), \SQLITE3_TEXT);
    $statement->execute();
};

// Tell the client how to persist any token refreshes.

$oauth1Token = $oauth1Token->withOnPersist($onPersist);

// We will add a guard time of five minutes.
// The token will be renewed five minutes early each cycle just to
// cut down on the round-trip API accesses.

$oauth1Token = $oauth1Token->withGuardTimeSeconds(60 * 5);
```

Now we set up a Partner application client.

```php
use Consilience\XeroApi\Client\Partner;

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
    'private_key_file' => 'certs/partner-app/privatekey.pem',
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

TODO: simple example of authorising access to an organisation using
League OAuth 1.0 + Xero plugin.

## TODO

* Tests (as usual).
* Support multiple discovery packages.
* Is there a better way of handling key files, perhaps as streams?
* Some better exception handling, so we can catch a failed token, redacted
  authorisation, general network error etc and handle appropriately.
* Configuration should probably be an class rather than an array. It would
  be self documenting, validating, serialisable etc.

