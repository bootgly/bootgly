<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


/**
 * Issued refresh-token snapshot.
 */
class Token
{
   // * Data
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
      string $refresh,
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
