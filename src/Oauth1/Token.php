<?php

namespace Consilience\XeroApi\Client\Oauth1;

/**
 * Token details for a current OAuth1 authorisation, or any stage
 * in the OAuth authorisation flow (temporary token, long-term token,
 * renewed token, errors, failures, etc.)
 *
 * Does not hold details of keys needed for renewal; holds just
 * the current state.
 */

use Consilience\XeroApi\Client\OauthTokenInterface;

class Token implements OauthTokenInterface
{
    /**
     * @var array Custom properties
     */
    protected $customProperties = [];

    // Properties for persisting.

    protected $oauthToken;
    protected $oauthTokenSecret;
    protected $oauthSessionHandle;
    protected $oauthExpiresAt;
    protected $oauthAuthorizationExpiresAt;
    protected $xeroOrgMuid;

    // Error properties not for persisting.

    protected $oauthProblem;
    protected $oauthProblemAdvice;

    /**
     * @var array All scalar properties for persisting
     */
    protected $propertyNames = [
        'oauth_token',
        'oauth_token_secret',
        'oauth_session_handle',
        'oauth_expires_at',
        'oauth_authorization_expires_at',
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

    /**
     * @param array $tokenData OAuth parameters as an associative array
     * @param callable|null $onPersist function to save the new token on renewal
     */
    public function __construct(array $tokenData = [], ?callable $onPersist = null)
    {
        $this->setTokenData($tokenData);

        if ($onPersist !== null) {
            $this->setOnPersist($onPersist);
        }
    }

    // TODO: fromServerRequest() and fromResponse() to parse tokens or errors
    // coming from Xero.

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

        $setterName = 'set' . ucfirst($property);

        if (method_exists($this, $setterName)) {
            return $this->$setterName($value);
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
    protected function setOauthToken(string $oauthToken): self
    {
        // Check if this is an refreshed token.

        if ($this->oauthToken === null || $this->oauthToken === $oauthToken) {
            // Token is new, or has not changed.

            $this->refreshedFlag = false;
        } else {
            $this->refreshedFlag = true;
        }

        $this->oauthToken = $oauthToken;
        return $this;
    }

    public function getOauthToken(): ?string
    {
        return $this->oauthToken;
    }

    public function withOauthToken(string $oauthToken): self
    {
        // FIXME: in two minds about which to use.

        return (clone $this)->setOauthToken($oauthToken);
        //return $this->with('oauthToken', $oauthToken);
    }

    /**
     * Get/set/with token secret.
     */
    protected function setOauthTokenSecret(string $oauthTokenSecret): self
    {
        $this->oauthTokenSecret = $oauthTokenSecret;
        return $this;
    }

    public function getOauthTokenSecret(): ?string
    {
        return $this->oauthTokenSecret;
    }

    public function withOauthTokenSecret(string $oauthTokenSecret): self
    {
        return (clone $this)->setOauthTokenSecret($oauthTokenSecret);
    }

    /**
     * Get/set/with session handle.
     */
    protected function setOauthSessionHandle(string $oauthSessionHandle): self
    {
        $this->oauthSessionHandle = $oauthSessionHandle;
        return $this;
    }

    public function getOauthSessionHandle(): ?string
    {
        return $this->oauthSessionHandle;
    }

    public function withOauthSessionHandle(string $oauthSessionHandle): self
    {
        return (clone $this)->setOauthSessionHandle($oauthSessionHandle);
    }

    /**
     * Get/set/with expires at.
     */
    protected function setOauthExpiresAt(int $oauthExpiresAt): self
    {
        $this->oauthExpiresAt = $oauthExpiresAt;
        return $this;
    }

    /**
     * Time the token expires.
     * Normally lasts 30 minutes.
     *
     * @return int unixtimestamp
     */
    public function getOauthExpiresAt(): ?int
    {
        return $this->oauthExpiresAt;
    }

    public function withOauthExpiresAt(int $oauthExpiresAt): self
    {
        return (clone $this)->setOauthExpiresAt($oauthExpiresAt);
    }

    /**
     * Get/set/with expires in.
     * The expiresIn value is never stored, as it is no real use.
     * It is converted immediately to expiresAt, an absolute time.
     */
    protected function setOauthExpiresIn(int $oauthExpiresIn): self
    {
        $this->oauthExpiresAt = $oauthExpiresIn + time();
        return $this;
    }

    public function getOauthExpiresIn(): ?int
    {
        $oauthExpiresAt = $this->getOauthExpiresAt();

        if (is_integer($oauthExpiresAt)) {
            return $oauthExpiresAt - time();
        }

        return null;
    }

    public function withOauthExpiresIn(int $oauthExpiresIn): self
    {
        return (clone $this)->setOauthExpiresIn($oauthExpiresIn);
    }

    /**
     * Get/set/with authorization expires at.
     */
    protected function setOauthAuthorizationExpiresAt(int $oauthAuthorizationExpiresAt): self
    {
        $this->oauthAuthorizationExpiresAt = $oauthAuthorizationExpiresAt;
        return $this;
    }

    /**
     * Time the authorisation expires.
     * Currently practically indefinite (several decades).
     *
     * @return int unixtimestamp
     */
    public function getOauthAuthorizationExpiresAt(): ?int
    {
        return $this->oauthAuthorizationExpiresAt;
    }

    public function withOauthAuthorizationExpiresAt(int $oauthAuthorizationExpiresAt): self
    {
        return (clone $this)->setOauthAuthorizationExpiresAt($oauthAuthorizationExpiresAt);
    }

    /**
     * Get/set/with authorization expires in.
     */
    protected function setOauthAuthorizationExpiresIn(int $oauthAuthorizationExpiresIn): self
    {
        $this->oauthAuthorizationExpiresAt = $oauthAuthorizationExpiresIn + time();
        return $this;
    }

    public function getOauthAuthorizationExpiresIn(): ?int
    {
        $oauthAuthorizationExpiresAt = $this->getOauthAuthorizationExpiresAt();

        if (is_integer($oauthAuthorizationExpiresAt)) {
            return $oauthAuthorizationExpiresAt - time();
        }

        return null;
    }

    public function withOauthAuthorizationExpiresIn(int $oauthauthorizationExpiresIn): self
    {
        return (clone $this)->setOauthAuthorizationExpiresIn($oauthAuthorizationExpiresIn);
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
     * Get/set/with OAuth error.
     */
    protected function setOauthProblem(string $oauthProblem): self
    {
        $this->oauthProblem = $oauthProblem;
        return $this;
    }

    /**
     * @return string|null the OAuth error, if any
     */
    public function getOauthProblem(): ?string
    {
        return $this->oauthProblem;
    }

    public function withOauthProblem(string $oauthProblem): self
    {
        return (clone $this)->setOauthProblem($oauthProblem);
    }

    /**
     * Undocumented, some results come back with an "error"
     * GET parameter. Map them onto an OAuth error.
     */
    public function setError(string $error): self
    {
        $this->oauthProblem = $error;
        return $this;
    }

    /**
     * Get/set/with OAuth reason.
     */
    protected function setOauthProblemAdvice(string $oauthProblemAdvice): self
    {
        $this->oauthProblemAdvice = $oauthProblemAdvice;
        return $this;
    }

    /**
     * @return string|null the OAuth reason, if any
     */
    public function getOauthProblemAdvice(): ?string
    {
        return $this->oauthProblemAdvice;
    }

    public function withOauthProblemAdvice(string $oauthProblemAdvice): self
    {
        return (clone $this)->setOauthProblemAdvice($oauthProblemAdvice);
    }

    /**
     * Undocumented, some results come back with an "error_description"
     * GET parameter. Map them onto an OAuth error.
     */
    public function setErrorDescription(string $errorDescription): self
    {
        $this->oauthProblemAdvice = $errorDescription;
        return $this;
    }

    /**
     * @return bool true if the object contains an OAuth error
     */
    public function isError()
    {
        return $this->oauthProblem !== null;
    }

    /**
     * @return bool true if the object contains an OAuth token
     */
    public function isSuccess()
    {
        return $this->oauthToken !== null;
    }

    /**
     * Convenience method.
     */
    public function getErrorCode()
    {
        return $this->getOauthProblem();
    }

    /**
     * Convenience method.
     */
    public function getErrorReason()
    {
        return $this->getOauthProblemAdvice();
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

                if ($token !== $this->getOauthToken()) {
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
        $oauthExpiresAt = $this->oauthExpiresAt;

        if ($oauthExpiresAt === null) {
            return null;
        }

        return time() >= $oauthExpiresAt - $this->guardTimeSeconds;
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
     * @return string Properties as JSON, for persisting in storage
     */
    public function jsonSerialize() {
        return $this->getTokenData();
    }
}
