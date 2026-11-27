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
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Two requests resolve one slow name from deferrals: they park on the
 * worker's loop and share one query per type, while a third connection is
 * answered at once.
 */
$Probe = new class {
   /** @var array<int,array<string,mixed>> */
   public array $parked = [];
   /** @var array<string,mixed> */
   public array $other = [];
   /** Seconds from the first lookup's request to the other answer, and to the lookups'. */
   public float $answeredOther = -1.0;
   public float $answeredParked = -1.0;
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
         // ! The nameserver holds the answers 1 second
         $NS = $Harness->Nameserver;
         $NS->reset();
         $NS->script('slow.test', DNS::TYPE_A, ['answers' => [['slow.test', DNS::TYPE_A, 60, '192.0.2.31']], 'delay' => 1.0]);
         $NS->script('slow.test', DNS::TYPE_AAAA, ['answers' => [], 'delay' => 1.0]);

         // @ Two lookups of the slow name
         $start = microtime(true);
         $First = Client::open($hostPort);
         @fwrite($First, Client::request('/dns/slow', $testIndex));
         usleep(20_000);
         $Second = Client::open($hostPort);
         @fwrite($Second, Client::request('/dns/slow', $testIndex));
         usleep(50_000);

         // @ Another connection, answered while they park
         $Other = Client::open($hostPort);
         $Probe->other = Client::send($Other, Client::request('/dns/ping', $testIndex), 2.0);
         $Probe->answeredOther = round(microtime(true) - $start, 3);
         Client::close($Other);

         $Probe->parked[] = $Read($First, 4.0);
         $Probe->parked[] = $Read($Second, 4.0);
         $Probe->answeredParked = round(microtime(true) - $start, 3);
         Client::close($First);
         Client::close($Second);

         $Probe->queries = count(array_filter($NS->log(), static fn (array $Entry): bool => $Entry['name'] === 'slow.test'));
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /dns/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/dns/slow', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response) {
            // ! One Configs for every request: the shared state follows its seams' identity
            $Harness = $GLOBALS['BOOTGLY_E2E_DNS'];
            $GLOBALS['BOOTGLY_E2E_DNS_CONFIGS'] ??= $Harness->configure(['timeout' => 3]);
            $DNS = new DNS_Client_CLI()->configure($GLOBALS['BOOTGLY_E2E_DNS_CONFIGS']);
            $addresses = $DNS->resolve('slow.test', static fn (mixed $value = null): mixed => $Response->wait($value));

            $Response->JSON->send(['addresses' => $addresses]);
         });
      }, GET);
      yield $Router->route('/dns/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'parked' => $Probe->parked,
         'other' => $Probe->other,
         'answeredOther' => $Probe->answeredOther,
         'answeredParked' => $Probe->answeredParked,
         'queries' => $Probe->queries,
      ]);

      yield new Assertion(
         description: 'A lookup parks on the worker loop while the worker answers another connection',
         fallback: "The other connection waited for the lookup: {$evidence}"
      )
         ->expect(
            $Probe->error === ''
            && ($Probe->other['body'] ?? null) === 'pong'
            && $Probe->answeredOther >= 0.0 && $Probe->answeredOther < 0.6
            && $Probe->answeredParked >= 0.9
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Both lookups get the answer from one A and one AAAA query',
         fallback: "The lookups did not share one query: {$evidence}"
      )
         ->expect([$Probe->parked, $Probe->queries])
         ->to->be([[['addresses' => ['192.0.2.31']], ['addresses' => ['192.0.2.31']]], 2])
         ->assert();
   })
);
