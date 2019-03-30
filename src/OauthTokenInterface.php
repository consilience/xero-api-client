<?php

namespace Consilience\XeroApi;

/**
 *
 */

use \JsonSerializable;

interface OauthTokenInterface extends JsonSerializable
{
    public function getToken(): ?string;
    public function isExpired(): ?bool;
    public function getSessionHandle(): ?string;
    public function withTokenData(array $tokenData): OauthTokenInterface;
    public function getTokenData(): array;
    public function persist();
}
