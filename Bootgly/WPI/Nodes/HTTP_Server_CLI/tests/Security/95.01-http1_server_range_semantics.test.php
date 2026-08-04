<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


if (! class_exists('M3RangeConnection', false)) {
   class M3RangeConnection extends Connection
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
 * PoC — a server-initiated `upload($file, $offset, $length)` must describe the
 * bytes it actually sends, and must refuse a window the file cannot satisfy.
 *
 * `Response::upload()` keeps two shapes in one array. On the client-`Range`
 * path the entries are real `{start, end}` offsets and everything downstream is
 * correct. On the server-initiated path the same `end` slot receives a
 * *length*, and that value is emitted verbatim as an end offset.
 *
 * The assertions are pure header arithmetic, so this drives `upload()` and the
 * production encoder inline rather than over a socket — the transport plays no
 * part in computing a Content-Range.
 *
 * Deliberately unchanged, and asserted so by the controls: the response stays
 * `206` with a `Content-Range` even though no client asked for a range. A `200`
 * carrying only part of the representation would misdescribe it; `206` states
 * exactly which bytes of which whole the client received.
 */
$probe = [
   'error' => '',
   'cases' => [],
];

return new Specification(
   description: 'Server-initiated upload() ranges must describe the bytes actually sent',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $WPI = WPI;
      $Old = $WPI->Request;
      $Socket = fopen('php://temp', 'w+');

      try {
         // ! Every case gets a fresh Package: upload() failures leave the
         //   Response unstreamed, and a shared queue would blur the cases.
         $Run = static function (
            null|int $offset,
            null|int $length,
            null|string $clientRange
         ) use (&$Socket): array {
            $WPI = WPI;

            $Connection = new M3RangeConnection($Socket);
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
            if ($clientRange !== null) {
               // ! The decoder lowercase-normalizes inbound field names.
               $Request->Header->adopt(['range' => $clientRange]);
            }
            $WPI->Request = $Request;

            $Response = new Response;
            $Response->reset($Package, $Request);
            if ($offset === null) {
               $Response->upload('statics/alphanumeric.txt', close: false);
            }
            else {
               $Response->upload(
                  'statics/alphanumeric.txt',
                  offset: $offset,
                  length: $length,
                  close: false
               );
            }

            $encoded = null;
            $head = $Response->encode($Package, $encoded);

            preg_match('/^HTTP\/1\.[01] ([0-9]{3})/', $head, $status);
            preg_match('/\r\nContent-Length:\s*([0-9]+)\r\n/i', $head, $cl);
            preg_match('/\r\nContent-Range:\s*([^\r\n]*)\r\n/i', $head, $cr);

            $part = $Package->uploading[0]['parts'][0] ?? null;

            return [
               'code' => isset($status[1]) ? (int) $status[1] : 0,
               'length' => isset($cl[1]) ? (int) $cl[1] : null,
               'range' => $cr[1] ?? null,
               'queued' => count($Package->uploading),
               'part' => is_array($part)
                  ? ['offset' => $part['offset'] ?? null, 'length' => $part['length'] ?? null]
                  : null,
            ];
         };

         $probe['cases'] = [
            'head_two'     => $Run(0, 2, null),
            'mid'          => $Run(10, 5, null),
            'tail'         => $Run(57, null, null),
            'over_length'  => $Run(0, 999999, null),
            'past_offset'  => $Run(999, null, null),
            'client_range' => $Run(null, null, 'bytes=0-2'),
            'whole_file'   => $Run(null, null, null),
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

      return "GET /m3-range-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/m3-range-harness',
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
         return 'Server-range PoC harness failed: ' . $probe['error'];
      }
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Server-range PoC harness request did not reach its control route.';
      }

      $size = 62; // statics/alphanumeric.txt
      $C = $probe['cases'];

      $Evidence = static function () use ($C): void {
         Vars::$labels = ['server-range PoC'];
         dump(json_encode($C, JSON_UNESCAPED_SLASHES));
      };
      $show = static fn (array $c): string => json_encode([
         $c['code'], $c['length'], $c['range'], $c['part'],
      ], JSON_UNESCAPED_SLASHES);

      // @ Controls — the client-Range path and the whole-file path must be
      //   untouched, or every assertion below measures a broken upload().
      if (
         $C['client_range']['code'] !== 206
         || $C['client_range']['length'] !== 3
         || $C['client_range']['range'] !== "bytes 0-2/{$size}"
         || $C['client_range']['part'] !== ['offset' => 0, 'length' => 3]
      ) {
         $Evidence();

         return 'Control failed: the client `Range: bytes=0-2` path no longer answers 3 bytes '
            . 'as `bytes 0-2/62` — got ' . $show($C['client_range']) . '.';
      }
      if (
         $C['whole_file']['code'] !== 200
         || $C['whole_file']['length'] !== $size
         || $C['whole_file']['range'] !== null
         || $C['whole_file']['part'] !== ['offset' => 0, 'length' => $size]
      ) {
         $Evidence();

         return 'Control failed: a plain whole-file upload() no longer answers 200 with the '
            . 'complete representation and no Content-Range — got '
            . $show($C['whole_file']) . '.';
      }

      // @ The server-initiated windows: end MUST be offset + length - 1, and
      //   the queued part must carry the same bytes the header describes.
      $expected = [
         'head_two' => ['offset 0 length 2', 206, 2, "bytes 0-1/{$size}", ['offset' => 0, 'length' => 2]],
         'mid'      => ['offset 10 length 5', 206, 5, "bytes 10-14/{$size}", ['offset' => 10, 'length' => 5]],
         'tail'     => ['offset 57, no length', 206, 5, "bytes 57-61/{$size}", ['offset' => 57, 'length' => 5]],
      ];

      $findings = [];
      foreach ($expected as $key => [$label, $code, $length, $range, $part]) {
         $got = $C[$key];
         if (
            $got['code'] !== $code
            || $got['length'] !== $length
            || $got['range'] !== $range
            || $got['part'] !== $part
         ) {
            $findings[] = "{$label} answered " . $show($got)
               . ' but the bytes it sends are ' . json_encode([$code, $length, $range, $part]);
         }
      }

      // @ A window the file cannot satisfy must be refused at the source,
      //   before a head is built — not left for the transport to abort
      //   mid-message, with the Content-Length already committed.
      foreach ([
         'over_length' => 'a length past the end of the file',
         'past_offset' => 'an offset past the end of the file',
      ] as $key => $label) {
         $got = $C[$key];
         if ($got['code'] < 400 || $got['queued'] !== 0) {
            $findings[] = "{$label} was accepted: " . $show($got)
               . ' with ' . $got['queued'] . ' part(s) queued';
         }
      }

      if ($findings !== []) {
         $Evidence();

         return 'CONFIRMED: server-initiated upload() ranges do not describe the bytes sent — '
            . implode('; ', $findings) . '.';
      }

      return true;
   }
);
