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
 * JWT verification key resolver.
 */
interface KeyResolver
{
   /**
    * Resolve a key for a protected header `kid` and algorithm.
    */
   public function resolve (null|string $id, string $algorithm): null|Key;

   /**
    * Return the last resolver failure, if any.
    */
   public function fail (): null|Failures;
}
