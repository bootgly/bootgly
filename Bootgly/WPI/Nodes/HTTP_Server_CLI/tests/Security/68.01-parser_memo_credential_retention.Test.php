<?php

use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


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
if (! class_exists('L401Connection', false)) {
   class L401Connection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 1;
      }
   }
}

$probe = [
   'error' => '',
   'retained' => [],
   'controlMemoized' => false,
   'scans' => 0,
   // ! Entry-decoder memo layers (L0 per-connection key/template, shared L1).
   'decoderRetained' => [],
   'decoderControl' => false,
   'pathResidual' => false,
];

return new Test(
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
         // ! Custom credential names must be excluded from the header-scan memo
         //   too, not only from Decoder_'s L0/L1 layers.
         $Parse("X-API-Key: L4-SECRET-FRAME-API-KEY\r\n");
         $Parse("X-Access-Code: L4-SECRET-FRAME-ACCESS\r\n");

         // ! Control — an ordinary block must still be memoized. It carries a
         //   STANDARD field, because the boundary is an allowlist: an
         //   application header is not memoized at all, since no name rule can
         //   tell `X-Access-Code` from `X-Ordinary`.
         $Parse("Accept-Encoding: L4-PLAIN-VALUE\r\n");

         /** @var array<string,mixed> $memo */
         $memo = $scans->getValue();
         $probe['scans'] = count($memo);
         $serialized = json_encode(array_keys($memo)) . json_encode($memo);

         foreach ([
            'L4-SECRET-TOKEN',
            'L4-SECRET-COOKIE',
            'L4-SECRET-FRAME-API-KEY',
            'L4-SECRET-FRAME-ACCESS',
         ] as $secret) {
            if (str_contains((string) $serialized, $secret)) {
               $probe['retained'][] = $secret;
            }
         }
         $probe['controlMemoized'] = str_contains((string) $serialized, 'L4-PLAIN-VALUE');

         $scans->setValue(null, []);

         // ---

         // @ The entry decoder keeps two more memo layers, both keyed on the
         //   RAW request bytes: the per-connection L0 key/template and the
         //   shared L1 template map. `Frame::$scans` sees only the header
         //   block, so a secret-bearing BODY or a custom credential header
         //   never reached the guard above.
         $Decoder = new Decoder_;
         $Socket = fopen('php://memory', 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException('L4 probe stream did not open.');
         }

         $Drive = static function (string $raw) use ($Decoder, $Socket): Packages {
            // ! One fresh connection per request: the decoder's L0 layer is
            //   per-connection, and a repeat on the same one would take the
            //   template-adoption path instead of the store path under probe.
            $Connection = new L401Connection($Socket);
            $Package = new class($Connection) extends Packages {};

            $Decoder->decode($Package, $raw, strlen($raw));

            return $Package;
         };

         $body = 'token=L4-SECRET-BODY';
         $Bodied = $Drive(
            "POST /l4-body HTTP/1.1\r\nHost: localhost\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n\r\n{$body}"
         );
         $Custom = $Drive(
            "GET /l4-custom HTTP/1.1\r\nHost: localhost\r\n"
            . "X-API-Key: L4-SECRET-CUSTOM\r\n\r\n"
         );
         // ! A heuristic keyword list is not a credential boundary. This is a
         //   realistic custom credential name that does not contain auth,
         //   cookie, token, secret, passw, credential, or key.
         $Access = $Drive(
            "GET /l4-access HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Access-Code: L4-SECRET-ACCESS\r\n\r\n"
         );
         // ! A QUERY is the one part of a target that routinely carries a
         //   credential (`?code=`, `?token=`). It is now excluded from BOTH memo
         //   layers rather than from the shared L1 alone.
         $Query = $Drive(
            "GET /l4-query?code=L4-SECRET-QUERY HTTP/1.1\r\n"
            . "Host: localhost\r\nAccept: */*\r\n\r\n"
         );
         // ! A credential embedded in the PATH is an ACCEPTED RESIDUAL, not a
         //   closed branch. Nothing distinguishes `/account/<secret>` from
         //   `/l4-public`: identical shape, identical fields, only the target
         //   text differs — so any test on the path's content (length, segment
         //   count, entropy) is the same class of heuristic that was refuted for
         //   field names, and the only alternative is to memoize no target at
         //   all, which removes both decoder layers. Putting a credential in a
         //   URL path is an anti-pattern RFC 9110 §4.2.4 discourages precisely
         //   because it lands in logs, referrers and caches; retention here is
         //   worker-local, bounded, with no remote read path.
         $Path = $Drive(
            "GET /account/L4-SECRET-PATH HTTP/1.1\r\n"
            . "Host: localhost\r\nAccept: */*\r\n\r\n"
         );
         // ! Control — an ordinary public request must STILL be memoized, so a
         //   fix that disables the decoder memo outright cannot pass.
         $Public = $Drive("GET /l4-public HTTP/1.1\r\nHost: localhost\r\nAccept: */*\r\n\r\n");

         /** @var array<string,mixed> $inputs */
         $inputs = (new ReflectionMethod(Decoder_::class, 'decode'))
            ->getStaticVariables()['inputs'] ?? [];
         $keys = implode('', array_keys($inputs));

         foreach ([
            'L4-SECRET-BODY' => $Bodied,
            'L4-SECRET-CUSTOM' => $Custom,
            'L4-SECRET-ACCESS' => $Access,
            'L4-SECRET-QUERY' => $Query,
         ] as $secret => $Package) {
            if (str_contains($Package->known, (string) $secret)) {
               $probe['decoderRetained'][] = "{$secret} in the L0 per-connection key";
            }
            if ($Package->Template !== null) {
               $probe['decoderRetained'][] = "{$secret} request built an L0 template";
            }
            if (str_contains($keys, (string) $secret)) {
               $probe['decoderRetained'][] = "{$secret} in a shared L1 key";
            }
         }

         $probe['decoderControl'] = $Public->known !== ''
            && str_contains($keys, '/l4-public');

         // ! The accepted residual is ASSERTED, not ignored: if a later change
         //   stops retaining path targets, the case fails and the residual gets
         //   re-examined instead of quietly outliving its rationale.
         $probe['pathResidual'] = str_contains($Path->known, 'L4-SECRET-PATH');
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

      // ? Control — the decoder memo must still serve ordinary public traffic.
      if ($probe['decoderControl'] !== true) {
         return 'L4 control failed: an ordinary public request was not memoized by the entry '
            . 'decoder, so its layers are disabled rather than made credential-safe.';
      }

      // ? Accepted residual — see the fixture comment for why no rule separates
      //   a secret-bearing path from an ordinary one.
      if ($probe['pathResidual'] !== true) {
         return 'L4 residual changed: the memo no longer retains a path target. That is an '
            . 'improvement, but it was an ACCEPTED residual — re-read the rationale, then '
            . 'update this case and the audit report instead of leaving a stale assertion.';
      }

      $retention = $probe['decoderRetained'];
      foreach ($probe['retained'] as $secret) {
         $retention[] = "{$secret} in the Frame header-block memo";
      }
      if ($retention !== []) {
         return 'CONFIRMED L4: parser memos retained credential material in worker memory — '
            . implode(', ', $retention)
            . '. These layers key on raw request bytes, so the secret survives request reset '
            . 'until bounded eviction.';
      }

      return true;
   },
);
