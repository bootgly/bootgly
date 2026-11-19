<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


use SensitiveParameter;

use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;


/**
 * Issued refresh-token snapshot.
 *
 * The refresh token never shows in a debug dump or an exception trace.
 */
class Token
{
   use Redactable;


   // * Data
   #[Secret]
   public private(set) string $refresh;
   public private(set) string $subject;
   public private(set) string $family;
   /**
    * Application claims copied into refreshed access tokens.
    *
    * @var array<string,mixed>
    */
   public private(set) array $claims;
   public private(set) int $issued;
   public private(set) int $expires;


   /**
    * Create a refresh-token snapshot.
    *
    * @param array<string,mixed> $claims
    */
   public function __construct (
      #[SensitiveParameter] string $refresh,
      string $subject,
      string $family,
      array $claims,
      int $issued,
      int $expires
   )
   {
      // * Data
      $this->refresh = $refresh;
      $this->subject = $subject;
      $this->family = $family;
      $this->claims = $claims;
      $this->issued = $issued;
      $this->expires = $expires;
   }
}
