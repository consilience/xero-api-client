<?php

namespace Consilience\XeroApi;

/**
 * Setting, getting and autodiscovery of HTTP clients and factories.
 * Keeps any discovery in one place, and keeps the accessors and
 * mutators consistent throughout.
 */

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Client\ClientInterface;

// Just for docblocks/.
use TypeError;

// HTTPlug discovery (php-http/discovery + adapters)
use Http\Discovery\Psr17FactoryDiscovery as HttplugFactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery as HttplugClientDiscovery;

// HTTP Interop discovery http-interop/http-factory-discovery + adapters
use Http\Factory\Discovery\HttpFactory as HttpInteropFactoryDiscovery;
use Http\Factory\Discovery\HttpClient as HttpInteropClientDiscovery;

trait HttpTrait
{
    /**
     * @var Http\Message\RequestFactoryInterface
     */
    protected $requestFactory;

    /**
     * @var Psr\Http\Message\UriFactoryInterface
     */
    protected $uriFactory;

    /**
     * @var Psr\Http\Client\ClientInterface
     */
    protected $client;

    /**
     * Return a PSR-17 Request factory, using discovery if necessary.
     *
     * @return RequestFactoryInterface
     * @throws TypeError if RequestFactory is not set or could be discovered
     */
    public function getRequestFactory(): RequestFactoryInterface
    {
        if ($this->requestFactory === null && class_exists(HttplugFactoryDiscovery::class)) {
            $this->requestFactory = HttplugFactoryDiscovery::findRequestFactory();
        }

        if ($this->requestFactory === null && class_exists(HttpInteropFactoryDiscovery::class)) {
            $this->requestFactory = HttpInteropFactoryDiscovery::requestFactory();
        }

        return $this->requestFactory;
    }

    protected function setRequestFactory(RequestFactoryInterface $requestFactory): self
    {
        $this->requestFactory = $requestFactory;
        return $this;
    }

    /**
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory to use
     * @return self clone of $this
     */
    public function withRequestFactory(RequestFactoryInterface $requestFactory): self
    {
        return (clone $this)->setRequestFactory($requestFactory);
    }

    /**
     * Return a PSR-17 URI factory, using discovery if necessary.
     *
     * @return RequestFactoryInterface
     * @throws TypeError if UriFactory is not set or could be discovered
     */
    public function getUriFactory(): UriFactoryInterface
    {
        if ($this->uriFactory === null && class_exists(HttplugFactoryDiscovery::class)) {
            $this->uriFactory = HttplugFactoryDiscovery::findUrlFactory();
        }

        if ($this->uriFactory === null && class_exists(HttpInteropFactoryDiscovery::class)) {
            $this->uriFactory = HttpInteropFactoryDiscovery::uriFactory();
        }

        return $this->uriFactory;
    }

    protected function setUriFactory(UriFactoryInterface $uriFactory): self
    {
        $this->uriFactory = $uriFactory;
        return $this;
    }

    /**
     * @param UriFactoryInterface $uriFactory PSR-17 uri factory to use
     * @return self clone of $this
     */
    public function withUriFactory(UriFactoryInterface $uriFactory): self
    {
        return (clone $this)->setUriFactory($uriFactory);
    }

    /**
     * Get the PSR-18 HTTP client, using discovery if necessary.
     *
     * @return ClientInterface the supplied client or auto-discovered
     * @throws TypeError if RequestFactory is not set or could be discovered
     */
    public function getClient(): ClientInterface
    {
        if ($this->client === null && class_exists(HttplugClientDiscovery::class)) {
            $this->client = HttplugClientDiscovery::find();
        }

        if ($this->client === null && class_exists(HttpInteropClientDiscovery::class)) {
            $this->client = HttpInteropClientDiscovery::client();
        }

        return $this->client;
    }

    protected function setClient(ClientInterface $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * @param ClientInterface $client PSR-18 client to use
     * @return self clone of $this
     */
    public function withClient(ClientInterface $client): self
    {
        return (clone $this)->setClient($client);
    }
}
