<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\KV;


use Bootgly\ADI\Database\Driver as DatabaseDriver;
use Bootgly\ADI\Databases\KV\Operation;


/**
 * Key-value database wire driver base.
 */
abstract class Driver extends DatabaseDriver
{
   /**
    * A key-value server drops a disconnected client's work at once (a
    * command runs atomically, a blocked one is unblocked), so a withdrawn
    * command never holds its slot past the dropped session.
    */
   public const bool LINGERING = false;

   /**
    * Create a key-value command operation.
    *
    * @param array<int,mixed> $arguments
    */
   abstract public function command (string $command, array $arguments = []): Operation;
}
