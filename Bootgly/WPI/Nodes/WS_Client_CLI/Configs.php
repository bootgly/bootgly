<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


use ArgumentCountError;

use Bootgly\ABI\Argument;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Configs as TCPConfigs;


/**
 * Client configuration of a WebSocket Client.
 *
 * Handed to `WS_Client_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * `workers` is inherited from the transport and kept for compatibility: the WS
 * client opens a single blocking connection via `connect()` and does not fork,
 * so it has no effect.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('127.0.0.1', 8083)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs extends TCPConfigs
{
   // * Config
   /** Seconds between automatic pings (`0` disables the heartbeat). */
   public private(set) int $heartbeatInterval;
   /** Maximum accepted frame payload in bytes. */
   public private(set) int $maxFrameSize;
   /** Maximum accepted (reassembled) message payload in bytes. */
   public private(set) int $maxMessageSize;
   /** permessage-deflate offer toggle. */
   public private(set) bool $compression;
   /** Auto re-dial after an abrupt drop (EOF / transport error). Off by default. */
   public private(set) bool $reconnect;
   /** Max reconnect attempts before giving up (`0` = unlimited). */
   public private(set) int $reconnectAttempts;
   /** Base backoff in seconds (doubles each attempt, capped). */
   public private(set) int $reconnectDelay;
   /** Backoff cap in seconds. */
   public private(set) int $reconnectMaxDelay;
   /**
    * Total wall-clock budget in seconds for the whole reconnect campaign
    * (`0` = unbounded). Guarantees the loop terminates even with unlimited
    * attempts, so a permanently dead port cannot re-dial forever.
    */
   public private(set) int $reconnectTimeout;
   /** Seconds to receive + verify the 101 after dialing (`0` = unbounded). */
   public private(set) int $handshakeTimeout;
   /**
    * Maximum seconds to drain a queued close frame. Zero force-closes
    * immediately when the frame cannot be written synchronously.
    */
   public private(set) float $closeTimeout;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<string,mixed> $secure Secure SSL/TLS Stream Context options (enables wss://).
    *
    * @throws ArgumentCountError When `host` or `port` is missing.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      int $workers = 0,
      null|array $secure = null,
      int $heartbeatInterval = 0,
      int $maxFrameSize = 1048576,
      int $maxMessageSize = 8388608,
      bool $compression = true,
      bool $reconnect = false,
      int $reconnectAttempts = 0,
      int $reconnectDelay = 1,
      int $reconnectMaxDelay = 30,
      int $reconnectTimeout = 60,
      int $handshakeTimeout = 10,
      float $closeTimeout = 5.0
   )
   {
      parent::__construct(
         host: $host,
         port: $port,
         workers: $workers,
         secure: $secure
      );

      // * Config
      // # Session policy
      $this->heartbeatInterval = $heartbeatInterval;
      $this->maxFrameSize = $maxFrameSize;
      $this->maxMessageSize = $maxMessageSize;

      $this->compression = $compression;

      // # Reconnect policy
      $this->reconnect = $reconnect;
      $this->reconnectAttempts = $reconnectAttempts;
      $this->reconnectDelay = $reconnectDelay;
      $this->reconnectMaxDelay = $reconnectMaxDelay;
      $this->reconnectTimeout = $reconnectTimeout;

      // # Handshake policy
      $this->handshakeTimeout = $handshakeTimeout;
      $this->closeTimeout = $closeTimeout;
   }
}
