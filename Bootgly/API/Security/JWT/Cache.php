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


use Closure;


/**
 * JWT-local shared cache/store contract.
 */
interface Cache
{
   /**
    * Run a critical cache section under an exclusive lock.
    */
   public function lock (Closure $Closure): mixed;

   /**
    * Read a non-expired value.
    */
   public function read (string $key): null|string;

   /**
    * Write a value with a positive TTL.
    */
   public function write (string $key, string $value, int $ttl): bool;

   /**
    * Write only when the key does not already hold a non-expired value.
    */
   public function claim (string $key, string $value, int $ttl): bool;

   /**
    * Atomically read and delete a non-expired value.
    */
   public function take (string $key): null|string;

   /**
    * Delete a value.
    */
   public function delete (string $key): bool;

   /**
    * Purge expired values.
    */
   public function purge (): bool;
}
