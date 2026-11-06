<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI;


/**
 * The recycling options of the transport Configs — `maxRequests`, `jitter`
 * and `maxMemory` — default to off (0, 0.1, 0), take a size string for
 * `maxMemory` (`Bytes::parse()`), refuse every value no worker could honor,
 * reach the HTTP and WebSocket Configs unchanged, and are adopted whole by the
 * server: a later configure() never inherits an earlier one's limits. TCP and
 * WebSocket count accepted connections toward `maxRequests`; HTTP, requests.
 */
return new Test(
   description: 'Recycling: maxRequests, jitter and maxMemory are validated, inherited and adopted',
   test: new Assertions(Case: function (): Generator {
      /** The exception class a Configs construction throws, or '' when none. */
      $Refuse = static function (Closure $Construct): string {
         try {
            $Construct();
         }
         catch (Throwable $Throwable) {
            return $Throwable::class;
         }

         return '';
      };

      // @@ A) Defaults: recycling off
      $Default = new Configs(host: '127.0.0.1', port: 0, workers: 1);
      yield assert(
         assertion: $Default->maxRequests === 0
            && $Default->jitter === 0.1
            && $Default->maxMemory === 0,
         description: 'defaults: maxRequests 0, jitter 0.1, maxMemory 0'
      );

      // @@ B) Values, a size string and the jitter bounds
      $Set = new Configs(host: '127.0.0.1', port: 0, workers: 1, maxRequests: 1000, jitter: 0.25, maxMemory: '512M');
      $Bytes = new Configs(host: '127.0.0.1', port: 0, workers: 1, maxMemory: 1_048_576);
      $Low = new Configs(host: '127.0.0.1', port: 0, workers: 1, jitter: 0);
      $High = new Configs(host: '127.0.0.1', port: 0, workers: 1, jitter: 1);
      yield assert(
         assertion: $Set->maxRequests === 1000
            && $Set->jitter === 0.25
            && $Set->maxMemory === 536_870_912
            && $Bytes->maxMemory === 1_048_576
            && $Low->jitter === 0.0
            && $High->jitter === 1.0,
         description: 'maxRequests and jitter are kept, "512M" reads 536870912 bytes, jitter 0 and 1 are accepted'
      );

      // @@ C) Refused: a limit no worker could honor
      $Refusals = [
         'maxRequests -1' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, maxRequests: -1)),
         'jitter -0.1' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, jitter: -0.1)),
         'jitter 1.5' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, jitter: 1.5)),
         'jitter NAN' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, jitter: NAN)),
         'maxMemory -1' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, maxMemory: -1)),
         'maxMemory "lots"' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, maxMemory: 'lots')),
         'maxMemory "-5M"' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, maxMemory: '-5M')),
         'maxMemory "5 parsecs"' => $Refuse(static fn () => new Configs(host: '127.0.0.1', port: 0, workers: 1, maxMemory: '5 parsecs')),
      ];
      yield assert(
         assertion: $Refusals === array_fill_keys(array_keys($Refusals), InvalidArgumentException::class),
         description: 'negative, out-of-range and malformed values throw InvalidArgumentException — '
            . json_encode($Refusals)
      );

      // @@ D) The node Configs take the same options
      $HTTP = new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, maxRequests: 7, jitter: 0.2, maxMemory: '1K');
      $WS = new WS_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, maxRequests: 9, jitter: 0.3, maxMemory: 2048);
      yield assert(
         assertion: $HTTP->maxRequests === 7 && $HTTP->jitter === 0.2 && $HTTP->maxMemory === 1024
            && $WS->maxRequests === 9 && $WS->jitter === 0.3 && $WS->maxMemory === 2048
            && $Refuse(static fn () => new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, maxRequests: -3)) === InvalidArgumentException::class
            && $Refuse(static fn () => new WS_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, jitter: 2.0)) === InvalidArgumentException::class,
         description: 'HTTP_Server_CLI\Configs and WS_Server_CLI\Configs carry and validate the recycling options'
      );

      // @@ E) What one dispatch is, per node
      yield assert(
         assertion: TCP_Server_CLI::DISPATCH === 'connection'
            && WS_Server_CLI::DISPATCH === 'connection'
            && HTTP_Server_CLI::DISPATCH === 'request',
         description: 'TCP and WebSocket count accepted connections, HTTP counts requests'
      );

      // @@ F) Adopted whole by the server
      // ! configure() writes worker statics: snapshot what this case changes
      $maxRequests = TCP_Server_CLI::$maxRequests;
      $jitter = TCP_Server_CLI::$jitter;
      $maxMemory = TCP_Server_CLI::$maxMemory;
      $OldProtocols = TCP_Server_CLI::$Protocols;
      try {
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);
         $Server->configure($HTTP);
         $adopted = [TCP_Server_CLI::$maxRequests, TCP_Server_CLI::$jitter, TCP_Server_CLI::$maxMemory];

         $Other = new HTTP_Server_CLI(Mode: Modes::Test);
         $Other->configure(new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1));
         $reset = [TCP_Server_CLI::$maxRequests, TCP_Server_CLI::$jitter, TCP_Server_CLI::$maxMemory];
      }
      finally {
         TCP_Server_CLI::$maxRequests = $maxRequests;
         TCP_Server_CLI::$jitter = $jitter;
         TCP_Server_CLI::$maxMemory = $maxMemory;
         TCP_Server_CLI::$Protocols = $OldProtocols;
      }
      yield assert(
         assertion: $adopted === [7, 0.2, 1024] && $reset === [0, 0.1, 0],
         description: 'configure() adopts the options, and a later configure() without them turns recycling off — '
            . json_encode([$adopted, $reset])
      );
   }),
);
