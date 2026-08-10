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


if (! class_exists('M4FramingConnection', false)) {
   class M4FramingConnection extends Connection
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
         $this->writes = 0;
      }

      public function close (): true
      {
         return true;
      }
   }
}

/**
 * PoC — a route cache entry must be a complete message on its own terms.
 *
 * M4 was one instance of a class: a response whose stored bytes do not carry
 * the body its head advertises. That instance was a streamed file, and it was
 * closed at the producer. Every other guard in `stash()` is the same shape —
 * `chunked`, `encoded`, HEAD, streamed — and each one was a bug before it was a
 * guard, because each enumerates a *reason* the body might be missing.
 *
 * This locks the invariant instead: whatever produced it, an entry is refused
 * unless its buffer carries exactly one Content-Length and exactly that many
 * bytes behind the head/body separator. An entry that fails it desynchronizes
 * every keep-alive connection that replays it — the client consumes the next
 * response on the wire as this one's body.
 *
 * `stash()` is public and takes the buffer as an argument, so the legs hand it
 * crafted wire directly. That is the point: the check must hold for bytes it
 * did not produce, since the whole reason for it is the producer that has not
 * been written yet.
 *
 * Mutation matrix, measured against the patched source:
 *
 *   remove the framing check ................. every malformed leg
 *   consume the trailing CRLF instead of
 *     looking ahead for it .................... duplicate_length
 *   tolerate duplicates (`$fields < 1`) ....... duplicate_length
 *   drop the length comparison ................ advertised_over, _under
 *
 * The second row is not hypothetical: the check shipped that way in its first
 * draft. `preg_match_all` does not find overlapping matches, so consuming the
 * trailing CRLF swallowed the delimiter the next field needed and two adjacent
 * `Content-Length` fields counted as one. This leg caught it.
 */
$probe = [
   'error' => '',
   'cases' => [],
];

return new Test(
   description: 'A route cache entry must carry the body its head advertises',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $WPI = WPI;
      $Old = $WPI->Request;
      $Socket = fopen('php://temp', 'w+');

      try {
         // ! Build a response that satisfies every OTHER stash() condition —
         //   GET, HTTP/1.1, 200, cacheable, no cookies, no credentials, a
         //   storable Cache-Control — then hand it the buffer under test.
         $Stash = static function (string $URI, string $buffer) use (&$Socket): array {
            $WPI = WPI;

            $Connection = new M4FramingConnection($Socket);
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
            $Response(code: 200, body: 'HELLO');
            $Response->Header->set('Cache-Control', 'public, max-age=60');
            $Response->cache = 60;

            $key = Cache::compose($Request);
            $Response->stash($buffer);
            $stored = Cache::fetch($key);

            return [
               'stored' => $stored !== null,
               'bytes' => $stored === null ? 0 : strlen($stored),
            ];
         };

         $token = bin2hex(random_bytes(6));
         $head = "HTTP/1.1 200 OK\r\nServer: Bootgly\r\nCache-Control: public, max-age=60";

         $probe['cases'] = [
            // # CONTROL — head and body agree. Must still be stored, or the
            //   check has simply disabled the route cache.
            'consistent' => $Stash(
               "/m4f-{$token}-ok",
               "{$head}\r\nContent-Length: 5\r\n\r\nHELLO"
            ),
            // # The M4 shape, generalized: the head promises bytes that are
            //   not there. A warm hit reads the next response as this body.
            'advertised_over' => $Stash(
               "/m4f-{$token}-over",
               "{$head}\r\nContent-Length: 99\r\n\r\nHELLO"
            ),
            // # The mirror: bytes are there that the head does not account
            //   for, so the tail is read as the start of the next response.
            'advertised_under' => $Stash(
               "/m4f-{$token}-under",
               "{$head}\r\nContent-Length: 2\r\n\r\nHELLO"
            ),
            // # No framing at all — the client cannot know where this ends.
            'no_length' => $Stash(
               "/m4f-{$token}-none",
               "{$head}\r\n\r\nHELLO"
            ),
            // # Two fields are a smuggling shape whatever the values say, and
            //   must never become wire that is replayed to many clients.
            'duplicate_length' => $Stash(
               "/m4f-{$token}-dup",
               "{$head}\r\nContent-Length: 5\r\nContent-Length: 5\r\n\r\nHELLO"
            ),
            // # Not a message: no head/body separator.
            'no_separator' => $Stash(
               "/m4f-{$token}-nosep",
               "{$head}\r\nContent-Length: 5\r\n"
            ),
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

      return "GET /m4-framing-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/m4-framing-harness',
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
         return 'Cache framing PoC harness failed: ' . $probe['error'];
      }
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Cache framing PoC harness request did not reach its control route.';
      }

      $C = $probe['cases'];
      $Evidence = static function () use ($C): void {
         Vars::$labels = ['cache entry framing'];
         dump(json_encode($C, JSON_UNESCAPED_SLASHES));
      };

      // @ Control first — a consistent entry must still be stored. Without
      //   this, a check that refused everything would pass every assertion
      //   below while silently disabling the route cache.
      if ($C['consistent']['stored'] !== true) {
         $Evidence();

         return 'Control failed: an entry whose head and body agree was not stored. The '
            . 'framing check refuses everything rather than only malformed entries.';
      }

      $findings = [];
      foreach ([
         'advertised_over' => 'a head advertising 99 bytes over a 5-byte body',
         'advertised_under' => 'a head advertising 2 bytes over a 5-byte body',
         'no_length' => 'an entry with no Content-Length at all',
         'duplicate_length' => 'an entry carrying two Content-Length fields',
         'no_separator' => 'an entry with no head/body separator',
      ] as $key => $label) {
         if (($C[$key]['stored'] ?? false) === true) {
            $findings[] = "{$label} was stored (" . $C[$key]['bytes'] . ' bytes)';
         }
      }

      if ($findings !== []) {
         $Evidence();

         return 'CONFIRMED: the route cache accepts an entry that is not a complete message, '
            . 'so every warm hit desynchronizes the connection that replays it — '
            . implode('; ', $findings) . '.';
      }

      return true;
   }
);
