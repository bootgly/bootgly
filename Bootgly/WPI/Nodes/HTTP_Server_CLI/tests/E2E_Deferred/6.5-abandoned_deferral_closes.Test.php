<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * H-HSC-6: a parked deferral whose generation ends without a response leaves
 * a hole in the connection's response order — nothing may be answered after
 * it. The connection closes, and the request pipelined behind it, held while
 * it parked, is never answered.
 *
 * The deferral parks 0.3 s before it abandons its generation: a connection
 * closed less than 0.25 s after the write was closed without parking, which
 * proves nothing about an abandoned deferral — the case fails as
 * inconclusive.
 */
$Probe = new class {
   public string $wire = '';
   public bool $eof = false;
   /** Seconds from the write to the server's close; -1 while it stays open. */
   public float $closed = -1.0;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         // @ A parking deferral that is abandoned, and a request pipelined
         //   behind it in the same write
         $parked = Client::request('/deferred/abandon', $testIndex);
         $behind = Client::request('/deferred/tag?tag=E', $testIndex);
         $start = microtime(true);
         @fwrite($Socket, "{$parked}{$behind}");

         // @@ Read until the server closes the connection
         $deadline = $start + 3.0;
         while (microtime(true) < $deadline) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
               continue;
            }
            $chunk = @fread($Socket, 65536);
            if ($chunk === false || $chunk === '') {
               $Probe->eof = true;
               $Probe->closed = round(microtime(true) - $start, 3);
               break;
            }
            $Probe->wire .= $chunk;
         }
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'eof' => $Probe->eof,
         'closed' => $Probe->closed,
         'wire' => $Probe->wire,
      ]);

      // @ Timing precondition: inconclusive unless it held
      yield new Assertion(
         description: 'Precondition: the deferral parked (the connection stayed open at least 0.25 s after the write)',
         fallback: "Inconclusive — the connection closed before the deferral could park: {$evidence}"
      )
         ->expect($Probe->eof === false || $Probe->closed >= 0.25)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Nothing is answered: neither the abandoned deferral nor the request held behind it',
         fallback: "A response followed the abandoned deferral: {$evidence}"
      )
         ->expect($Probe->wire)
         ->to->be('')
         ->assert();

      yield new Assertion(
         description: 'The connection closes once the deferral is abandoned',
         fallback: "The connection stayed open: {$evidence}"
      )
         ->expect($Probe->eof)
         ->to->be(true)
         ->assert();
   })
);
