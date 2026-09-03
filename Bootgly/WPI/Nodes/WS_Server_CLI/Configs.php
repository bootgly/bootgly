<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use Closure;

use Bootgly\ABI\Argument;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs as TCPConfigs;


/**
 * Server configuration of a WebSocket Server.
 *
 * Handed to `WS_Server_CLI->configure()`, which accepts one Configs per
 * concern and applies them in any order.
 *
 * Named arguments only: `$Named` is a guard slot no named call ever fills, so
 * a positional `new Configs('0.0.0.0', 8080, 4)` is rejected by the engine
 * (and flagged by static analysis) before it can silently bind the wrong
 * values.
 */
class Configs extends TCPConfigs
{
   // * Config
   // # Session policy
   /** Seconds between heartbeat pings sent to an idle peer. */
   public private(set) int $heartbeatInterval;
   /** Seconds of silence before a session is closed; `null` keeps it off. */
   public private(set) null|int $idleTimeout;
   /** Maximum size, in bytes, of a single inbound frame. */
   public private(set) int $maxFrameSize;
   /** Maximum size, in bytes, of a reassembled inbound message. */
   public private(set) int $maxMessageSize;
   // # Handshake policy
   /** @var array<string> Server-supported subprotocols, in preference order. */
   public private(set) array $subprotocols;
   /** Per-message deflate (RFC 7692) negotiation. */
   public private(set) bool $compression;
   /** @var array<object> Handshake authentication guards. */
   public private(set) array $Guards;
   // # Connection-exhaustion caps
   /** Maximum established connections per worker. */
   public private(set) null|int $maxConnections;
   /** Maximum established connections per client IP. */
   public private(set) null|int $maxConnectionsPerIP;
   // # HTTP fallback
   /** Responder for plain (non-upgrade) requests — e.g. the client page. */
   public private(set) null|Closure $Fallback;


   /**
    * @param Argument $Named Guard slot — never pass it; it only rejects positional calls.
    * @param null|array<string,mixed> $secure Secure SSL/TLS Stream Context options.
    * @param array<string> $subprotocols Server-supported subprotocols, in preference order.
    * @param array<object> $Guards Handshake authentication guards.
    */
   public function __construct (
      Argument $Named = Argument::Undefined,
      null|string $host = null,
      null|int $port = null,
      null|int $workers = null,
      null|array $secure = null,
      null|string $user = null,
      null|string $group = null,
      int $heartbeatInterval = 30,
      null|int $idleTimeout = null,
      int $maxFrameSize = 1048576,
      int $maxMessageSize = 8388608,
      array $subprotocols = [],
      bool $compression = true,
      array $Guards = [],
      null|int $maxConnections = null,
      null|int $maxConnectionsPerIP = null,
      null|Closure $Fallback = null
   )
   {
      parent::__construct(
         host: $host,
         port: $port,
         workers: $workers,
         secure: $secure,
         user: $user,
         group: $group
      );

      // * Config
      // # Session policy
      $this->heartbeatInterval = $heartbeatInterval;
      $this->idleTimeout = $idleTimeout;
      $this->maxFrameSize = $maxFrameSize;
      $this->maxMessageSize = $maxMessageSize;
      // # Handshake policy
      $this->subprotocols = $subprotocols;
      $this->compression = $compression;
      $this->Guards = $Guards;
      // # Connection-exhaustion caps
      $this->maxConnections = $maxConnections;
      $this->maxConnectionsPerIP = $maxConnectionsPerIP;
      // # HTTP fallback
      $this->Fallback = $Fallback;
   }
}
