<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function is_callable;

use Bootgly\API\Security\Authorization\Policy;


/**
 * Transport-agnostic authorization policy evaluator.
 */
class Authorization
{
   /**
    * Authorize an identity against a resource policy action.
    */
   public function authorize (Identity $Identity, Policy $Policy, string $action, mixed $Resource = null): bool
   {
      // @ Global policy override.
      $override = $Policy->override($Identity, $Resource);
      if ($override !== null) {
         return $override;
      }

      if (is_callable([$Policy, $action]) === false) {
         return false;
      }

      // : Action-specific decision.
      return $Policy->$action($Identity, $Resource) === true;
   }
}
