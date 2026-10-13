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
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSON;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


/**
 * H-HSC-5 — mounting an event stream pins the live Request, but never a HEAD.
 *
 * An SSE response takes no snapshot: its ticks read the live Request long
 * after the head was written, so `Response::attach()` pins it
 * (`Request::pin()`) and the encoder keeps its head (`shed()` is skipped). A
 * HEAD answer ends at its head and never streams — nothing reads that head
 * again — so a HEAD is not pinned: a pinned HEAD would let every idle
 * keep-alive peer keep the whole head it sent (up to 100 field lines and
 * 16 KB) outside every ledger. Security 103.04 row I7 measures it live
 * (audit only); this spec is its CI pin.
 *
 * Pinned in-process, on a Response bound to a real Request, for both ways a
 * route mounts SSE — the lazy `$Response->SSE` and `$Response->mount()`:
 *
 * - a GET or a POST is pinned when SSE is mounted, and not before;
 * - a HEAD is never pinned;
 * - a resource other than SSE (JSON) pins nothing.
 */
return new Test(
   description: 'H-HSC-5: mounting SSE pins the live Request, never a HEAD',
   test: new Assertions(Case: function (): Generator {
      // ! A Response bound to a fresh Request of `$method`, as `reset()`
      //   binds the live one
      $Bind = static function (string $method): array {
         $Request = new Request;
         $Request->method = $method;
         $Request->protocol = 'HTTP/1.1';
         $Response = new Response;
         new ReflectionProperty(Response::class, 'Request')->setValue($Response, $Request);

         // :
         return [$Request, $Response];
      };
      // ! How a route mounts the stream
      $Mounts = [
         'lazy' => static fn (Response $Response): mixed => $Response->SSE,
         'mount' => static fn (Response $Response): mixed => $Response->mount(new SSE($Response), 'Events'),
      ];

      // @@ Each method, each mount: [pinned before, pinned after, mounted]
      $pins = [];
      foreach (['GET', 'POST', 'HEAD'] as $method) {
         foreach ($Mounts as $style => $Mount) {
            [$Request, $Response] = $Bind($method);
            $before = $Request->pinned;
            $Resource = $Mount($Response);
            $pins["{$method} {$style}"] = [$before, $Request->pinned, $Resource instanceof SSE];
         }
      }
      $evidence = json_encode($pins);

      yield new Assertion(
         description: "A GET or POST is pinned when SSE is mounted — lazily or by mount(): {$evidence}"
      )
         ->expect([
            $pins['GET lazy'], $pins['GET mount'], $pins['POST lazy'], $pins['POST mount'],
         ])
         ->to->be([
            [false, true, true], [false, true, true], [false, true, true], [false, true, true],
         ])
         ->assert();

      yield new Assertion(
         description: "A HEAD is never pinned — its answer ends at its head: {$evidence}"
      )
         ->expect([$pins['HEAD lazy'], $pins['HEAD mount']])
         ->to->be([[false, false, true], [false, false, true]])
         ->assert();

      // @ Another resource pins nothing
      [$Request, $Response] = $Bind('GET');
      $mounted = $Response->JSON instanceof JSON;

      yield new Assertion(
         description: 'A resource other than SSE (JSON) pins nothing'
      )
         ->expect([$mounted, $Request->pinned])
         ->to->be([true, false])
         ->assert();
   }),
);
