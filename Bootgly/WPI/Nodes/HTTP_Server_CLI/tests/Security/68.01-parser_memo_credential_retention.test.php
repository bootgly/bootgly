<?php

use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC L4 (2026-07-27) — the header-block memo must not retain
 * credentials in worker memory.
 *
 * `Frame::$scans` memoizes a successful field scan keyed on the RAW header
 * block, so a block carrying `Authorization` or `Cookie` parked that credential
 * in plaintext for the life of the worker — as the array KEY itself, which means
 * scrubbing the stored value array could never have helped. Retention is bounded
 * (512 entries, FIFO) but long-lived, and survives every request reset.
 *
 * Those two names also make a block near-unique per user, so they were never the
 * traffic the memo exists for: it pays off on the byte-identical blocks that
 * keep-alive repeats.
 *
 * The real `Frame::parse()` is driven directly and the real static memo is read
 * back by reflection. The memo is bypassed in the Test environment (audit N3),
 * which is what this process runs as, so the probe swaps `Server::$Environment`
 * around the scans and restores it in a finally.
 *
 * Control: an ordinary credential-free block MUST still be memoized, so a fix
 * that simply disables the memo cannot pass.
 */
$probe = ['error' => '', 'retained' => [], 'controlMemoized' => false, 'scans' => 0];

return new Specification(
   description: 'the header-block memo must not retain Authorization or Cookie values',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $Environment = SAPI::$Environment ?? null;

      try {
         $Package = new class extends Packages {
            // ! parse() only ever calls reject() on this double; no transport
            //   state is touched, so the parent constructor is bypassed.
            public function __construct () {}

            public function reject (string $raw): void
            {
               $this->rejected = true;
            }
         };

         $Reflection = new ReflectionClass(Frame::class);
         $scans = $Reflection->getProperty('scans');
         $scans->setAccessible(true);
         $scans->setValue(null, []);

         // ! The memo is disabled under Environments::Test — the very state this
         //   process runs in. Swap it so the production path is exercised.
         SAPI::$Environment = Environments::Development;

         $Parse = static function (string $block) use ($Package): void {
            $buffer = "GET /l4 HTTP/1.1\r\nHost: localhost\r\n{$block}\r\n";
            Frame::parse($Package, $buffer, strlen($buffer));
         };

         // @ Credential-bearing blocks.
         $Parse("Authorization: Bearer L4-SECRET-TOKEN\r\n");
         $Parse("Cookie: session=L4-SECRET-COOKIE\r\n");

         // ! Control — an ordinary block must still be memoized.
         $Parse("X-Ordinary: L4-PLAIN-VALUE\r\n");

         /** @var array<string,mixed> $memo */
         $memo = $scans->getValue();
         $probe['scans'] = count($memo);
         $serialized = json_encode(array_keys($memo)) . json_encode($memo);

         foreach (['L4-SECRET-TOKEN', 'L4-SECRET-COOKIE'] as $secret) {
            if (str_contains((string) $serialized, $secret)) {
               $probe['retained'][] = $secret;
            }
         }
         $probe['controlMemoized'] = str_contains((string) $serialized, 'L4-PLAIN-VALUE');

         $scans->setValue(null, []);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         // ! Restore before anything else in this process observes it.
         if ($Environment !== null) {
            SAPI::$Environment = $Environment;
         }
      }

      return "GET /l4-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/l4-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'L4 harness request did not reach /l4-harness.';
      }
      if ($probe['error'] !== '') {
         return 'L4 fixture error: ' . $probe['error'];
      }

      // ? Control — the memo must still work for ordinary traffic, so a fix
      //   that disables it outright cannot pass this case.
      if ($probe['controlMemoized'] !== true) {
         return 'L4 control failed: an ordinary credential-free block was not memoized ('
            . $probe['scans'] . ' entries), so the memo is disabled rather than made '
            . 'credential-safe.';
      }

      if ($probe['retained'] !== []) {
         return 'CONFIRMED L4: the header-block memo retained credential material in worker '
            . 'memory — ' . implode(', ', $probe['retained'])
            . '. The memo is keyed on the RAW block, so the credential is the array key itself '
            . 'and survives every request reset until FIFO eviction.';
      }

      return true;
   },
);
