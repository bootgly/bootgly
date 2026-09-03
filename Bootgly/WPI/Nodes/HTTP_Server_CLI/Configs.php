<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use InvalidArgumentException;

use Bootgly\ABI\Argument;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs as TCPConfigs;


/**
 * Server configuration of an HTTP Server.
 *
 * Handed to `HTTP_Server_CLI->configure()` alongside `Request\Configs` and
 * `Response\Configs`, in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('0.0.0.0', 8080, 4)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs extends TCPConfigs
{
   // * Config
   /** Auto-TLS runtime; mutually exclusive with a manual `secure` context. */
   public private(set) null|AutoTLS $AutoTLS;
   /** HTTP/2 support: `false` serves HTTP/1.x only (no ALPN offer, no h2c preface). */
   public private(set) null|bool $enableHTTP2;
   /** Health endpoint path (e.g. `/health`); `null` keeps it off. */
   public private(set) null|string $health;
   /** Maximum established connections per worker. */
   public private(set) null|int $maxConnections;
   /** Maximum established connections per client IP. */
   public private(set) null|int $maxConnectionsPerIP;
   /** Seconds of transport silence before a connection is closed (`0` disables). */
   public private(set) null|int $connectionIdleTimeout;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<string,mixed> $secure Secure SSL/TLS Stream Context options.
    *
    * @throws InvalidArgumentException When both `secure` and `AutoTLS` are given.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      null|int $workers = null,
      null|array $secure = null,
      null|string $user = null,
      null|string $group = null,
      null|AutoTLS $AutoTLS = null,
      null|bool $enableHTTP2 = null,
      null|string $health = null,
      null|int $maxConnections = null,
      null|int $maxConnectionsPerIP = null,
      null|int $connectionIdleTimeout = null
   )
   {
      // ? One TLS source — a manual context and Auto-TLS cannot both own it
      if ($secure !== null && $AutoTLS !== null) {
         $Configs = static::class;

         throw new InvalidArgumentException(
            "{$Configs} accepts either `secure` or `AutoTLS`, never both."
         );
      }

      parent::__construct(
         host: $host,
         port: $port,
         workers: $workers,
         secure: $secure,
         user: $user,
         group: $group
      );

      // * Config
      $this->AutoTLS = $AutoTLS;

      $this->enableHTTP2 = $enableHTTP2;
      $this->health = $health;

      $this->maxConnections = $maxConnections;
      $this->maxConnectionsPerIP = $maxConnectionsPerIP;
      $this->connectionIdleTimeout = $connectionIdleTimeout;
   }
}
