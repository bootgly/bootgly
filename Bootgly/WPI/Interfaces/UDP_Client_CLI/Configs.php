<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use ArgumentCountError;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Configs as Configuring;


/**
 * Transport configuration of a UDP Client.
 *
 * Handed to `UDP_Client_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('127.0.0.1', 8080)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs implements Configuring
{
   // * Config
   /** Domain name or IP address to connect to. */
   public private(set) string $host;
   /** Port number to connect to. */
   public private(set) int $port;
   /** Number of worker processes to fork. */
   public private(set) int $workers;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    *
    * @throws ArgumentCountError When `host` or `port` is missing.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      int $workers = 0
   )
   {
      // ? Required — the guard slot forces every parameter to be optional, so
      //   the mandatory ones are enforced here instead of by the engine.
      if ($host === null || $port === null) {
         $Configs = static::class;

         throw new ArgumentCountError(
            "{$Configs} requires the named arguments: host, port."
         );
      }

      // * Config
      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;
   }
}
