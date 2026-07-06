<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
