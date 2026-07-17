<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression H8 — aggregate HTTP/2 request-body retention.
 *
 * Three unfinished streams each receive exactly their 64 KiB per-stream
 * allowance. The reduced cap avoids allocating the projected production
 * maximum, while the production 32 KiB replenish threshold is retained so
 * the same probe also observes connection and stream WINDOW_UPDATE credit.
 * The connection budget is set to one stream allowance. A secure decoder
 * keeps stream 1, resets excess streams 3 and 5 before concatenating their
 * DATA, and releases the exact reservation on disconnect.
 */

$probe = [
   'error' => '',
   'state' => '',
   'consumed' => -1,
   'wire_bytes' => 0,
   'prefaced' => false,
   'settled' => false,
   'closing' => false,
   'open_streams' => 0,
   'stream_body_bytes' => [],
   'aggregate_body_bytes' => 0,
   'per_stream_cap' => 64 * 1024,
   'connection_probe_budget' => 64 * 1024,
   'connection_window_updates' => 0,
   'stream_window_updates' => [],
   'reset_streams' => [],
   'retained_before_disconnect' => -1,
   'retained_after_disconnect' => -1,
   'default_stream_cap' => Decoder_HTTP2::$streams,
   'default_body_cap' => Request::$maxBodySize,
   'projected_default_body_bytes' => Decoder_HTTP2::$streams * Request::$maxBodySize,
   'default_connection_body_cap' => Decoder_HTTP2::$maxConnectionBodySize,
   'default_worker_body_cap' => Decoder_HTTP2::$maxWorkerBodySize,
];

return new Specification(
   description: 'HTTP/2 unfinished request bodies must obey an aggregate per-connection budget',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $oldBodySize = Request::$maxBodySize;
      $oldStreams = Decoder_HTTP2::$streams;
      $oldReplenish = Decoder_HTTP2::$replenish;
      $oldConnectionBodySize = Decoder_HTTP2::$maxConnectionBodySize;
      $oldWorkerBodySize = Decoder_HTTP2::$maxWorkerBodySize;
      $Socket = null;
      $Decoder = null;

      try {
         Request::$maxBodySize = $probe['per_stream_cap'];
         Decoder_HTTP2::$streams = 3;
         Decoder_HTTP2::$replenish = 32 * 1024;
         Decoder_HTTP2::$maxConnectionBodySize = $probe['connection_probe_budget'];
         Decoder_HTTP2::$maxWorkerBodySize = 2 * $probe['connection_probe_budget'];

         $Socket = fopen('php://temp', 'w+b');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not open the HTTP/2 control-frame capture stream.');
         }

         $Connection = new class($Socket) extends Connection {
            public function __construct (&$Socket)
            {
               $this->Socket = $Socket;
               $this->timers = [];
               $this->expiration = 0;
               $this->ip = '127.0.0.1';
               $this->port = 0;
               $this->encrypted = false;
               $this->handshaking = false;
               $this->handshakeTimer = 0;
               $this->status = Connections::STATUS_ESTABLISHED;
               $this->started = time();
               $this->used = time();
               $this->writes = 0;
            }
         };
         $Package = new class($Connection) extends TCPPackages {};
         $Decoder = new Decoder_HTTP2;

         $wire = HTTP2::PREFACE . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0);
         foreach ([1, 3, 5] as $stream) {
            $HPACKBlock = HPACK::encode([
               [':method', 'POST'],
               [':scheme', 'http'],
               [':path', "/h8/{$stream}"],
               [':authority', 'localhost'],
               ['content-length', (string) $probe['per_stream_cap']],
            ]);
            $wire .= Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS,
               $stream,
               $HPACKBlock,
            );

            for ($frame = 0; $frame < 4; $frame++) {
               $wire .= Frame::pack(
                  HTTP2::FRAME_DATA,
                  0,
                  $stream,
                  str_repeat(chr(64 + $stream), 16 * 1024),
               );
            }
         }

         $probe['wire_bytes'] = strlen($wire);
         $State = $Decoder->decode($Package, $wire, strlen($wire));

         $probe['state'] = $State->name;
         $probe['consumed'] = $Package->consumed;
         $probe['prefaced'] = $Decoder->prefaced;
         $probe['settled'] = $Decoder->settled;
         $probe['closing'] = $Decoder->closing;
         $probe['open_streams'] = $Decoder->opened;
         foreach ($Decoder->Streams as $stream => $Stream) {
            $bytes = strlen($Stream->body);
            $probe['stream_body_bytes'][$stream] = $bytes;
            $probe['aggregate_body_bytes'] += $bytes;
         }
         $probe['retained_before_disconnect'] = $Decoder->Bodies->retained;

         rewind($Socket);
         $output = stream_get_contents($Socket);
         if ($output === false) {
            throw new RuntimeException('Could not read the emitted HTTP/2 control frames.');
         }

         $offset = 0;
         $length = strlen($output);
         while ($length - $offset >= 9) {
            $head = unpack('Nword/Cflags/Nstream', $output, $offset);
            if ($head === false) {
               break;
            }

            $type = $head['word'] & 0xff;
            $payload = $head['word'] >> 8;
            $stream = $head['stream'] & 0x7fffffff;
            if ($length - $offset < 9 + $payload) {
               break;
            }

            if ($type === HTTP2::FRAME_WINDOW_UPDATE) {
               if ($stream === 0) {
                  $probe['connection_window_updates']++;
               }
               else {
                  $probe['stream_window_updates'][$stream]
                     = ($probe['stream_window_updates'][$stream] ?? 0) + 1;
               }
            }
            else if (
               $type === HTTP2::FRAME_RST_STREAM
               && ! in_array($stream, $probe['reset_streams'], true)
            ) {
               $probe['reset_streams'][] = $stream;
            }

            $offset += 9 + $payload;
         }
         ksort($probe['stream_body_bytes']);
         ksort($probe['stream_window_updates']);
         sort($probe['reset_streams']);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         if ($Decoder !== null) {
            $Decoder->disconnect();
            $probe['retained_after_disconnect'] = $Decoder->Bodies->retained;
         }
         if (is_resource($Socket)) {
            fclose($Socket);
         }

         Request::$maxBodySize = $oldBodySize;
         Decoder_HTTP2::$streams = $oldStreams;
         Decoder_HTTP2::$replenish = $oldReplenish;
         Decoder_HTTP2::$maxConnectionBodySize = $oldConnectionBodySize;
         Decoder_HTTP2::$maxWorkerBodySize = $oldWorkerBodySize;
      }

      return "GET /h8-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h8-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H8-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'H8-HARNESS-OK')) {
         return 'H8 harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         return $probe['error'];
      }
      if (
         $probe['default_connection_body_cap'] !== 10 * 1024 * 1024
         || $probe['default_worker_body_cap'] !== 64 * 1024 * 1024
      ) {
         Vars::$labels = ['H8 protective defaults'];
         dump(json_encode($probe));
         return 'HTTP/2 aggregate body budgets no longer have finite protective defaults.';
      }
      if (
         $probe['state'] !== States::Incomplete->name
         || $probe['consumed'] !== $probe['wire_bytes']
         || ! $probe['prefaced']
         || ! $probe['settled']
         || $probe['closing']
      ) {
         Vars::$labels = ['H8 HTTP/2 frame-feed setup'];
         dump(json_encode($probe));
         return 'The H8 probe was not fully accepted as a live unfinished HTTP/2 connection.';
      }

      if ($probe['aggregate_body_bytes'] > $probe['connection_probe_budget']) {
         Vars::$labels = ['H8 aggregate HTTP/2 body retention evidence'];
         dump(json_encode($probe));
         return 'H8 still reproduced: aggregate unfinished HTTP/2 bodies exceeded the '
            . 'configured per-connection budget; evidence=' . json_encode($probe);
      }

      if (
         $probe['open_streams'] !== 1
         || $probe['stream_body_bytes'] !== [1 => 65536]
         || $probe['aggregate_body_bytes'] !== 65536
         || $probe['retained_before_disconnect'] !== 65536
         || $probe['retained_after_disconnect'] !== 0
         || $probe['reset_streams'] !== [3, 5]
         || $probe['stream_window_updates'] !== [1 => 2]
      ) {
         Vars::$labels = ['H8 aggregate-budget regression evidence'];
         dump(json_encode($probe));
         return 'The H8 budget did not retain exactly one allowed stream, reset both excess '
            . 'streams, and release its reservation on disconnect; evidence=' . json_encode($probe);
      }

      return true;
   },
);
