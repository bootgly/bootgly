<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * H-HSC-6 — a read event that brings no bytes still decodes the requests a
 * live connection holds in its receive carry.
 *
 * A parked HTTP/1.1 deferral holds the requests pipelined behind it
 * undecoded in the carry; once its response is written, the hold is lifted
 * and `reading()` is called again — usually with nothing new on the socket.
 * The zero-byte guard used to return before the carry was reassembled, so
 * those requests waited for bytes that never came. Pinned, on a real
 * connection over a socket pair:
 *
 * - a complete request held in the carry is decoded and answered on a read
 *   that brings nothing — exactly once: a second empty read answers nothing
 *   more — and the carry is released;
 * - a peer that closed while a request was held is closed on that read, the
 *   held request never decoded nor answered.
 */
if (! class_exists('U164Holding', false)) {
   /**
    * Holds every byte, consuming nothing: what a parked deferral installs.
    */
   class U164Holding extends Decoders
   {
      public function decode (ServerPackages $Package, string $buffer, int $size): States
      {
         $Package->consumed = 0;

         return States::Incomplete;
      }
   }
}
if (! class_exists('U164Encoder', false)) {
   class U164Encoder implements ServerEncoder
   {
      // * Metadata
      public static int $responses = 0;

      public static function encode (ServerPackages $Package, null|int &$length): string
      {
         $count = ++self::$responses;
         $body = "R{$count}";
         $length = strlen($body);

         return $body;
      }
   }
}


return new Test(
   description: 'H-HSC-6: an empty read decodes the requests a live connection holds in its carry, once',
   test: new Assertions(Case: function (): Generator {
      // ! Statics this case writes — directly, or through the decoder
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $OldRequest = isset(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;

      $Sockets = [];
      $Select = null;

      // ! A server end that reads everything the client end sent, up to one
      //   transport read, and a client end that never blocks
      $Open = static function () use (&$Sockets): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U164 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         stream_set_read_buffer($Server, 0);
         $Sockets[] = $Server;
         $Sockets[] = $Client;

         return [$Server, $Client];
      };
      // ! Whatever reached the client end, without blocking
      $Receive = static function (mixed $Client): string {
         $wire = '';
         while (is_resource($Client)) {
            $chunk = @fread($Client, 65_536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $wire .= $chunk;
         }

         return $wire;
      };
      $request = "GET /u164 HTTP/1.1\r\nHost: localhost\r\n\r\n";

      try {
         // ! The worker reactor (teardown deletes from it) and plain-TCP
         //   connections without an idle timer
         $Peers = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Select = new Select($Peers);
         TCP_Server_CLI::$Event = $Select;
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         TCP_Server_CLI::$Decoder = new Decoder_;
         TCP_Server_CLI::$Encoder = new U164Encoder;
         U164Encoder::$responses = 0;

         // @@ A) A held request, released, decodes on a read with no bytes
         [$Socket, $Client] = $Open();
         $Connection = new Connection($Socket, '127.0.0.1', 40003);
         $Connection->Decoder = new U164Holding;
         fwrite($Client, $request);
         $Connection->reading($Socket);
         $held = [
            'carry' => $Connection->carry === $request,
            'written' => $Receive($Client),
            'responses' => U164Encoder::$responses,
         ];

         // @ The hold is lifted; nothing new arrives
         $Connection->Decoder = null;
         $Connection->reading($Socket);
         $first = [
            'written' => $Receive($Client),
            'responses' => U164Encoder::$responses,
            'carry' => $Connection->carry,
            'retained' => $Connection->Buffers->retained,
            'status' => $Connection->status,
         ];
         // @ Another read with nothing: nothing is left to decode
         $Connection->reading($Socket);
         $second = [
            'written' => $Receive($Client),
            'responses' => U164Encoder::$responses,
            'status' => $Connection->status,
         ];
         $evidence = json_encode(['held' => $held, 'first' => $first, 'second' => $second]);

         yield assert(
            assertion: $held === ['carry' => true, 'written' => '', 'responses' => 0]
               && $first === [
                  'written' => 'R1',
                  'responses' => 1,
                  'carry' => '',
                  'retained' => 0,
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ]
               && $second === [
                  'written' => '',
                  'responses' => 1,
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ],
            description: "A request held in the carry is decoded and answered once on a read that brings no bytes: {$evidence}",
         );

         // @@ B) The peer left while a request was held: closed, not decoded
         U164Encoder::$responses = 0;
         [$Socket, $Client] = $Open();
         $Connection = new Connection($Socket, '127.0.0.1', 40004);
         $Connection->Decoder = new U164Holding;
         fwrite($Client, $request);
         $Connection->reading($Socket);
         $kept = $Connection->carry === $request;
         $Connection->Decoder = null;
         fclose($Client);
         $Connection->reading($Socket);
         $left = [
            'kept' => $kept,
            'status' => $Connection->status,
            'carry' => $Connection->carry,
            'responses' => U164Encoder::$responses,
         ];
         $evidence = json_encode($left);

         yield assert(
            assertion: $left === [
               'kept' => true,
               'status' => TCPConnections::STATUS_CLOSED,
               'carry' => '',
               'responses' => 0,
            ],
            description: "A peer that left while a request was held is closed on the empty read, the request never decoded: {$evidence}",
         );
      }
      finally {
         if ($Select !== null) {
            try {
               $Select->destroy();
            }
            catch (Throwable) {
               // Teardown only.
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }

         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         // ! A typed static no earlier case set cannot return to unset: it
         //   is left inert, as a worker boots with it — the destroyed (empty)
         //   reactor, an empty context and a fresh Request
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         HTTP_Server_CLI::$Request = $OldRequest ?? new Request;
      }
   })
);
