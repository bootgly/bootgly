<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


interface Handling
{
   public function read (string $sessionId): string|false;

   public function write (string $sessionId, string $sessionData): bool;

   public function touch (string $sessionId): bool;

   public function destroy (string $sessionId): bool;

   public function purge (int $maxLifetime): bool;
}
