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
    /**
     * @var array Custom properties
     */
    protected $customProperties = [];

    // Properties for persisting.

    protected $token;
    protected $tokenSecret;
    protected $sessionHandle;
    protected $expiresAt;
    protected $authorizationExpiresAt;
    protected $xeroOrgMuid;

    /**
     * @var array All scalar properties for persisting
     */
    protected $propertyNames = [
        'token',
        'token_secret',
        'session_handle',
        'expires_at',
        'authorization_expires_at',
        'xero_org_muid',
    ];

    // Modifying or functional properties that are not persisted.

    /**
     * @var callback
     */
    protected $onPersist;

    /**
     * @var callback
     */
    protected $onReload;

    /**
     * @var int
     */
    protected $guardTimeSeconds = 0;

    /**
     * @var bool true if the token has been changed.
     */
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

    public function with(string $name, $value): self
    {
        return (clone $this)->set($name, $value);
    }

    /**
     * Get/set/with token.
     */
    protected function setToken(string $token): self
    {
        // Check if this is an refreshed token.

        if ($this->token === null || $this->token === $token) {
            // Token is new, or has not changed.

            $this->refreshedFlag = false;
        } else {
            $this->refreshedFlag = true;
        }

        $this->token = $token;
        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function withToken(string $token): self
    {
        // FIXME: in two minds about which to use.

        return (clone $this)->setToken($token);
        //return $this->with('token', $token);
    }

    /**
     * Get/set/with token secret.
     */
    protected function setTokenSecret(string $tokenSecret): self
    {
        $this->tokenSecret = $tokenSecret;
        return $this;
    }

    public function getTokenSecret(): ?string
    {
        return $this->tokenSecret;
    }

    public function withTokenSecret(string $tokenSecret): self
    {
        return (clone $this)->setTokenSecret($tokenSecret);
    }

    /**
     * Get/set/with session handle.
     */
    protected function setSessionHandle(string $sessionHandle): self
    {
        $this->sessionHandle = $sessionHandle;
        return $this;
    }

    public function getSessionHandle(): ?string
    {
        return $this->sessionHandle;
    }

    public function withSessionHandle(string $sessionHandle): self
    {
        return (clone $this)->setSessionHandle($sessionHandle);
    }

    /**
     * Get/set/with expires at.
     */
    protected function setExpiresAt(int $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
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

    public function withExpiresAt(int $expiresAt): self
    {
        return (clone $this)->setExpiresAt($expiresAt);
    }

    /**
     * Get/set/with expires in.
     * The expiresIn value is never stored, as it is no real use.
     * It is converted immediately to expiresAt, an absolute time.
     */
    protected function setExpiresIn(int $expiresIn): self
    {
        $this->expiresAt = $expiresIn + time();
        return $this;
    }

    public function getExpiresIn(): ?int
    {
        $expiresAt = $this->getExpiresAt();

        if (is_integer($expiresAt)) {
            return $expiresAt - time();
        }

        return null;
    }

    public function withExpiresIn(int $expiresIn): self
    {
        return (clone $this)->setExpiresIn($expiresIn);
    }

    /**
     * Get/set/with authorization expires at.
     */
    protected function setAuthorizationExpiresAt(int $authorizationExpiresAt): self
    {
        $this->authorizationExpiresAt = $authorizationExpiresAt;
        return $this;
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

    public function withAuthorizationExpiresAt(int $authorizationExpiresAt): self
    {
        return (clone $this)->setAuthorizationExpiresAt($authorizationExpiresAt);
    }

    /**
     * Get/set/with authorization expires in.
     */
    protected function setAuthorizationExpiresIn(int $authorizationExpiresIn): self
    {
        $this->authorizationExpiresAt = $authorizationExpiresIn + time();
        return $this;
    }

    public function getAuthorizationExpiresIn(): ?int
    {
        $authorizationExpiresAt = $this->getAuthorizationExpiresAt();

        if (is_integer($authorizationExpiresAt)) {
            return $authorizationExpiresAt - time();
        }

        return null;
    }

    public function withAuthorizationExpiresIn(int $authorizationExpiresIn): self
    {
        return (clone $this)->setAuthorizationExpiresIn($authorizationExpiresIn);
    }

    /**
     * Get/set/with xero org muid.
     */
    protected function setXeroOrgMuid(string $xeroOrgMuid): self
    {
        $this->xeroOrgMuid = $xeroOrgMuid;
        return $this;
    }

    public function getXeroOrgMuid(): ?string
    {
        return $this->xeroOrgMuid;
    }

    public function withXeroOrgMuid(string $xeroOrgMuid): self
    {
        return (clone $this)->setXeroOrgMuid($xeroOrgMuid);
    }

    /**
     * Get/set/with onPersist.
     */
    protected function setOnPersist(callable $onPersist): self
    {
        $this->onPersist = $onPersist;
        return $this;
    }

    public function getOnPersist(): ?callable
    {
        return $this->onPersist;
    }

    public function withOnPersist(?callable $onPersist): self
    {
        return (clone $this)->setOnPersist($onPersist);
    }

    /**
     * Get/set/with onReload.
     */
    protected function setOnReload(callable $onReload): self
    {
        $this->onReload = $onReload;
        return $this;
    }

    public function getOnReload(): ?callable
    {
        return $this->onReload;
    }

    public function withOnReload(?callable $onReload): self
    {
        return (clone $this)->setOnReload($onReload);
    }

    /**
     * Get/set/with guard time seconds.
     */
    protected function setGuardTimeSeconds(int $guardTimeSeconds): self
    {
        $this->guardTimeSeconds = abs($guardTimeSeconds ?? 0);
        return $this;
    }

    /**
     * @return int guard time in seconds; default is 0
     */
    public function getGuardTimeSeconds(): int
    {
        return $this->guardTimeSeconds;
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
     * @return array All scalar properties
     */
    public function getTokenData(): array
    {
        $tokenData = [];

        foreach ($this->propertyNames as $name) {
            if (($value = $this->get($name)) !== null) {
                $tokenData[$name] = $value;
            }
        }

        $tokenData = array_merge(
            $tokenData,
            array_filter($this->customProperties, function($value) {
                return is_scalar($value);
            })
        );

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
     * Check in case the token has been updated by another process
     * and stored. If it has, return a new token with the "is updated"
     * flag set, otherwise return self.
     */
    public function reload(): self
    {
        // TODO: will need to use an application callback.
        // The callback just needs to ask for the latest token data,
        // and this package can check if it has changed.
        // The callback could do this check if it wants and indicate
        // that there is no change to the token data.
        //
        // If new data was reloaded, then a flag should be set to indicate
        // that this has happened.

        if (is_callable($this->onreload)) {
            $tokenData = ($this->onReload)($this);

            // The callback will return an array of token data.
            // We are interested in if the main token has changed.

            if (is_array($tokenData)) {
                $token = $tokenData['token'] ?? $tokenData['oauth_token'] ?? '';

                if ($token !== $this->getToken()) {
                    // Token has changed so return a new token object
                    // instantiated with the new data.

                    return $this->withTokenData($tokenData);
                }
            }

            return $this;
        }

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
