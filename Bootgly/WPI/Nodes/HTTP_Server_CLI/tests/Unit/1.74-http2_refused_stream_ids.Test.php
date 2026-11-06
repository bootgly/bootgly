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
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * A stream id the HTTP/2 decoder refuses or resets is a used id — never an
 * idle one — and a GOAWAY never names more than the graceful one announced,
 * driven over a real connection on a socket pair:
 *
 * - A HEADERS past the concurrency cap is refused (`REFUSED_STREAM`); the
 *   DATA that follows on that stream is a stream error (`STREAM_CLOSED`),
 *   not the connection error an idle stream draws.
 * - A HEADERS that depends on itself is reset (`PROTOCOL_ERROR`); the DATA
 *   that follows on it is a stream error too.
 * - After `retire()` — a recycling worker's graceful GOAWAY — a PRIORITY
 *   that depends on itself on a new stream is reset, and a connection error
 *   after it sends a GOAWAY naming the Last-Stream-ID `retire()` announced,
 *   never that stream (RFC 9113 §6.8).
 */
return new Test(
   description: 'HTTP/2: refused and reset stream ids are used, never idle; no GOAWAY names more than the graceful one',
   test: new Assertions(Case: function (): Generator {
      // ! Statics this case writes — directly or through the encoder
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $OldEvent = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldContext = isSet(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $OldRequest = isSet(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;
      $OldResponse = isSet(HTTP_Server_CLI::$Response) ? HTTP_Server_CLI::$Response : null;
      $OldRouter = isSet(HTTP_Server_CLI::$Router) ? HTTP_Server_CLI::$Router : null;
      $OldHandler = isSet(SAPI::$Handler) ? SAPI::$Handler : null;
      $OldMiddlewares = isSet(SAPI::$Middlewares) ? SAPI::$Middlewares : null;
      $OldEnvironment = SAPI::$Environment;
      $OldCatcher = Catcher::$Environment;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;
      $oldStreams = Decoder_HTTP2::$streams;
      // ! No SIGALRM while this case runs: a pending one waits until the end
      $oldAlarm = pcntl_alarm(0);

      $Sockets = [];
      $Connection = null;
      try {
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         // ! Plain connections: a TLS context an earlier suite left behind
         //   would make every socket pair start a handshake and close
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$Decoder = new Decoder_;
         TCP_Server_CLI::$Encoder = new Encoder_Testing;
         TCP_Server_CLI::$Event = (new ReflectionClass(Select::class))->newInstanceWithoutConstructor();
         HTTP_Server_CLI::$enableHTTP2 = true;
         HTTP_Server_CLI::$Response = new Response;
         HTTP_Server_CLI::$Router = new Router;
         SAPI::$Middlewares = new Middlewares;
         SAPI::$Environment = Environments::Test;
         Catcher::$Environment = null;
         SAPI::$Handler = static fn (Request $Request, Response $Response): Response => $Response(body: 'answered');
         // ! One concurrent stream: the second is past the cap
         Decoder_HTTP2::$streams = 1;

         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('Could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         $Sockets = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         $Connection = new Connection($Server, '127.0.0.1', 40074);

         /**
          * The stream-level and connection-level frames the server wrote.
          *
          * @return array<int,array{string,int,int}> [type, stream or Last-Stream-ID, error code]
          */
         $Frames = static function () use ($Client): array {
            $wire = '';
            while (($chunk = @fread($Client, 65_536)) !== false && $chunk !== '') {
               $wire .= $chunk;
            }
            $Frames = [];
            $offset = 0;
            while (strlen($wire) - $offset >= 9) {
               $size = (ord($wire[$offset]) << 16) | (ord($wire[$offset + 1]) << 8) | ord($wire[$offset + 2]);
               if (strlen($wire) - $offset < 9 + $size) {
                  break;
               }
               $type = ord($wire[$offset + 3]);
               $stream = ((ord($wire[$offset + 5]) & 0x7f) << 24) | (ord($wire[$offset + 6]) << 16)
                  | (ord($wire[$offset + 7]) << 8) | ord($wire[$offset + 8]);
               $payload = substr($wire, $offset + 9, $size);
               $offset += 9 + $size;

               if ($type === HTTP2::FRAME_RST_STREAM) {
                  /** @var array{1:int} $code */
                  $code = unpack('N', $payload);
                  $Frames[] = ['RST_STREAM', $stream, $code[1]];
               }
               else if ($type === HTTP2::FRAME_GOAWAY) {
                  /** @var array{last:int,error:int} $GOAWAY */
                  $GOAWAY = unpack('Nlast/Nerror', $payload);
                  $Frames[] = ['GOAWAY', $GOAWAY['last'] & 0x7fffffff, $GOAWAY['error']];
               }
            }

            return $Frames;
         };
         $Head = static fn (string $path): string => HPACK::encode([
            [':method', 'POST'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
         ]);

         // @ Past the cap, then on itself — each followed by its DATA
         fwrite($Client, HTTP2::PREFACE
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
            . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1, $Head('/open'))
            . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 3, $Head('/capped'))
            . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 3, 'x')
            . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_PRIORITY, 5, pack('NC', 5, 16) . $Head('/circular'))
            . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 5, 'x'));
         $Connection->reading($Server);
         $Used = $Frames();
         $Decoder = $Connection->decoded;
         $peak = $Decoder instanceof Decoder_HTTP2 ? $Decoder->peak : -1;

         // @ The graceful GOAWAY, then a self-dependent PRIORITY on a new
         //   stream, then a connection error
         $retired = $Decoder instanceof Decoder_HTTP2 && $Decoder->retire();
         $Retired = $Frames();
         // ! `@`: a connection an earlier frame ended takes no more — the
         //   frames already read tell it
         @fwrite($Client, Frame::pack(HTTP2::FRAME_PRIORITY, 0, 7, pack('NC', 7, 16))
            . Frame::pack(HTTP2::FRAME_WINDOW_UPDATE, 0, 9, pack('N', 1)));
         if (is_resource($Server)) {
            $Connection->reading($Server);
         }
         $After = $Frames();

         yield new Assertion(description: 'a refused or reset stream id is used, never idle: its DATA draws a stream error, no GOAWAY')
            ->expect(['frames' => $Used, 'peak' => $peak], Op::Identical, [
               'frames' => [
                  ['RST_STREAM', 3, Errors::RefusedStream->value],
                  ['RST_STREAM', 3, Errors::StreamClosed->value],
                  ['RST_STREAM', 5, Errors::Protocol->value],
                  ['RST_STREAM', 5, Errors::StreamClosed->value],
               ],
               'peak' => 5,
            ])
            ->assert();

         yield new Assertion(description: 'after the graceful GOAWAY, no later GOAWAY names more — not even after a self-dependent PRIORITY on a new stream')
            ->expect(['retired' => $retired, 'graceful' => $Retired, 'after' => $After], Op::Identical, [
               'retired' => true,
               'graceful' => [['GOAWAY', 5, Errors::None->value]],
               'after' => [
                  ['RST_STREAM', 7, Errors::Protocol->value],
                  ['GOAWAY', 5, Errors::Protocol->value],
               ],
            ])
            ->assert();
      }
      finally {
         try {
            $Connection?->close();
         }
         catch (Throwable) {
            // Teardown only.
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }
         Decoder_HTTP2::$streams = $oldStreams;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         Catcher::$Environment = $OldCatcher;
         SAPI::$Environment = $OldEnvironment;
         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldRequest !== null) {
            HTTP_Server_CLI::$Request = $OldRequest;
         }
         if ($OldResponse !== null) {
            HTTP_Server_CLI::$Response = $OldResponse;
         }
         if ($OldRouter !== null) {
            HTTP_Server_CLI::$Router = $OldRouter;
         }
         if ($oldAlarm > 0) {
            pcntl_alarm($oldAlarm);
         }
      }
   })
);
