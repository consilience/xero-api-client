<?php

namespace Consilience\XeroApi;

/**
 * Setting, getting and autodiscovery of HTTP clients and factories.
 */

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Client\ClientInterface;

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

    public function withRequestFactory(RequestFactoryInterface $requestFactory): self
    {
        return (clone $this)->setRequestFactory($requestFactory);
    }

    /**
     * Return a PSR-17 URI factory, using discovery if necessary.
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

    public function withUriFactory(UriFactoryInterface $uriFactory): self
    {
        return (clone $this)->setUriFactory($uriFactory);
    }

    /**
     * Get the PSR-18 HTTP client.
     * The client is lazy-discovered.
     *
     * @return ClientInterface the supplied client or auto-discovered
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

    public function withClient(ClientInterface $client): self
    {
        return (clone $this)->setClient($client);
    }
}
