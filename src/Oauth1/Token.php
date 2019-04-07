<?php

namespace Consilience\XeroApi\Oauth1;

/**
 * Token details for a current OAuth1 authorisation.
 * Also handles storage of refreshed tokens.
 * Does not hold details of keys needed for renewal; holds just
 * the current state.
 */

use Consilience\XeroApi\OauthTokenInterface;

class Token implements OauthTokenInterface
{
    // Custom properties.

    protected $customProperties = [];

    // Properties for persisting.

    protected $token;
    protected $tokenSecret;
    protected $sessionHandle;
    protected $expiresAt;
    protected $authorizationExpiresAt;
    protected $xeroOrgMuid;

    // All standard scalar properties for persisting.

    protected $propertyNames = [
        'token',
        'token_secret',
        'session_handle',
        'expires_at',
        'authorization_expires_at',
        'xero_org_muid',
    ];

    // Modifying or functional properties that are not persisted.

    protected $onPersist;
    protected $guardTimeSeconds = 0;

    protected $refreshedFlag = false;

    public function __construct(array $tokenData = [], ?callable $onPersist = null)
    {
        $this->setTokenData($tokenData);

        if ($onPersist !== null) {
            $this->setPersistCallback($onPersist);
        }
    }

    /**
     * Set a token property.
     *
     * @param string $name Snake case or camel case.
     * @param string|int the property value to store.
     * @return $this
     */
    protected function set(string $name, $value): self
    {
        $setterNames = [];
        $property = $this->snakeToCamel($name);

        $setterNames[] = 'set' . ucfirst($property);

        if (strpos($property, 'oauth_') === 0) {
            // If the name starts with 'oauth_' then try a setter
            // without that prefix.

            $setterNames[] = 'set' . ucfirst(substr($property, 0, 6));
        }

        foreach ($setterNames as $setterName) {
            if (method_exists($this, $setterName)) {
                return $this->$setterName($value);
            }
        }

        if (strpos($property, 'oauth_') === 0) {
            $setterName = 'set' . ucfirst(substr($property, 0, 6));

            if (method_exists($this, $setterName)) {
                return $this->$setterName($value);
            }
        }

        // Custom properties.

        if ($value === null) {
            unset($this->customProperties[$property]);
        } else {
            $this->customProperties[$property] = $value;
        }

        return $this;
    }

    /**
     * Get a token property.
     *
     * @param string $name Snake case or camel case.
     * @return mixed
     */
    public function get(string $name)
    {
        $property = $this->snakeToCamel($name);
        $getterName = 'get' . ucfirst($property);

        if (method_exists($this, $getterName)) {
            return $this->$getterName();
        }

        if (array_key_exists($property, $this->customProperties)) {
            return $this->customProperties[$property];
        }
    }

    /**
     * Set each of the properties.
     * TODO: a null should unset a property.
     */

    protected function setToken(string $token): self
    {
        // Check if this is an refreshed token.

        if ($this->token !== null && $this->token !== $token) {
            $this->refreshedFlag = true;
        }

        $this->token = $token;
        return $this;
    }

    protected function setTokenSecret(string $tokenSecret): self
    {
        $this->tokenSecret = $tokenSecret;
        return $this;
    }

    protected function setSessionHandle(string $sessionHandle): self
    {
        $this->sessionHandle = $sessionHandle;
        return $this;
    }

    protected function setExpiresAt(int $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    protected function setExpiresIn(int $expiresIn): self
    {
        $this->expiresAt = $expiresIn + time();
        return $this;
    }

    protected function setAuthorizationExpiresAt(int $authorizationExpiresAt): self
    {
        $this->authorizationExpiresAt = $authorizationExpiresAt;
        return $this;
    }

    protected function setAuthorizationExpiresIn(int $authorizationExpiresIn): self
    {
        $this->authorizationExpiresAt = $authorizationExpiresIn + time();
        return $this;
    }

    protected function setXeroOrgMuid(string $xeroOrgMuid): self
    {
        $this->xeroOrgMuid = $xeroOrgMuid;
        return $this;
    }

    protected function setOnPersist(callable $onPersist): self
    {
        $this->onPersist = $onPersist;
        return $this;
    }

    protected function setGuardTimeSeconds(int $guardTimeSeconds): self
    {
        $this->guardTimeSeconds = abs($guardTimeSeconds ?? 0);
        return $this;
    }

    /**
     * Get each of the properties.
     */

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getTokenSecret(): ?string
    {
        return $this->tokenSecret;
    }

    public function getSessionHandle(): ?string
    {
        return $this->sessionHandle;
    }

    /**
     * Time the token expires.
     * Normally lasts 30 minutes.
     *
     * @return int unixtimestamp
     */
    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }

    /**
     * Time the authorisation expires.
     * Currently practically indefinite (several decades).
     *
     * @return int unixtimestamp
     */
    public function getAuthorizationExpiresAt(): ?int
    {
        return $this->authorizationExpiresAt;
    }

    public function getXeroOrgMuid(): ?string
    {
        return $this->xeroOrgMuid;
    }

    public function getOnPersist(): ?callable
    {
        return $this->onPersist;
    }

    /**
     * @return int guard time in seconds; default is 0
     */
    public function getGuardTimeSeconds(): int
    {
        return $this->guardTimeSeconds;
    }

    /**
     * Change each of the properties (new instance returned).
     */

    public function withToken(string $token): self
    {
        return (clone $this)->setToken($token);
    }

    public function withTokenSecret(string $tokenSecret): self
    {
        return (clone $this)->setTokenSecret($tokenSecret);
    }

    public function withSessionHandle(string $sessionHandle): self
    {
        return (clone $this)->setSessionHandle($sessionHandle);
    }

    public function withExpiresAt(int $expiresAt): self
    {
        return (clone $this)->setExpiresAt($expiresAt);
    }

    public function withExpiresIn(int $expiresIn): self
    {
        return (clone $this)->setExpiresIn($expiresIn);
    }

    public function withAuthorizationExpiresAt(int $authorizationExpiresAt): self
    {
        return (clone $this)->setAuthorizationExpiresAt($authorizationExpiresAt);
    }

    public function withAuthorizationExpiresIn(int $authorizationExpiresIn): self
    {
        return (clone $this)->setAuthorizationExpiresIn($authorizationExpiresIn);
    }

    public function withXeroOrgMuid(string $xeroOrgMuid): self
    {
        return (clone $this)->setXeroOrgMuid($xeroOrgMuid);
    }

    public function withOnPersist(?callable $onPersist): self
    {
        return (clone $this)->setOnPersist($onPersist);
    }

    public function withGuardTimeSeconds(int $guardTimeSeconds): self
    {
        return (clone $this)->setGuardTimeSeconds($guardTimeSeconds);
    }

    /**
     * Magic getter for properties.
     *
     * @paran string $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    public function __isset($name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Indicates whether the token has been refreshed, then resets
     * the refresh flag. It will only return true once after a refresh.
     *
     * @return bool True if the token has been refreshed.
     */
    public function isRefreshed(): bool
    {
        if ($this->refreshedFlag) {
            $this->refreshedFlag = false;

            return true;
        }

        return false;
    }

    /**
     * @return array All scalar properties as an array.
     */
    public function getTokenData(): array
    {
        $tokenData = [];

        foreach ($this->propertyNames as $name) {
            if (($value = $this->get($name)) !== null) {
                $tokenData[$name] = $value;
            }
        }

        $tokenData = array_merge($tokenData, $this->customProperties);

        return $tokenData;
    }

    /**
     * @param array $tokenData Multiple properties as an array.
     * @return $this
     */
    protected function setTokenData(array $tokenData): self
    {
        foreach ($tokenData as $name => $value) {
            $this->set($name, $value);
        }
        return $this;
    }

    public function withTokenData(array $tokenData): OauthTokenInterface
    {
        return (clone $this)->setTokenData($tokenData);
    }

    /**
     * Check if the token has been updated by another process
     * in storage. If it has, return a new token with the "is updated"
     * flag set, otherwise return self.
     * CHECKME: should this also set a lock on updates in the application,
     * to be released when persisted, on next successful API call, or on
     * end of process?
     * Probably rename to reload()
     */
    public function fresh(): self
    {
        // TODO: will need to use an application callback.

        return $this;
    }

    /**
     * Persist the current token to storage.
     * Uses the application storage callback to do this.
     */
    public function persist()
    {
        if (is_callable($this->onPersist)) {
            ($this->onPersist)($this);
        }
    }

    /**
     * Checks if the token has expired, based on the expires_at value
     * and the current time.
     *
     * @return bool|null true if expired, false if not expired, null if not known
     */
    public function isExpired(): ?bool
    {
        $expiresAt = $this->expiresAt;

        if ($expiresAt === null) {
            return null;
        }

        return time() >= $expiresAt - $this->guardTimeSeconds;
    }

    /**
     * Convert a "snake_case" string to "camelCase".
     *
     * @param string $name
     * @return string
     */
    protected function snakeToCamel(string $name): string
    {
        return lcfirst(
            str_replace(
                '_',
                '',
                ucwords($name, '_')
            )
        );
    }

    /**
     * @return string Properties as JSON, for persistence or logging.
     */
    public function jsonSerialize() {
        return $this->getTokenData();
    }
}
