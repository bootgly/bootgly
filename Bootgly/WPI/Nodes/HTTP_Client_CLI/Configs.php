<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Client_CLI;


use ArgumentCountError;

use Bootgly\ABI\Argument;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Configs as TCPConfigs;


/**
 * Client configuration of an HTTP Client.
 *
 * Handed to `HTTP_Client_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('127.0.0.1', 8080)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs extends TCPConfigs
{
   // * Config
   /** @var null|array<string,int> Connection pool bounds: `['min' => N, 'max' => N]`. */
   public private(set) null|array $pool;
   /** HTTP/2 negotiation (`null` = ALPN when secure; `true` = also h2c; `false` = never). */
   public private(set) null|bool $enableHTTP2;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<string,mixed> $secure Secure SSL/TLS Stream Context options.
    * @param null|array<string,int> $pool Connection pool bounds: `['min' => N, 'max' => N]`.
    *
    * @throws ArgumentCountError When `host` or `port` is missing.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      int $workers = 0,
      null|array $secure = null,
      null|array $pool = null,
      null|bool $enableHTTP2 = null
   )
   {
      parent::__construct(
         host: $host,
         port: $port,
         workers: $workers,
         secure: $secure
      );

      // * Config
      $this->pool = $pool;

      $this->enableHTTP2 = $enableHTTP2;
   }
}
