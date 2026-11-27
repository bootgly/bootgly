<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Data\DNS;
use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * An outbound call by name from a deferral: the name's lookup parks on the
 * worker's loop (the nameserver holds its answer 1 second) while the worker
 * answers another connection at once. The system resolver sleeps 1 second
 * too, so a dial that resolved outside the loop blocks the worker either way.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $dialed = [];
   /** @var array<string,mixed> */
   public array $other = [];
   public float $answeredOther = -1.0;
   public float $answeredDialed = -1.0;
   public int $queries = -1;
   public string $error = '';
};

// ! Read one Content-Length framed JSON answer from a socket whose request is already written
$Read = static function ($Socket, float $timeout): array {
   $wire = '';
   $deadline = microtime(true) + $timeout;
   // @@
   while (microtime(true) < $deadline) {
      $separator = strpos($wire, "\r\n\r\n");
      $matches = [];
      if (
         $separator !== false
         && preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', substr($wire, 0, $separator), $matches) === 1
         && strlen($wire) - $separator - 4 >= (int) $matches[1]
      ) {
         $decoded = json_decode(substr($wire, $separator + 4, (int) $matches[1]), true);

         return is_array($decoded) ? $decoded : ['raw' => $wire];
      }
      $read = [$Socket];
      $write = null;
      $except = null;
      if (@stream_select($read, $write, $except, 0, 50_000) !== 1) {
         continue;
      }
      $chunk = @fread($Socket, 65536);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $wire .= $chunk;
   }

   // :
   return ['raw' => $wire];
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Read): string {
      $Harness = $GLOBALS['BOOTGLY_E2E_DNS'] ?? null;
      if ($Harness instanceof Harness === false) {
         $Probe->error = 'The fake nameserver did not start (no pcntl).';

         return "GET /dns/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }

      try {
         // ! The nameserver holds the upstream's answers 1 second
         $NS = $Harness->Nameserver;
         $NS->reset();
         $NS->script('upstream.test', DNS::TYPE_A, ['answers' => [['upstream.test', DNS::TYPE_A, 60, '127.0.0.1']], 'delay' => 1.0]);
         $NS->script('upstream.test', DNS::TYPE_AAAA, ['answers' => [], 'delay' => 1.0]);

         // @ A deferral calls this worker back by name
         $start = microtime(true);
         $Dialer = Client::open($hostPort);
         @fwrite($Dialer, Client::request('/dns/dial', $testIndex));
         usleep(50_000);

         // @ Another connection, answered while the lookup parks
         $sent = microtime(true);
         $Other = Client::open($hostPort);
         $Probe->other = Client::send($Other, Client::request('/dns/ping', $testIndex), 2.0);
         $Probe->answeredOther = round(microtime(true) - $sent, 3);
         Client::close($Other);

         $Probe->dialed = $Read($Dialer, 5.0);
         $Probe->answeredDialed = round(microtime(true) - $start, 3);
         Client::close($Dialer);

         $Probe->queries = count(array_filter($NS->log(), static fn (array $Entry): bool => $Entry['name'] === 'upstream.test'));
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /dns/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/dns/dial', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response) {
            // ! One Configs for every request (the shared state follows its seams' identity),
            //   with a system resolver that sleeps 1 second and counts its calls
            $Harness = $GLOBALS['BOOTGLY_E2E_DNS'];
            $GLOBALS['BOOTGLY_E2E_DNS_LADDER'] ??= 0;
            $GLOBALS['BOOTGLY_E2E_DNS_DIAL_CONFIGS'] ??= $Harness->configure([
               'timeout' => 3,
               'hostname' => 'dial.example',
               'Resolver' => new Resolver(
                  Lookup: static function (string $host): array {
                     $GLOBALS['BOOTGLY_E2E_DNS_LADDER']++;
                     usleep(1_000_000);

                     return [['ai_addr' => ['sin_addr' => '127.0.0.1']]];
                  }
               ),
            ]);
            $DNS = new DNS_Client_CLI()->configure($GLOBALS['BOOTGLY_E2E_DNS_DIAL_CONFIGS']);

            // @ Call this worker back by name
            $Upstream = new HTTP(host: 'upstream.test', port: 8106, connectTimeout: 4, timeout: 4);
            $Upstream->Client->Resolution = $DNS->resolve(...);
            $Upstream->schedule(static fn (mixed $value = null): mixed => $Response->wait($value));
            $Answer = $Upstream->request('GET', '/dns/echo');

            $Response->JSON->send([
               'code' => $Answer->code,
               'body' => $Answer->body,
               'ladder' => $GLOBALS['BOOTGLY_E2E_DNS_LADDER'],
            ]);
         });
      }, GET);
      yield $Router->route('/dns/echo', static function (Request $Request, Response $Response) {
         return $Response(body: (string) $Request->Header->get('Host'));
      }, GET);
      yield $Router->route('/dns/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'dialed' => $Probe->dialed,
         'other' => $Probe->other,
         'answeredOther' => $Probe->answeredOther,
         'answeredDialed' => $Probe->answeredDialed,
         'queries' => $Probe->queries,
      ]);

      yield new Assertion(
         description: 'A dial by name parks its lookup while the worker answers another connection at once',
         fallback: "The other connection waited for the dial's lookup: {$evidence}"
      )
         ->expect(
            $Probe->error === ''
            && ($Probe->other['body'] ?? null) === 'pong'
            && $Probe->answeredOther >= 0.0 && $Probe->answeredOther < 0.6
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The dial reaches the upstream through the parked lookup, never the system resolver, and names it in Host',
         fallback: "The dial did not resolve on the loop: {$evidence}"
      )
         ->expect([$Probe->dialed, $Probe->queries, $Probe->answeredDialed >= 0.9])
         ->to->be([['code' => 200, 'body' => 'upstream.test:8106', 'ladder' => 0], 2, true])
         ->assert();
   })
);
