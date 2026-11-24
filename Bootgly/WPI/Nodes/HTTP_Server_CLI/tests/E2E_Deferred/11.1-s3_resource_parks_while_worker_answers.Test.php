<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Resources\Storage\tests\fixtures\S3 as Fake;
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
 * S3 from a request handler: a deferral whose write the store holds parks, and the worker
 * answers another connection meanwhile; while a large write holds the worker's in-flight budget
 * but its last part, a second large write is refused by name, a small write still goes and the
 * worker still answers.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $parked = [];
   /** @var array<string,mixed> */
   public array $other = [];
   /** Seconds from the parked write's request to the other answer, and to its own. */
   public float $answeredOther = -1.0;
   public float $answeredParked = -1.0;
   /** @var array<string,mixed> */
   public array $big = [];
   /** @var array<string,mixed> */
   public array $refused = [];
   /** @var array<string,mixed> */
   public array $meanwhile = [];
   public null|string $stored = null;
   public int $bigStored = -1;
   /** @var array<string,mixed> */
   public array $small = [];
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
      $Fake = $GLOBALS['BOOTGLY_E2E_DEFERRED_S3'] ?? null;
      if ($Fake instanceof Fake === false) {
         $Probe->error = 'The S3 fake did not start (no pcntl/posix).';

         return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }

      try {
         // @ A: a write the store holds 600 ms
         $Fake->delay('PutObject', 0.6);
         $Parked = Client::open($hostPort);
         $start = microtime(true);
         @fwrite($Parked, Client::request('/deferred/s3?tag=A', $testIndex));
         usleep(50_000);

         // @ B: another connection, answered while A parks
         $Other = Client::open($hostPort);
         $Probe->other = Client::send($Other, Client::request('/deferred/tag?tag=B', $testIndex), 2.0);
         $Probe->answeredOther = round(microtime(true) - $start, 3);
         Client::close($Other);

         $Probe->parked = $Read($Parked, 4.0);
         $Probe->answeredParked = round(microtime(true) - $start, 3);
         Client::close($Parked);
         $Probe->stored = $Fake->fetch('e2e/A.txt');

         // @ A large write whose parts the store holds: it takes the budget but its last part
         $Fake->delay('UploadPart', 0.8, times: 2);
         $Big = Client::open($hostPort);
         @fwrite($Big, Client::request('/deferred/s3/big?tag=1', $testIndex));
         usleep(200_000);

         // @ Meanwhile: a second large write refused by name, a small write, and a plain answer
         $Refused = Client::open($hostPort);
         $Probe->refused = (array) json_decode((string) (Client::send($Refused, Client::request('/deferred/s3/big?tag=2', $testIndex), 2.0)['body'] ?? ''), true);
         Client::close($Refused);
         $Small = Client::open($hostPort);
         $Probe->small = (array) json_decode((string) (Client::send($Small, Client::request('/deferred/s3?tag=C', $testIndex), 2.0)['body'] ?? ''), true);
         Client::close($Small);
         $Meanwhile = Client::open($hostPort);
         $Probe->meanwhile = Client::send($Meanwhile, Client::request('/deferred/tag?tag=D', $testIndex), 2.0);
         Client::close($Meanwhile);

         $Probe->big = $Read($Big, 5.0);
         Client::close($Big);
         $Probe->bigStored = strlen((string) $Fake->fetch('e2e/big1.bin'));
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/deferred/s3', static function (Request $Request, Response $Response) {
         $tag = (string) ($Request->queries['tag'] ?? 'X');

         return $Response->defer(static function (Response $Response) use ($tag) {
            $saved = $Response->S3->write("e2e/{$tag}.txt", "payload-{$tag}", ['type' => 'text/plain']);
            $error = $Response->S3->error;
            $read = $saved ? $Response->S3->read("e2e/{$tag}.txt") : false;

            $Response->JSON->send(['tag' => $tag, 'saved' => $saved, 'read' => $read, 'error' => $error]);
         });
      }, GET);
      yield $Router->route('/deferred/s3/big', static function (Request $Request, Response $Response) {
         $tag = (string) ($Request->queries['tag'] ?? '0');

         return $Response->defer(static function (Response $Response) use ($tag) {
            $saved = $Response->S3->write("e2e/big{$tag}.bin", str_repeat('b', 10 * 1024 * 1024 - 1));

            $Response->JSON->send(['saved' => $saved, 'error' => $Response->S3->error]);
         });
      }, GET);

      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $other = json_decode((string) ($Probe->other['body'] ?? ''), true);
      $meanwhile = json_decode((string) ($Probe->meanwhile['body'] ?? ''), true);
      $evidence = json_encode([
         'error' => $Probe->error,
         'parked' => $Probe->parked,
         'other' => $Probe->other,
         'answeredOther' => $Probe->answeredOther,
         'answeredParked' => $Probe->answeredParked,
         'stored' => $Probe->stored,
         'big' => $Probe->big,
         'refused' => $Probe->refused,
         'small' => $Probe->small,
         'meanwhile' => $Probe->meanwhile,
         'bigStored' => $Probe->bigStored,
      ]);

      yield new Assertion(
         description: 'A deferred S3 write parks while the worker answers another connection',
         fallback: "The other connection waited for the S3 write: {$evidence}"
      )
         ->expect(
            $Probe->error === ''
            && $other === ['tag' => 'B']
            && $Probe->answeredOther >= 0.0
            && $Probe->answeredParked - $Probe->answeredOther >= 0.2
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The parked write lands in the store and reads back',
         fallback: "The deferred write did not land: {$evidence}"
      )
         ->expect([$Probe->parked, $Probe->stored])
         ->to->be([['tag' => 'A', 'saved' => true, 'read' => 'payload-A', 'error' => ''], 'payload-A'])
         ->assert();

      yield new Assertion(
         description: 'While a large write holds the budget but its last part, a second large write is refused by name, a small write goes and the worker still answers',
         fallback: "The budget was not held or the worker stopped answering: {$evidence}"
      )
         ->expect(
            ($Probe->refused['saved'] ?? null) === false
            && str_contains((string) ($Probe->refused['error'] ?? ''), "the worker's in-flight budget (10485760 bytes) cannot take one more part")
            && ($Probe->small['saved'] ?? null) === true
            && $meanwhile === ['tag' => 'D']
            && $Probe->big === ['saved' => true, 'error' => '']
            && $Probe->bigStored === 10 * 1024 * 1024 - 1
         )
         ->to->be(true)
         ->assert();
   })
);
