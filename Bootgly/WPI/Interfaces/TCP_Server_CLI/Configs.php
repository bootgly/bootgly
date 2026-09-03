<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use ArgumentCountError;

use Bootgly\ABI\Argument;
use Bootgly\ABI\Configs as Configuring;


/**
 * Transport configuration of a TCP Server.
 *
 * Handed to `TCP_Server_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('0.0.0.0', 8080, 4)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs implements Configuring
{
   // * Config
   /** Domain name or IP address to bind. */
   public private(set) string $host;
   /** Port number to bind. */
   public private(set) int $port;
   /** Number of worker processes to fork. */
   public private(set) int $workers;
   /** @var null|array<string,mixed> Secure SSL/TLS Stream Context options. */
   public private(set) null|array $secure;
   /** User to drop privileges to after socket binding. */
   public private(set) null|string $user;
   /** Group to drop privileges to after socket binding. */
   public private(set) null|string $group;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<string,mixed> $secure Secure SSL/TLS Stream Context options.
    *
    * @throws ArgumentCountError When `host`, `port` or `workers` is missing.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      null|int $workers = null,
      null|array $secure = null,
      null|string $user = null,
      null|string $group = null
   )
   {
      // ? Required — the guard slot forces every parameter to be optional, so
      //   the mandatory ones are enforced here instead of by the engine.
      if ($host === null || $port === null || $workers === null) {
         $Configs = static::class;

         throw new ArgumentCountError(
            "{$Configs} requires the named arguments: host, port, workers."
         );
      }

      // * Config
      $this->host = $host;
      $this->port = $port;
      $this->workers = $workers;

      $this->secure = $secure;

      $this->user = $user;
      $this->group = $group;
   }
}
