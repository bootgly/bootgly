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
 * Refresh-token replay incident snapshot.
 */
class Replay
{
   // * Data
   public private(set) string $subject;
   public private(set) string $family;
   /**
    * Application claims copied from the replayed refresh family.
    *
    * @var array<string,mixed>
    */
   public private(set) array $claims;
   public private(set) int $expires;


   /**
    * Create a refresh-token replay incident.
    *
    * @param array<string,mixed> $claims
    */
   public function __construct (string $subject, string $family, array $claims, int $expires)
   {
      // * Data
      $this->subject = $subject;
      $this->family = $family;
      $this->claims = $claims;
      $this->expires = $expires;
   }
}
