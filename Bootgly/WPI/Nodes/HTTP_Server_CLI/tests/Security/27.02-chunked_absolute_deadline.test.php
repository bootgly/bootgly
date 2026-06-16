<?php

use function time;

use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Audit F-6 (timeout): the chunked decode deadline must be ABSOLUTE,
 * anchored to the decode start (`$decoded`, set once in `init()`), NOT a
 * per-packet sliding window. The old code reset `$decoded = time()` on every
 * packet, so an attacker dribbling one byte every 29 s kept the request — and
 * the worker buffer/connection — alive forever.
 *
 * Driven in-process (no live server needed for the assertion): `expire()`
 * reads only `$decoded` + `time()`. Before the fix, `Decoder_Chunked::expire()`
 * does not exist, so this spec errors out.
 */
return new Specification(
   description: 'Decoder_Chunked deadline is absolute (anchored to init, not refreshed per packet)',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /f6-deadline HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'OK');
      });
   },

   test: function ($response): bool|string {
      $Decoder = new Decoder_Chunked;
      $Decoder->init();

      // @ A freshly initialized decoder is within its deadline.
      if ($Decoder->expire() !== false) {
         return 'A freshly initialized chunked decoder must not be expired.';
      }

      $decoded = new ReflectionProperty(Decoder_Chunked::class, 'decoded');

      // @ 31 s past the ABSOLUTE start anchor → expired. A per-packet sliding
      //   window would have reset the anchor and never reached this.
      $decoded->setValue($Decoder, time() - 31);
      if ($Decoder->expire() !== true) {
         return 'Decoder must expire 31s after its start anchor (absolute deadline).';
      }

      // @ 29 s past → still within the 30 s deadline.
      $decoded->setValue($Decoder, time() - 29);
      if ($Decoder->expire() !== false) {
         return 'Decoder must NOT expire before the 30s absolute deadline.';
      }

      return true;
   }
);
