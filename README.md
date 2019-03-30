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

$persistCallback = function (Oauth1TokenInterface $oauth1Token) use ($authId, $db) {
    $statement = $db->prepare('replace into auth (id, token) values (:id, :token)');
    $statement->bindValue(':id', $authId, \SQLITE3_INTEGER);
    $statement->bindValue(':token', json_encode($oauth1Token), \SQLITE3_TEXT);
    $statement->execute();
};

$oauth1Token = $oauth1Token->withPersistCallback($persistCallback);

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

To support Guzzle as the underlying PSR-18 client, you will need
to install the adapters through composer:

* guzzlehttp/psr7
* guzzlehttp/guzzle
* php-http/guzzle6-adapter
* http-interop/http-factory-discovery
* http-interop/http-factory-guzzle

Now we can make a request of the API.

```php
use Http\Discovery\MessageFactoryDiscovery;

$messageFactory = MessageFactoryDiscovery::find();

$response = $partner->sendRequest(
    $messageFactory->createRequest(
        'GET',
        'https://api.xero.com/api.xro/2.0/organisation'
    )->withHeader('Accept', 'application/json')
);

$payloadData = json_decode((string)$response->getBody(), true);
```

That's it. With the correct method, URL, `Accept` header and payload
(if using `POST`) you can access all parts of the API.
Token renewals - for the Partner application at least - will be handled
for you automatically.

Another package will handle the payload parsing and request building.
This package is just conerned with the HTTP access with OAuth 1.0a
credentials.

## TODO

* Tests (as usual).
* Agent property parameter (Xero like to see this set).
* "Fresh" functionality to allow updated tokens to be reloaded from persistence
  on all long-running processes accessing Xero.
* Locking mechanism to prevent multiple processes trying to update the OAuth
  token at the same time (the first should lock, refresh the token, then persist
  it, while the remaining processes will be signalled to fetch the fresh details).
* Allow injection of the URL factory.
* Support Private and Public applications.
* Support multiple discovery packages.
* Make all discovery packages optional. Discovery-ception is a risk, having to
  dicover the discovery package installed.

