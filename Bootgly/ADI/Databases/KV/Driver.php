<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
    * Create a key-value command operation.
    *
    * @param array<int,mixed> $arguments
    */
   abstract public function command (string $command, array $arguments = []): Operation;
}
