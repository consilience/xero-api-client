<?php

namespace Consilience\XeroApi\Client;

/**
 *
 */

use \JsonSerializable;

interface OauthTokenInterface extends JsonSerializable
{
    public function getOauthToken(): ?string;
    public function isExpired(): ?bool;
    public function getOauthSessionHandle(): ?string;
    public function withTokenData(array $tokenData): OauthTokenInterface;
    public function getTokenData(): array;
    public function persist();
}
