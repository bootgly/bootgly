<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression H8 — worker-wide body reservations and cleanup.
 *
 * Two connection accountants share a 96-byte worker ceiling. The test covers
 * the exact boundary, rejection above it, release, duplicate cleanup, and the
 * destructor fallback that prevents a dropped decoder from poisoning future
 * reservations in the same worker.
 */

$probe = [
   'error' => '',
   'connection_a_64' => null,
   'connection_overrun_1' => null,
   'connection_b_32' => null,
   'worker_overrun_1' => null,
   'connection_b_after_release_32' => null,
   'connection_a_retained' => -1,
   'connection_b_retained' => -1,
   'worker_reusable_after_release' => null,
   'worker_reusable_after_destructor' => null,
   'zero_reservation' => null,
   'negative_reservation' => null,
];

return new Specification(
   description: 'HTTP/2 body accounting must enforce and release the per-worker budget',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      try {
         $BodiesA = new Bodies(64, 96);
         $BodiesB = new Bodies(64, 96);

         $probe['connection_a_64'] = $BodiesA->reserve(64);
         $probe['connection_overrun_1'] = $BodiesA->reserve(1);
         $probe['connection_b_32'] = $BodiesB->reserve(32);
         $probe['worker_overrun_1'] = $BodiesB->reserve(1);

         $BodiesA->release(64);
         $BodiesA->release(64);
         $probe['connection_b_after_release_32'] = $BodiesB->reserve(32);
         $probe['connection_a_retained'] = $BodiesA->retained;
         $probe['connection_b_retained'] = $BodiesB->retained;

         $BodiesB->release(64);
         unset($BodiesA, $BodiesB);
         gc_collect_cycles();

         $BodiesC = new Bodies(96, 96);
         $probe['worker_reusable_after_release'] = $BodiesC->reserve(96);
         unset($BodiesC);
         gc_collect_cycles();

         $BodiesD = new Bodies(96, 96);
         $probe['worker_reusable_after_destructor'] = $BodiesD->reserve(96);
         $probe['zero_reservation'] = $BodiesD->reserve(0);
         $probe['negative_reservation'] = $BodiesD->reserve(-1);
         $BodiesD->release(-1);
         $BodiesD->release(96);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /h8-worker-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h8-worker-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H8-WORKER-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'H8-WORKER-HARNESS-OK')) {
         return 'H8 worker-budget harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      $expected = [
         'connection_a_64' => true,
         'connection_overrun_1' => false,
         'connection_b_32' => true,
         'worker_overrun_1' => false,
         'connection_b_after_release_32' => true,
         'connection_a_retained' => 0,
         'connection_b_retained' => 64,
         'worker_reusable_after_release' => true,
         'worker_reusable_after_destructor' => true,
         'zero_reservation' => true,
         'negative_reservation' => true,
      ];
      $actual = $probe;
      unset($actual['error']);

      if ($actual !== $expected) {
         Vars::$labels = ['H8 worker-budget lifecycle evidence'];
         dump(json_encode($probe));
         return 'The HTTP/2 worker body budget was not enforced and released exactly; evidence='
            . json_encode($probe);
      }

      return true;
   },
);
