<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tokens;


use SensitiveParameter;

use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;


/**
 * Minted opaque token snapshot.
 *
 * `value` ("selector.verifier") is the only exposure of the raw secret —
 * stores persist the selector and the sha256 digest of the verifier. It never
 * shows in a debug dump or an exception trace.
 */
class Token
{
   use Redactable;


   // * Data
   #[Secret]
   public private(set) string $value;
   public private(set) string $selector;
   public private(set) string $user;
   public private(set) null|Purposes $Purpose;
   public private(set) int $expires;


   public function __construct (
      #[SensitiveParameter] string $value,
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
