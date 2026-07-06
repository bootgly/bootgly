<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Benchmark\WS_Server_CLI;


use function exec;
use function getenv;
use function max;

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;


return new Project(
   name: 'Benchmark WS Server CLI',
   description: 'WebSocket server benchmark for Bootgly (echo or broadcast fan-out)',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: false,

   boot: function (array $arguments = [], array $options = []): void {
      // ? Mode — `echo` (default) replies each frame to its sender; `broadcast`
      //   joins every connection to one channel and fans each frame out to all
      //   other members. Set by the opponent script from the active load set.
      $mode = getenv('BENCH_WS_MODE') ?: 'echo';

      $Server = new WS_Server_CLI(Mode: Modes::Daemon);

      $Server->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8085,
         workers: getenv('BOOTGLY_WORKERS')
            ? (int) getenv('BOOTGLY_WORKERS')
            : max(1, (int) ((int) (exec('nproc 2>/dev/null') ?: 1) / 2)),
         // # No server-initiated pings during a short run — measure framing, not liveness.
         heartbeatInterval: 0,
         // # permessage-deflate stays offered, but the benchmark client does not
         //   offer it, so no deflate is negotiated (raw-framing throughput).
         compression: getenv('WS_NOCOMPRESS') ? false : true
      );

      // @ Broadcast: every connection joins one room; each inbound frame fans
      //   out to all OTHER members (sender excluded, RFC-clean fan-out).
      if ($mode === 'broadcast') {
         $Server
            ->on(Events::Connected, static function ($Session): void {
               $Session->join('bench');
            })
            ->on(Events::MessageReceived, static function ($Session, $Message): void {
               $Session->broadcast('bench', $Message->payload, $Message->binary);
            });
      }
      // @ Echo (default): reply each frame to its sender, opcode preserved.
      else {
         $Server->on(Events::MessageReceived, static function ($Session, $Message): void {
            $Session->send($Message->payload, $Message->binary);
         });
      }

      $Server->start();
   }
);
