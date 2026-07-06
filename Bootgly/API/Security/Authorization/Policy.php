<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Authorization;


use Bootgly\API\Security\Identity;


/**
 * Base resource authorization policy.
 *
 * Action methods return `true` to allow, `false` to deny, or `null` when the
 * policy has no opinion. The authorization engine treats `null` as denial.
 */
abstract class Policy
{
   /**
    * Run a global pre-check before the requested action.
    */
   public function override (Identity $Identity, mixed $Resource = null): null|bool
   {
      return null;
   }

   /**
    * Check whether an identity may view a resource.
    */
   public function view (Identity $Identity, mixed $Resource = null): null|bool
   {
      return null;
   }

   /**
    * Check whether an identity may create a resource.
    */
   public function create (Identity $Identity, mixed $Resource = null): null|bool
   {
      return null;
   }

   /**
    * Check whether an identity may update a resource.
    */
   public function update (Identity $Identity, mixed $Resource = null): null|bool
   {
      return null;
   }

   /**
    * Check whether an identity may delete a resource.
    */
   public function delete (Identity $Identity, mixed $Resource = null): null|bool
   {
      return null;
   }
}
