<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;

/**
 * Coverage seam — the production header-block memo (audit N3).
 *
 * `Frame::parse()` memoizes a successful field scan keyed on the exact header
 * block, but the memo is explicitly bypassed while
 * `Server::$Environment === Environments::Test`. Every live suite (HTTP E2E and
 * Security) runs in that environment, so the production cache-HIT path — the one
 * that replays Content-Length, Transfer-Encoding, Expect, Host and Connection
 * decisions — had no coverage at all.
 *
 * This case drives `Frame::parse()` directly with the environment temporarily
 * switched to Production, so the warm/hit path is exercised for real, then
 * restores it. It asserts the three properties that make the memo safe:
 *
 *   1. a hit reproduces the framing decisions of the cold scan byte-for-byte;
 *   2. a block rejected DURING the scan is never inserted, so the same bytes
 *      are rejected again rather than replayed from cache;
 *   3. a block rejected by a POST-scan check stays rejected on a warmed hit,
 *      since those checks replay per request and are not part of the memo.
 */

return new Test(
   description: 'the production header-block memo must replay framing decisions without weakening them',
   test: new Assertions(Case: function (): Generator {
      $Package = new class extends TCPPackages {
         public string $rejection = '';

         // ! Frame::parse() only ever calls reject() on this object, so the
         //   transport-bound parent constructor is deliberately skipped.
         public function __construct () {}

         public function reject (string $raw): void
         {
            $this->rejected = true;
            $this->rejection = $raw;
         }
      };

      // @ Drive the real static memo, with a clean slate either side.
      $Reflection = new ReflectionClass(Frame::class);
      $scans = $Reflection->getProperty('scans');
      $scans->setAccessible(true);
      $scans->setValue(null, []);

      $Parse = static function (string $head) use ($Package): array {
         $Package->rejected = false;
         $Package->rejection = '';
         $buffer = $head;
         $Frame = Frame::parse($Package, $buffer, strlen($head));

         return [$Frame, $Package->rejected, $Package->rejection];
      };

      $Environment = Server::$Environment;
      Server::$Environment = Environments::Production;

      try {
         // # 1 — cold scan then warm hit over identical framing-bearing bytes
         $head = "POST /memo HTTP/1.1\r\n"
            . "Host: memo.example.test\r\n"
            . "Content-Length: 5\r\n"
            . "Expect: 100-continue\r\n"
            . "Connection: close\r\n"
            . "\r\nHELLO";

         [$cold] = $Parse($head);
         [$warm] = $Parse($head);

         yield new Assertion(
            description: 'a memo hit reproduces the cold scan framing decisions exactly',
         )
            ->expect([
               $cold?->contentLength, $cold?->expectContinue, $cold?->hostValue,
               $cold?->closeConnection, $cold?->chunked,
            ])
            ->to->be([
               $warm?->contentLength, $warm?->expectContinue, $warm?->hostValue,
               $warm?->closeConnection, $warm?->chunked,
            ])
            ->assert();

         yield new Assertion(
            description: 'the warmed hit still reports the real framing values',
         )
            ->expect([$warm?->contentLength, $warm?->expectContinue, $warm?->closeConnection])
            ->to->be([5, true, true])
            ->assert();

         yield new Assertion(
            description: 'exactly one entry was memoized for one distinct header block',
         )
            ->expect(count($scans->getValue()))
            ->to->be(1)
            ->assert();

         // # 2 — a block rejected DURING the field scan must not be inserted
         $before = count($scans->getValue());
         $malformed = "GET /memo HTTP/1.1\r\n"
            . "Host: memo.example.test\r\n"
            . "Bad(Name): x\r\n"
            . "\r\n";

         [$firstFrame, $firstRejected] = $Parse($malformed);
         [$secondFrame, $secondRejected] = $Parse($malformed);

         yield new Assertion(
            description: 'a scan-rejected block is rejected again instead of replayed from the memo',
         )
            ->expect([$firstFrame, $firstRejected, $secondFrame, $secondRejected])
            ->to->be([null, true, null, true])
            ->assert();

         yield new Assertion(
            description: 'a scan-rejected block was never inserted into the memo',
         )
            ->expect(count($scans->getValue()))
            ->to->be($before)
            ->assert();

         // # 3 — a POST-scan rejection must survive a warmed hit. The block
         //   itself scans cleanly, so it IS memoized; the TE+CL conflict is
         //   caught after the scan and must replay on every request.
         $conflict = "POST /memo HTTP/1.1\r\n"
            . "Host: memo.example.test\r\n"
            . "Content-Length: 5\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n";

         [$coldConflict, $coldRejected] = $Parse($conflict);
         [$warmConflict, $warmRejected] = $Parse($conflict);

         yield new Assertion(
            description: 'a post-scan rejection is not weakened by a warmed memo hit',
         )
            ->expect([$coldConflict, $coldRejected, $warmConflict, $warmRejected])
            ->to->be([null, true, null, true])
            ->assert();

         // # 4 — the memo key excludes the request line. Warm a chunked block
         //   under HTTP/1.1, then replay those exact header bytes under
         //   HTTP/1.0. The protocol-dependent guard must run after the hit.
         $memoBefore = count($scans->getValue());
         $HTTP11Head = "POST /memo HTTP/1.1\r\n"
            . "Host: memo.example.test\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n0\r\n\r\n";
         $HTTP10Head = "POST /memo HTTP/1.0\r\n"
            . "Host: memo.example.test\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n0\r\n\r\n";

         [$HTTP11Frame, $HTTP11Rejected] = $Parse($HTTP11Head);
         [$HTTP10Frame, $HTTP10Rejected, $HTTP10Rejection] = $Parse($HTTP10Head);

         yield new Assertion(
            description: 'HTTP/1.0 chunked framing is rejected after an HTTP/1.1 memo warm',
         )
            ->expect([
               $HTTP11Frame?->chunked,
               $HTTP11Rejected,
               $HTTP10Frame,
               $HTTP10Rejected,
               $HTTP10Rejection,
            ])
            ->to->be([
               true,
               false,
               null,
               true,
               "HTTP/1.1 400 Bad Request\r\n\r\n",
            ])
            ->assert();

         yield new Assertion(
            description: 'the protocol replay reused one memoized header block',
         )
            ->expect(count($scans->getValue()))
            ->to->be($memoBefore + 1)
            ->assert();

         // # 5 — the cold path applies the HTTP/1.0 policy before the
         //   coding-specific HTTP/1.1 501 response.
         $HTTP10UnknownHead = "POST /memo HTTP/1.0\r\n"
            . "Host: memo.example.test\r\n"
            . "Transfer-Encoding: gzip\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n";
         [
            $HTTP10UnknownFrame,
            $HTTP10UnknownRejected,
            $HTTP10UnknownRejection,
         ] = $Parse($HTTP10UnknownHead);

         yield new Assertion(
            description: 'cold HTTP/1.0 rejects every transfer coding with 400',
         )
            ->expect([
               $HTTP10UnknownFrame,
               $HTTP10UnknownRejected,
               $HTTP10UnknownRejection,
               count($scans->getValue()),
            ])
            ->to->be([
               null,
               true,
               "HTTP/1.1 400 Bad Request\r\n\r\n",
               $memoBefore + 1,
            ])
            ->assert();
      }
      finally {
         Server::$Environment = $Environment;
         $scans->setValue(null, []);
      }
   })
);
