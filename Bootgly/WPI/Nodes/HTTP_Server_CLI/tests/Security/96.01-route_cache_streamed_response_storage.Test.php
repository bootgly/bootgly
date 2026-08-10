<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('M4CacheStreamConnection', false)) {
   class M4CacheStreamConnection extends Connection
   {
      public bool $closed = false;

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
         $this->writes = 0;
      }

      public function close (): true
      {
         $this->closed = true;

         return true;
      }
   }
}

/**
 * PoC — the route response cache must never store a streamed file response.
 *
 * `stash()` refuses one: its guard list ends `|| $this->stream || ...`. That
 * guard cannot fire. `Raw::encode()` clears `$this->stream` as the last thing
 * it does before returning the head, and every `stash()` call site runs after
 * `encode()` — `Encoder_::encode()`, the deferred Fiber loop and
 * `Encoder_Testing`. By then the flag is always false.
 *
 * What actually keeps a file response out of the cache today is unrelated:
 * `upload()` installs `Cache-Control: no-cache, must-revalidate`, and `stash()`
 * refuses to store anything carrying `no-cache`. The protection is accidental,
 * and exactly one header away from gone.
 *
 * The stored bytes would be the response HEAD ONLY. A file's body never passes
 * through `stash()` — `Raw::encode()` moved it to the transport's upload queue
 * and it reaches the socket through `Packages::uploading()`. So every warm hit
 * answers with a complete head advertising the file's Content-Length followed
 * by zero body bytes, and a keep-alive client reads the NEXT response on the
 * wire as this one's body.
 *
 * The assertions are about what enters the cache, so this drives `upload()`,
 * the production encoder and `stash()` inline; no transport is involved in
 * deciding what gets stored.
 *
 * Mutation matrix, measured against the patched sources:
 *
 *   remove the refusal from `Raw::encode()` ........ the two file cases
 *   hoist it to the top of `encode()` .............. the buffered control
 *
 * The second is the over-fix that matters, and it is why the buffered control
 * exists: a refusal placed before the wire fast lane makes every plain response
 * uncacheable, which no assertion about file responses would notice.
 *
 * One mutation deliberately NOT in the matrix, because it kills nothing and the
 * reason is worth writing down: widening the `if ($this->stream)` block itself
 * to `if (true)` changes no outcome. A buffered response never reaches that
 * block — it returns earlier, through the wire fast lane — so the block is
 * already reachable only by the responses it is meant to refuse.
 */
$probe = [
   'error' => '',
   'cases' => [],
];

return new Test(
   description: 'The route cache must not store a streamed file response',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $WPI = WPI;
      $Old = $WPI->Request;
      $Socket = fopen('php://temp', 'w+');

      try {
         // ! One case: build a response the way a `cache:`-declared route
         //   would, stamp the TTL the Router dispatcher wrapper stamps, encode
         //   it, stash it, and report whether anything landed in the cache.
         $Run = static function (
            string $URI,
            bool $file,
            null|string $cacheControl
         ) use (&$Socket): array {
            $WPI = WPI;

            $Connection = new M4CacheStreamConnection($Socket);
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;
                  $this->consumed = 0;
                  $this->rejected = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };

            $Request = new Request;
            $Request->method = 'GET';
            $Request->protocol = 'HTTP/1.1';
            new ReflectionProperty($Request, 'URI')->setValue($Request, $URI);
            $WPI->Request = $Request;

            $Response = new Response;
            $Response->reset($Package, $Request);
            if ($file) {
               $Response->upload('statics/alphanumeric.txt', close: false);
            }
            else {
               $Response(code: 200, body: 'BUFFERED-BODY');
            }
            if ($cacheControl !== null) {
               $Response->Header->set('Cache-Control', $cacheControl);
            }
            // ! The per-request TTL stamp a `cache: ['TTL' => 60]` route gets.
            $Response->cache = 60;

            $length = null;
            $buffer = $Response->encode($Package, $length);
            $streamAfter = $Response->stream;

            $key = Cache::compose($Request);
            $Response->stash($buffer);
            $stored = Cache::fetch($key);

            return [
               'stream_after_encode' => $streamAfter,
               'queued' => count($Package->uploading),
               'buffer' => $buffer,
               'stored' => $stored !== null,
               'entry' => $stored,
            ];
         };

         $token = bin2hex(random_bytes(6));
         $probe['cases'] = [
            // # The shape that is refused today, and only by accident.
            'file_default_cc' => $Run("/m4-cache-{$token}-a", true, null),
            // # The same route with the caching policy an author would write.
            'file_public_cc' => $Run("/m4-cache-{$token}-b", true, 'public, max-age=60'),
            // # CONTROL — a buffered response with the same policy must still
            //   be cached, body included. Without this a fix that simply
            //   breaks stash() passes both assertions above.
            'buffered_public_cc' => $Run("/m4-cache-{$token}-c", false, 'public, max-age=60'),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $WPI->Request = $Old;
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }

      return "GET /m4-cache-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/m4-cache-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'HARNESS-OK');
         },
         GET
      );

      yield $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         return 'Route-cache stream PoC harness failed: ' . $probe['error'];
      }
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Route-cache stream PoC harness request did not reach its control route.';
      }

      $C = $probe['cases'];
      $Evidence = static function () use ($C): void {
         Vars::$labels = ['route-cache stream PoC'];
         dump(json_encode([
            'file_default_cc' => ['stored' => $C['file_default_cc']['stored']],
            'file_public_cc' => [
               'stored' => $C['file_public_cc']['stored'],
               'entry_bytes' => strlen($C['file_public_cc']['entry'] ?? ''),
               'buffer_bytes' => strlen($C['file_public_cc']['buffer']),
               'queued' => $C['file_public_cc']['queued'],
               'stream_after_encode' => $C['file_public_cc']['stream_after_encode'],
            ],
            'buffered_public_cc' => ['stored' => $C['buffered_public_cc']['stored']],
         ], JSON_UNESCAPED_SLASHES));
      };

      // @ Control — a buffered response with a cacheable policy must still be
      //   stored, body included. A fix that broke stash() outright, or one that
      //   marked every response ineligible, would pass every assertion below.
      $control = $C['buffered_public_cc'];
      if (
         $control['stored'] !== true
         || ! is_string($control['entry'])
         || ! str_contains($control['entry'], 'BUFFERED-BODY')
      ) {
         $Evidence();

         return 'Control failed: a buffered 200 with `Cache-Control: public, max-age=60` was '
            . 'not stored with its body. The route cache is broken rather than merely '
            . 'refusing streamed responses.';
      }

      // @ A file response must never enter the cache — with either policy.
      $findings = [];
      foreach ([
         'file_default_cc' => 'with the Cache-Control upload() installs',
         'file_public_cc' => 'with a handler-supplied `Cache-Control: public, max-age=60`',
      ] as $key => $label) {
         $case = $C[$key];
         if ($case['stored'] !== true) {
            continue;
         }

         $entry = $case['entry'] ?? '';
         $head = strpos($entry, "\r\n\r\n");
         $body = $head === false ? '' : substr($entry, $head + 4);
         preg_match('/\r\nContent-Length:\s*([0-9]+)/i', $entry, $matches);

         $findings[] = "a streamed file response {$label} was stored as "
            . strlen($entry) . ' bytes advertising Content-Length '
            . ($matches[1] ?? '?') . ' with ' . strlen($body) . ' body bytes behind it';
      }

      if ($findings !== []) {
         $Evidence();

         return 'CONFIRMED: the route cache stores a body-less head for a file response, so '
            . 'every warm hit desynchronizes the connection — ' . implode('; ', $findings)
            . '. `stash()` has a `$this->stream` guard for exactly this, but `Raw::encode()` '
            . 'clears the flag before returning, and stash() runs after encode(): '
            . 'stream_after_encode='
            . json_encode($C['file_public_cc']['stream_after_encode']) . '.';
      }

      return true;
   }
);
