<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


abstract class Logs
{
   /**
    * Log one or more messages, each at its own named severity level.
    *
    * @param string|array<string,mixed> ...$args One or more `level: message` pairs, plus optional `context: [...]`.
    * @return bool True once the records are handled.
    */
   abstract public function log (string|array ...$args): bool;
}
