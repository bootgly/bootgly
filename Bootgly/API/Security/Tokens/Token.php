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
 * Minted opaque token snapshot.
 *
 * `value` ("selector.verifier") is the only exposure of the raw secret —
 * stores persist the selector and the sha256 digest of the verifier.
 */
class Token
{
   // * Data
   public private(set) string $value;
   public private(set) string $selector;
   public private(set) string $user;
   public private(set) null|Purposes $Purpose;
   public private(set) int $expires;


   public function __construct (
      string $value,
      string $selector,
      string $user,
      null|Purposes $Purpose,
      int $expires
   )
   {
      // * Data
      $this->value = $value;
      $this->selector = $selector;
      $this->user = $user;
      $this->Purpose = $Purpose;
      $this->expires = $expires;
   }
}
