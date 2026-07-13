<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tokens;


/**
 * Persistent-login theft incident.
 *
 * Returned by `Trust::rotate()` when a known series is presented with a
 * wrong validator — the stolen-cookie signature. Every trusted device of
 * the affected user is revoked before this incident is returned.
 */
class Theft
{
   // * Data
   public private(set) string $user;


   public function __construct (string $user)
   {
      // * Data
      $this->user = $user;
   }
}
