<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Waiting;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame as Head;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('HTTPServerCLIWorkerMemoryConnection', false)) {
   class HTTPServerCLIWorkerMemoryConnection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket, int $port)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 0;
         $this->ip = '127.0.0.1';
         $this->port = $port;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      // ! No event loop in this process: a close only marks the status.
      public function close (): true
      {
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}


/**
 * Security regression H-HSC-3 — unfinished request bodies, and the parsed
 * heads their decoders keep, are charged to the worker memory budget
 * (`TCP_Server_CLI::$maxWorkerPendingBytes`) at their allocator footprint, in
 * the Inbound share (at most half the budget).
 *
 * A body string of 1,048,600 bytes is only 1.0 MiB long, but the allocator
 * hands it a whole 2 MiB chunk (`Buffers::weigh()`); a raw-length ledger
 * admits about twice what `memory_limit` can hold. A head of 1,300 tiny
 * fields is under 16 KiB on the wire but costs a Request over 128 KiB once
 * parsed, and it stays with the unfinished body. A read a body adopts whole
 * keeps the whole transport read — in a Content-Length body, in a chunked
 * body and in the deferred snapshot of a completed one. Every leg drives the
 * production decoders in-process — HTTP/1 Content-Length (`Decoder_Waiting`
 * and the initial slice in `Request::decode()`), chunked, multipart text,
 * prior-knowledge HTTP/2 DATA and the deferred `Request::capture()` snapshot —
 * and reads the worker ledger (`TCP_Server_CLI::$pendingBytes`) around them.
 *
 * Every expected footprint is computed: `Buffers::weigh()` prices strings and
 * the parsed head is re-priced from the bytes sent (never read back from the
 * decoder). The case runs on a private ledger — the worker statics are saved,
 * zeroed and restored exactly — so whatever earlier cases in this process
 * left reserved or cached cannot move it.
 */

// ! The attack shape: a body string the allocator serves with a whole chunk.
$held = 1_048_600;
// ! What the peer declares — the body never finishes at `$held`.
$declared = 1_100_000;
// ! One transport read.
$slice = 65_536;
// ! The reduced budget the capture share leg runs under.
$budget = 8 * 1024 * 1024;
// ! What a parsed field value costs a Request beyond its strings.
$overhead = 96;

$Probe = new class {
   // * Data
   /** Ledger total (`TCP_Server_CLI::$pendingBytes`) once the case isolated it. */
   public int $base = -1;
   /** Ledger movement left after every leg released. */
   public int $residue = -1;
   /** Whether the raw unfinished-body ledgers were whole again at the end. */
   public bool $drained = false;
   /** Whether every isolated worker static got its saved value back. */
   public bool $restored = false;
   /** Ledger total the earlier cases left (before isolation). */
   public int $inherited = -1;
   /**
    * Worker statics this tree does not declare (nothing to isolate).
    *
    * @var array<int,string>
    */
   public array $missing = [];
   /** The smallest string length `Buffers::weigh()` prices at a whole chunk. */
   public int $step = 0;
   /** Harness failure outside the legs. */
   public string $error = '';
   /**
    * Evidence per row.
    *
    * @var array<string,array<string,mixed>>
    */
   public array $rows = [];
};

return new Test(
   description: 'H-HSC-3: unfinished request bodies and their parsed heads are charged to the worker memory budget at their allocator footprint',
   Separator: new Separator(line: true),

   request: static function () use ($Probe, $held, $declared, $slice, $budget, $overhead): string {
      // ! Every config static the legs change — restored in `finally`
      $limit = TCP_Server_CLI::$maxWorkerPendingBytes;
      $workerBodySize = Bodies::$maxWorkerBodySize;
      $bodySize = Request::$maxBodySize;
      $fieldSize = Request::$maxMultipartFieldSize;
      $connectionBodySize = Decoder_HTTP2::$maxConnectionBodySize;
      $HTTP2BodySize = Decoder_HTTP2::$maxWorkerBodySize;
      $streams = Decoder_HTTP2::$streams;
      $replenish = Decoder_HTTP2::$replenish;
      $OldRequest = isset(Server::$Request) ? Server::$Request : null;

      // ! The worker ledger statics the legs read and move: saved, zeroed
      //   (a fresh generation for the epoch, so a token left by an earlier
      //   case can neither charge nor credit this ledger) and restored
      //   exactly in `finally`
      $isolation = [
         [Buffers::class, 'total', 0],
         [Buffers::class, 'held', [0, 0, 0]],
         [Buffers::class, 'epoch', null],
         [Bodies::class, 'total', 0],
         [Decoder_HTTP2\Bodies::class, 'total', 0],
         [TCP_Server_CLI::class, 'pendingBytes', 0],
         [Cache::class, 'entries', []],
         [Cache::class, 'bytes', 0],
         [Cache::class, 'URIs', []],
         [Cache::class, 'generation', 0],
         [Cache::class, 'Buffers', null],
      ];
      /** @var array<int,array{0:ReflectionProperty,1:mixed}> $Statics */
      $Statics = [];

      /** @var array<int,resource> $Sockets */
      $Sockets = [];
      /** @var array<int,Closure> $Cleanups */
      $Cleanups = [];

      // @ The ledger's movement since the case isolated it
      $Measure = static fn (): int => TCP_Server_CLI::$pendingBytes - $Probe->base;

      // @ Run every registered cleanup, newest first — refused probes included
      $Release = static function () use (&$Cleanups): void {
         while ($Cleanups !== []) {
            $Cleanup = array_pop($Cleanups);
            try {
               $Cleanup();
            }
            catch (Throwable) {
               // One owner cannot keep the others holding.
            }
         }
      };

      // @ One leg: its evidence, or the Throwable that stopped it. Its owners
      //   are released either way, so the next leg starts from an idle ledger.
      $Run = static function (Closure $Leg) use ($Release): array {
         try {
            return $Leg();
         }
         catch (Throwable $Throwable) {
            $origin = $Throwable::class;

            return [
               'error' => "{$origin}: {$Throwable->getMessage()} (line {$Throwable->getLine()})",
            ];
         }
         finally {
            $Release();
         }
      };

      // @ One independent peer: its own socket surrogate, Connection, Package
      //   and Request — exactly what N concurrent connections look like.
      $Open = static function () use (&$Sockets): array {
         $Socket = fopen('php://temp', 'w+b');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not open a decoder socket surrogate.');
         }
         $Sockets[] = $Socket;

         $Connection = new HTTPServerCLIWorkerMemoryConnection($Socket, 18_700 + count($Sockets));
         $Package = new class($Connection) extends TCPPackages {};
         $Request = new Request;
         Server::$Request = $Request;

         return [$Package, $Request, $Socket];
      };

      // @ What the peer was told on its socket surrogate
      $Answer = static function (mixed $Socket): string {
         rewind($Socket);

         return (string) stream_get_contents($Socket);
      };

      // @ What the parsed head of `$wire` costs a Request — the design's
      //   price recomputed from the bytes sent: the raw header block and
      //   every field name and value at their footprint, plus `$overhead`
      //   bytes per value
      $Weigh = static function (string $wire) use ($overhead): int {
         $line = (int) strpos($wire, "\r\n");
         $separator = (int) strpos($wire, "\r\n\r\n");
         $block = substr($wire, $line + 2, $separator - $line);
         $bytes = Buffers::weigh(strlen($block));

         $names = [];
         // @@
         foreach (explode("\r\n", $block) as $header) {
            if ($header === '') {
               continue;
            }

            $colon = (int) strpos($header, ':');
            $name = strtolower(substr($header, 0, $colon));
            $value = trim(substr($header, $colon + 1), " \t");
            if (isset($names[$name]) === false) {
               $names[$name] = true;
               $bytes += Buffers::weigh(strlen($name));
            }
            $bytes += Buffers::weigh(strlen($value)) + $overhead;
         }

         // :
         return $bytes;
      };

      // @ What `Request\Frame::weigh()` reports for the same head, parsed by
      //   production on a fixture peer (null where the tree has no price)
      $Parse = static function (string $wire) use ($Open): null|int {
         [$Package] = $Open();
         $Frame = Head::parse($Package, $wire, strlen($wire));
         if ($Frame === null || method_exists($Frame, 'weigh') === false) {
            return null;
         }

         // :
         return $Frame->weigh();
      };

      // @ The parsed-head footprint the installed body decoder holds
      $Hold = static function (TCPPackages $Package): null|int {
         $Decoder = $Package->Decoder;
         if (
            ! $Decoder instanceof Decoder_Waiting
            && ! $Decoder instanceof Decoder_Chunked
            && ! $Decoder instanceof Decoder_Downloading
         ) {
            return null;
         }

         // :
         return $Decoder->Bodies->head ?? null;
      };

      // @ One peer whose first read is `$wire`; whatever decoder it installs
      //   is released with the leg
      $Send = static function (string $wire) use ($Open, &$Cleanups): array {
         [$Package, $Request, $Socket] = $Open();

         $State = $Request->decode($Package, $wire, strlen($wire));
         $Decoder = $Package->Decoder;

         $Cleanups[] = static function () use ($Package, $Decoder): void {
            if ($Decoder instanceof Disconnecting) {
               $Decoder->disconnect();
            }
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         };

         // :
         return [$Package, $Request, $Socket, $State];
      };

      // @ A Content-Length POST head followed by its first `$bytes` body bytes
      $Compose = static function (string $path, int $length, int $bytes): string {
         $fill = str_repeat('a', $bytes);

         // :
         return "POST {$path} HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/octet-stream\r\nContent-Length: {$length}\r\n\r\n{$fill}";
      };

      // @ That head arriving with its first `$bytes` body bytes
      $Post = static function (string $path, int $length, int $bytes) use ($Send, $Compose): array {
         $wire = $Compose($path, $length, $bytes);
         [$Package, $Request, $Socket, $State] = $Send($wire);

         // :
         return [$Package, $Request, $Socket, $State, $wire];
      };

      // @ Disconnect the peer's installed decoder, as a transport close does
      $Close = static function (TCPPackages $Package): void {
         $Decoder = $Package->Decoder;
         if ($Decoder instanceof Disconnecting) {
            $Decoder->disconnect();
         }
         $Package->Decoder = null;
      };

      // @ Continuation reads of up to one transport read until the body holds
      //   `$target` bytes (or the decoder leaves)
      $Drip = static function (TCPPackages $Package, Request $Request, int $target) use ($slice): string {
         $State = States::Incomplete;

         // @@
         while (strlen($Request->Body->raw) < $target) {
            $Decoder = $Package->Decoder;
            if (! $Decoder instanceof Decoder_Waiting) {
               return 'Detached';
            }

            $bytes = min($slice, $target - strlen($Request->Body->raw));
            $fill = str_repeat('a', $bytes);
            $State = $Decoder->decode($Package, $fill, $bytes);
            if ($State === States::Rejected) {
               break;
            }
         }

         // :
         return $State->name;
      };

      try {
         // @ Isolate the worker ledger
         $Probe->inherited = TCP_Server_CLI::$pendingBytes;
         foreach ($isolation as [$class, $name, $zero]) {
            if (property_exists($class, $name) === false) {
               $Probe->missing[] = "{$class}::\${$name}";
               continue;
            }

            $Static = new ReflectionProperty($class, $name);
            $saved = $Static->getValue();
            $Statics[] = [$Static, $saved];
            $Static->setValue(null, $name === 'epoch' ? (int) $saved + 1 : $zero);
         }
         $Probe->base = TCP_Server_CLI::$pendingBytes;

         // ! The chunk step: the smallest string `weigh()` prices at a whole
         //   2 MiB chunk (weigh() never decreases between a page and a chunk)
         $low = 4_096;
         $high = 2_093_056 - 32;
         // @@
         while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (Buffers::weigh($middle) >= 2_097_152) {
               $high = $middle;
            }
            else {
               $low = $middle + 1;
            }
         }
         $Probe->step = $low;
         $step = $low;

         Bodies::$maxWorkerBodySize = 64 * 1024 * 1024;
         Request::$maxBodySize = 10 * 1024 * 1024;
         Request::$maxMultipartFieldSize = 1024 * 1024;
         Decoder_HTTP2::$maxConnectionBodySize = 10 * 1024 * 1024;
         Decoder_HTTP2::$maxWorkerBodySize = 64 * 1024 * 1024;
         Decoder_HTTP2::$streams = 128;
         Decoder_HTTP2::$replenish = 32_768;

         // --- (a) One unfinished Content-Length body, with its head.
         $Probe->rows['a'] = $Run(static function () use (
            $Post, $Drip, $Close, $Measure, $Weigh, $Parse, $Hold, $held, $declared, $slice
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $row = ['start' => $Measure()];

            // @ The head arrives with its first slice (Request::decode)
            [$Package, $Request, , , $wire] = $Post('/hhsc3/a/complete', $declared, $slice);
            $row['installed'] = $Package->Decoder instanceof Decoder_Waiting;
            $row['head'] = $Weigh($wire);
            $row['frame'] = $Parse($wire);
            $row['declared'] = $Hold($Package);
            $row['initial'] = $Measure();

            // @ Continuations (Decoder_Waiting) until the string holds `$held`
            $row['drip'] = $Drip($Package, $Request, $held);
            $row['holding'] = strlen($Request->Body->raw);
            $row['charged'] = $Measure();

            // @ The body completes: it leaves the unfinished-body budget
            $row['finish'] = $Drip($Package, $Request, $declared);
            $row['completed'] = strlen($Request->Body->raw);
            $row['settled'] = $Measure();

            // @ A second peer stops at `$held` and its transport closes
            [$Dropped, $DroppedRequest, , , $droppedWire] = $Post('/hhsc3/a/dropped', $declared, $slice);
            $row['dropped_head'] = $Weigh($droppedWire);
            $row['dropped_drip'] = $Drip($Dropped, $DroppedRequest, $held);
            $row['dropped_holding'] = strlen($DroppedRequest->Body->raw);
            $row['dropped'] = $Measure();
            $row['rejected'] = [$Package->rejected, $Dropped->rejected];
            $Close($Dropped);
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (b) The Inbound share: two unfinished bodies and their heads
         //     leave room for exactly one more head.
         $Probe->rows['b'] = $Run(static function () use (
            $Post, $Compose, $Drip, $Close, $Measure, $Weigh, $Answer, &$Cleanups, $held, $declared, $slice
         ): array {
            // ! Every peer sends the same header block (the path is in the
            //   request line, not in the block) — each peer's head is
            //   re-priced below and must match
            $head = $Weigh($Compose('/hhsc3/b', $declared, 0));
            $chunk = Buffers::weigh($held);
            $inbound = 2 * ($chunk + $head) + $head;
            $budget = 2 * $inbound;

            TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
            $row = [
               'start' => $Measure(),
               'head' => $head,
               'inbound' => $inbound,
               'budget' => $budget,
               'holders' => [],
               'heads' => [],
            ];

            // @@ Two holders at `$held` bytes each
            $Holders = [];
            for ($peer = 0; $peer < 2; $peer++) {
               [$Package, $Request, , , $wire] = $Post("/hhsc3/b/{$peer}", $declared, $slice);
               $row['heads'][] = $Weigh($wire);
               $row['holders'][] = [
                  $Drip($Package, $Request, $held),
                  strlen($Request->Body->raw),
                  $Package->rejected,
               ];
               $Holders[] = $Package;
            }
            $row['held'] = $Measure();

            // @ A third peer whose first slice arrives with its head: the head
            //   fits the room left, the slice does not
            [$Third, , $ThirdSocket, $ThirdState, $wire] = $Post('/hhsc3/b/2', $declared, $slice);
            $row['heads'][] = $Weigh($wire);
            $row['third'] = [$ThirdState->name, $Third->rejected, $Answer($ThirdSocket)];
            $row['third_charge'] = $Measure();

            // @ A fourth peer: the head alone fills the share to the byte, its
            //   first continuation is refused
            [$Fourth, , $FourthSocket, , $wire] = $Post('/hhsc3/b/3', $declared, 0);
            $row['heads'][] = $Weigh($wire);
            $Decoder = $Fourth->Decoder;
            $installed = $Decoder instanceof Decoder_Waiting;
            $row['fourth_head'] = $Measure();
            $fill = str_repeat('a', $slice);
            $State = $installed
               ? $Decoder->decode($Fourth, $fill, $slice)
               : States::Rejected;
            $row['fourth'] = [$installed, $State->name, $Fourth->rejected, $Answer($FourthSocket)];
            $row['fourth_charge'] = $Measure();

            // @ Pending output still takes everything the bodies do not hold
            $Output = new Buffers;
            $Cleanups[] = static function () use ($Output): void {
               $Output->release();
            };
            $room = $budget - $Measure();
            $row['transport'] = [
               $Output->reserve($room),
               $Output->reserve($room + 1),
               $Output->retained,
            ];
            $row['room'] = $room;
            $Output->release();

            // @ Every holder closes
            foreach ($Holders as $Holder) {
               $Close($Holder);
            }
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (c) Chunked: one read moves the decoded body past the chunk step.
         $Probe->rows['c'] = $Run(static function () use (
            $Send, $Measure, $Weigh, $Parse, $Hold, $step
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $row = ['start' => $Measure()];

            $head = "POST /hhsc3/c HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n";
            [$Package] = $Send($head);
            $Decoder = $Package->Decoder;
            if (! $Decoder instanceof Decoder_Chunked) {
               throw new RuntimeException('The chunked head did not install Decoder_Chunked.');
            }
            $row['head'] = $Weigh($head);
            $row['frame'] = $Parse($head);
            $row['declared'] = $Hold($Package);
            $row['headed'] = $Measure();

            $Body = new ReflectionProperty(Decoder_Chunked::class, 'body');
            $Wire = new ReflectionProperty(Decoder_Chunked::class, 'buffer');

            // @ Read 1: one chunk leaves the decoded body just below the step
            $below = $step - 16;
            $row['below'] = $below;
            $hex = dechex($below);
            $fill = str_repeat('c', $below);
            $read = "{$hex}\r\n{$fill}\r\n";
            $State = $Decoder->decode($Package, $read, strlen($read));
            $body = strlen((string) $Body->getValue($Decoder));
            $wire = strlen((string) $Wire->getValue($Decoder));
            $row['first'] = [
               'state' => $State->name,
               'body' => $body,
               'wire' => $wire,
               'charged' => $Measure(),
               'held' => Buffers::weigh($body) + Buffers::weigh($wire),
               // ! The design's price for this read: the whole wire may join
               //   the body and the whole wire may stay behind it
               'price' => Buffers::weigh(strlen($read)) + Buffers::weigh(strlen($read)),
            ];

            // @ Read 2: a 64-byte chunk crosses the step inside this decode()
            //   call, and a partial size line stays behind as wire
            $fill = str_repeat('c', 64);
            $read = "40\r\n{$fill}\r\n40";
            $State = $Decoder->decode($Package, $read, strlen($read));
            $body = strlen((string) $Body->getValue($Decoder));
            $wire = strlen((string) $Wire->getValue($Decoder));
            $row['second'] = [
               'state' => $State->name,
               'body' => $body,
               'wire' => $wire,
               'charged' => $Measure(),
               'held' => Buffers::weigh($body) + Buffers::weigh($wire),
               'price' => Buffers::weigh($below + strlen($read)) + Buffers::weigh(strlen($read)),
            ];
            $row['rejected'] = $Package->rejected;

            $Decoder->disconnect();
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (d) Multipart: an unfinished text field just past the chunk step.
         $Probe->rows['d'] = $Run(static function () use (
            $Send, $Measure, $Weigh, $Parse, $Hold, &$Cleanups, $slice, $step
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $row = ['start' => $Measure(), 'size' => $step];
            $size = $step;

            $head = "POST /hhsc3/d HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=BootglyMemory\r\nContent-Length: 1200000\r\n\r\n";
            [$Package, $Request] = $Send($head);
            $Decoder = $Package->Decoder;
            $row['installed'] = $Decoder instanceof Decoder_Downloading;
            if (! $Decoder instanceof Decoder_Downloading) {
               throw new RuntimeException('The multipart head did not install Decoder_Downloading.');
            }
            $Cleanups[] = static function () use ($Request): void {
               $Request->clean();
            };
            $row['head'] = $Weigh($head);
            $row['frame'] = $Parse($head);
            $row['declared'] = $Hold($Package);

            $Field = new ReflectionProperty(Decoder_Downloading::class, 'fieldBuffer');

            // @ Read 1: the part head and the first slice of the field value
            $fill = str_repeat('d', $slice);
            $read = "--BootglyMemory\r\nContent-Disposition: form-data; name=\"field\"\r\n\r\n{$fill}";
            $First = $Decoder->decode($Package, $read, strlen($read));
            $appended = strlen((string) $Field->getValue($Decoder));

            // @ Read 2: the rest of the value — the boundary-sized tail carried
            //   behind it is the same on both reads, so the field gains exactly
            //   what this read adds
            $rest = $size - $appended;
            $fill = str_repeat('d', $rest);
            $Second = $Decoder->decode($Package, $fill, $rest);

            $Block = new ReflectionMethod(Decoder_Downloading::class, 'block');
            $Footprint = new ReflectionMethod(Decoder_Downloading::class, 'measure');
            $row['states'] = [$First->name, $Second->name];
            $row['field'] = strlen((string) $Field->getValue($Decoder));
            $row['rejected'] = $Package->rejected;
            $row['charged'] = $Measure();
            $row['retained'] = $Decoder->Bodies->retained;
            $row['measure'] = $Footprint->invoke($Decoder);
            $row['block'] = $Block->invoke(null, $size);

            $Decoder->disconnect();
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (e) HTTP/2: three unfinished streams on one connection.
         $Probe->rows['e'] = $Run(static function () use (
            $Open, $Measure, &$Cleanups, $held
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $row = ['start' => $Measure()];
            $identifiers = [1, 3, 5];

            // ! The connection shape both connections share: preface, SETTINGS
            //   and one HEADERS block (no END_STREAM) per stream
            $frames = [HTTP2::PREFACE, Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0)];
            foreach ($identifiers as $stream) {
               $block = HPACK::encode([
                  [':method', 'POST'],
                  [':scheme', 'http'],
                  [':path', "/hhsc3/e/{$stream}"],
                  [':authority', 'localhost'],
               ]);
               $frames[] = Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, $stream, $block);
            }
            $heads = implode('', $frames);

            // ! `$held` DATA bytes per stream in maximum-size frames
            foreach ($identifiers as $stream) {
               $left = $held;
               // @@
               while ($left > 0) {
                  $bytes = min(16_384, $left);
                  $frames[] = Frame::pack(HTTP2::FRAME_DATA, 0, $stream, str_repeat(chr(64 + $stream), $bytes));
                  $left -= $bytes;
               }
            }
            $wire = implode('', $frames);

            // @ Decode one connection, then reset its streams one by one,
            //   reading the ledger after every step
            $Connect = static function (string $input) use ($Open, $Measure, &$Cleanups, $identifiers): array {
               [$Package] = $Open();
               $Decoder = new Decoder_HTTP2;
               $Package->Decoder = $Decoder;
               $Package->decoded = $Decoder;
               $Cleanups[] = static function () use ($Decoder): void {
                  $Decoder->disconnect();
               };

               $State = $Decoder->decode($Package, $input, strlen($input));
               $leg = [
                  'state' => $State->name,
                  'closing' => $Decoder->closing,
                  'rejected' => $Package->rejected,
                  'opened' => $Decoder->opened,
                  'bodies' => array_map(
                     static fn (object $Stream): int => strlen($Stream->body),
                     $Decoder->Streams
                  ),
                  'steps' => [$Measure()],
                  'resets' => [],
               ];

               // @@ RST_STREAM (CANCEL) each stream
               foreach ($identifiers as $stream) {
                  $reset = Frame::pack(HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', 8));
                  $leg['resets'][] = $Decoder->decode($Package, $reset, strlen($reset))->name;
                  $leg['steps'][] = $Measure();
               }
               $leg['remaining'] = $Decoder->opened;

               $Decoder->disconnect();
               $leg['released'] = $Measure();

               // :
               return $leg;
            };

            $row['control'] = $Connect($heads);
            $row['attack'] = $Connect($wire);
            $row['delta'] = array_map(
               static fn (int $attack, int $control): int => $attack - $control,
               $row['attack']['steps'],
               $row['control']['steps']
            );

            // :
            return $row;
         });

         // --- (f) Request::capture(): the deferred snapshot of a complete body.
         $Probe->rows['f'] = $Run(static function () use (
            $Post, $Drip, $Measure, &$Cleanups, $held, $slice, $budget
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $row = ['start' => $Measure()];

            [$Package, $Request] = $Post('/hhsc3/f', $held, $slice);
            $row['finish'] = $Drip($Package, $Request, $held);
            $row['raw'] = strlen($Request->Body->raw);
            $row['completed'] = $Measure();

            // @ The snapshot charges its own copy of the body
            $Captured = $Request->capture();
            $Cleanups[] = static function () use ($Captured): void {
               $Captured->clean();
            };
            $row['charged'] = $Measure();
            $row['captured_raw'] = strlen($Captured->Body->raw);

            // ! Hold the reservation across clean(): the release must be the
            //   explicit one, never the destructor of a dropped object
            $Reservation = new ReflectionProperty(Request::class, 'Bodies');
            $Holding = $Reservation->getValue($Captured);
            $Captured->clean();
            $row['cleaned'] = $Measure();
            unset($Holding);

            // # Inbound share at the edge, with the worker budget far from full
            TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
            $room = intdiv($budget, 2) - Buffers::weigh($held);
            $Filler = new Bodies;
            $Cleanups[] = static function () use ($Filler): void {
               $Filler->release();
            };
            $row['filler'] = $Filler->reserve(1, $room);

            // @ Exactly the room left: admitted
            $Fit = $Request->capture();
            $Cleanups[] = static function () use ($Fit): void {
               $Fit->clean();
            };
            $row['fit'] = $Measure();
            $Fit->clean();
            $row['fit_cleaned'] = $Measure();

            // @ One byte less room: refused, and nothing moves
            $row['filler_plus'] = $Filler->reserve(1, $room + 1);
            $row['refused'] = null;
            try {
               $Over = $Request->capture();
               $Cleanups[] = static function () use ($Over): void {
                  $Over->clean();
               };
            }
            catch (RuntimeException $Exception) {
               $row['refused'] = $Exception->getMessage();
            }
            $row['refused_charge'] = $Measure();
            if (isset($Over)) {
               $Over->clean();
            }

            $Filler->release();
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (g) A parsed head of 1,300 fields and no body bytes, on each
         //     decoder that keeps one: Content-Length, chunked and unfinished
         //     multipart.
         $Probe->rows['g'] = $Run(static function () use (
            $Send, $Close, $Measure, $Weigh, $Parse, $Hold, $Answer
         ): array {
            $fields = implode('', array_map(
               static fn (int $number): string => sprintf("X-%04d: v\r\n", $number),
               range(0, 1_299)
            ));
            $wires = [
               'length' => "POST /hhsc3/g/length HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/octet-stream\r\nContent-Length: 1000000\r\n{$fields}\r\n",
               'chunked' => "POST /hhsc3/g/chunked HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n{$fields}\r\n",
               'multipart' => "POST /hhsc3/g/multipart HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=BootglyMemory\r\nContent-Length: 1000000\r\n{$fields}\r\n",
            ];
            $row = ['start' => $Measure()];

            // @@
            foreach ($wires as $shape => $wire) {
               $head = $Weigh($wire);
               $leg = [
                  'wire' => strlen($wire),
                  'head' => $head,
                  'frame' => $Parse($wire),
               ];

               // # Room to spare: the head alone is held while unfinished
               TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
               [$Package, $Request, , $State] = $Send($wire);
               $leg['state'] = $State->name;
               $leg['rejected'] = $Package->rejected;
               $leg['decoder'] = $Package->Decoder === null ? null : $Package->Decoder::class;
               $leg['waiting'] = $Request->Body->waiting;
               $leg['declared'] = $Hold($Package);
               $leg['charged'] = $Measure();
               $Close($Package);
               $leg['released'] = $Measure();

               // # An Inbound share of exactly the head: admitted
               TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $head;
               [$Edge, , , $EdgeState] = $Send($wire);
               $leg['edge'] = [$EdgeState->name, $Edge->rejected, $Measure()];
               $Close($Edge);
               $leg['edge_released'] = $Measure();

               // # An Inbound share one byte short of the head: refused
               TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $head - 2;
               [$Short, $ShortRequest, $ShortSocket, $ShortState] = $Send($wire);
               $leg['short'] = [
                  $ShortState->name,
                  $Short->rejected,
                  $Answer($ShortSocket),
                  $Measure(),
                  $ShortRequest->Body->waiting,
                  $Short->Decoder === null,
               ];
               $Close($Short);
               $leg['short_released'] = $Measure();

               $row[$shape] = $leg;
            }

            // :
            return $row;
         });

         // --- (h) A head that arrives alone, then one continuation read the
         //     empty body adopts whole.
         $Probe->rows['h'] = $Run(static function () use (
            $Post, $Close, $Measure, $Weigh, $declared, $slice
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $adopted = 40_000;
            $row = ['start' => $Measure(), 'adopted' => $adopted];

            [$Package, $Request, , , $wire] = $Post('/hhsc3/h', $declared, 0);
            $Decoder = $Package->Decoder;
            $row['installed'] = $Decoder instanceof Decoder_Waiting;
            $row['head'] = $Weigh($wire);
            $row['headed'] = $Measure();
            $row['before'] = strlen($Request->Body->raw);

            // @ One continuation read: the body was empty, so it keeps this
            //   read's string — and the allocation behind it — whole
            $fill = str_repeat('h', $adopted);
            $State = $Decoder instanceof Decoder_Waiting
               ? $Decoder->decode($Package, $fill, $adopted)
               : States::Rejected;
            $row['state'] = $State->name;
            $row['raw'] = strlen($Request->Body->raw);
            $row['rejected'] = $Package->rejected;
            $row['charged'] = $Measure();
            $row['read'] = defined(TCPPackages::class . '::READ')
               ? constant(TCPPackages::class . '::READ')
               : null;

            $Close($Package);
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (i) Chunked: the size line of a half-read chunk rides with the
         //     head, the chunk's data arrives as one read the empty body
         //     adopts whole, then one byte of the chunk's CRLF.
         $Probe->rows['i'] = $Run(static function () use (
            $Send, $Measure, $Weigh, $Hold, $slice
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $half = intdiv($slice, 2);
            $row = ['start' => $Measure(), 'half' => $half];

            // @ Read 1: the head and the chunk-size line together
            $hex = dechex($half);
            $wire = "POST /hhsc3/i HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n{$hex}\r\n";
            [$Package, , , $State] = $Send($wire);
            $Decoder = $Package->Decoder;
            if (! $Decoder instanceof Decoder_Chunked) {
               throw new RuntimeException('The chunked head did not install Decoder_Chunked.');
            }
            $row['parsed'] = $State->name;
            $row['head'] = $Weigh($wire);
            $row['declared'] = $Hold($Package);

            $Body = new ReflectionProperty(Decoder_Chunked::class, 'body');
            $Wire = new ReflectionProperty(Decoder_Chunked::class, 'buffer');

            // @ One decode() call: the decoder's two strings and the ledger
            //   after it
            $Step = static function (string $read) use ($Package, $Decoder, $Body, $Wire, $Measure): array {
               $State = $Decoder->decode($Package, $read, strlen($read));

               // :
               return [
                  'state' => $State->name,
                  'body' => strlen((string) $Body->getValue($Decoder)),
                  'wire' => strlen((string) $Wire->getValue($Decoder)),
                  'charged' => $Measure(),
               ];
            };

            // @ The transport pipelines the rest of read 1 — the size line —
            //   into the decoder the head installed
            $row['first'] = $Step(substr($wire, $Package->consumed));
            // @ Read 2: exactly the chunk's data, half a transport read — the
            //   empty body keeps this read's string, and its allocation, whole
            $row['second'] = $Step(str_repeat('i', $half));
            // @ Read 3: the first byte of the chunk's CRLF — the body is still
            //   the adopted read, the wire is one byte
            $row['third'] = $Step("\r");
            $row['rejected'] = $Package->rejected;
            $row['read'] = defined(TCPPackages::class . '::READ')
               ? constant(TCPPackages::class . '::READ')
               : null;

            $Decoder->disconnect();
            $row['released'] = $Measure();

            // :
            return $row;
         });

         // --- (j) Request::capture(): the deferred snapshot of a body that one
         //     read completed and the body adopted whole.
         $Probe->rows['j'] = $Run(static function () use (
            $Post, $Measure, &$Cleanups, $slice
         ): array {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $adopted = 40_000;
            $row = ['start' => $Measure(), 'adopted' => $adopted];

            // @ The head arrives alone, then one read completes the body: the
            //   body was empty, so it keeps that read's string whole
            [$Package, $Request] = $Post('/hhsc3/j', $adopted, 0);
            $Decoder = $Package->Decoder;
            $row['installed'] = $Decoder instanceof Decoder_Waiting;
            $fill = str_repeat('j', $adopted);
            $State = $Decoder instanceof Decoder_Waiting
               ? $Decoder->decode($Package, $fill, $adopted)
               : States::Rejected;
            $row['finish'] = $State->name;
            $row['raw'] = strlen($Request->Body->raw);
            $row['completed'] = $Measure();

            // @ The snapshot charges its own copy of the body — its own
            //   reservation, with no head
            $Captured = $Request->capture();
            $Cleanups[] = static function () use ($Captured): void {
               $Captured->clean();
            };
            $row['charged'] = $Measure();
            $row['captured_raw'] = strlen($Captured->Body->raw);

            // ! Hold the reservation across clean(): the release must be the
            //   explicit one, never the destructor of a dropped object
            $Reservation = new ReflectionProperty(Request::class, 'Bodies');
            $Holding = $Reservation->getValue($Captured);
            $row['token'] = is_object($Holding)
               ? [$Holding->retained ?? null, $Holding->head ?? null]
               : null;
            $Captured->clean();
            $row['cleaned'] = $Measure();
            unset($Holding);
            $row['read'] = defined(TCPPackages::class . '::READ')
               ? constant(TCPPackages::class . '::READ')
               : null;

            // :
            return $row;
         });
      }
      catch (Throwable $Throwable) {
         $origin = $Throwable::class;
         $Probe->error = "{$origin}: {$Throwable->getMessage()}";
      }
      finally {
         $Release();

         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }

         TCP_Server_CLI::$maxWorkerPendingBytes = $limit;
         Bodies::$maxWorkerBodySize = $workerBodySize;
         Request::$maxBodySize = $bodySize;
         Request::$maxMultipartFieldSize = $fieldSize;
         Decoder_HTTP2::$maxConnectionBodySize = $connectionBodySize;
         Decoder_HTTP2::$maxWorkerBodySize = $HTTP2BodySize;
         Decoder_HTTP2::$streams = $streams;
         Decoder_HTTP2::$replenish = $replenish;
         if ($OldRequest !== null) {
            Server::$Request = $OldRequest;
         }

         // ? Every leg released: the private ledger and the raw ledgers every
         //   HTTP/1 and HTTP/2 body draws on are whole again
         $Probe->residue = TCP_Server_CLI::$pendingBytes - $Probe->base;
         $drained = true;
         foreach ($Statics as [$Static]) {
            $value = $Static->getValue();
            $drained = $drained && match ($Static->getName()) {
               'total' => $value === 0,
               'held' => $value === [0, 0, 0],
               default => true,
            };
         }
         $Probe->drained = $drained;

         // @ Hand the worker statics back exactly as the case found them
         foreach ($Statics as [$Static, $saved]) {
            $Static->setValue(null, $saved);
         }
         $restored = true;
         foreach ($Statics as [$Static, $saved]) {
            $restored = $restored && $Static->getValue() === $saved;
         }
         $Probe->restored = $restored;
      }

      return "GET /hhsc3-bodies-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/hhsc3-bodies-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'HHSC3-BODIES-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe, $held, $declared, $slice, $budget): Generator {
      // ! What one held string of `$held` bytes costs — a whole 2 MiB chunk
      $chunk = Buffers::weigh($held);
      $inbound = intdiv($budget, 2);
      $answer = "HTTP/1.1 503 Service Unavailable\r\n\r\n";
      $step = $Probe->step;
      $rows = $Probe->rows;
      $a = $rows['a'] ?? [];
      $b = $rows['b'] ?? [];
      $c = $rows['c'] ?? [];
      $d = $rows['d'] ?? [];
      $e = $rows['e'] ?? [];
      $f = $rows['f'] ?? [];
      $g = $rows['g'] ?? [];
      $h = $rows['h'] ?? [];
      $i = $rows['i'] ?? [];
      $j = $rows['j'] ?? [];
      $shapes = [
         'length' => Decoder_Waiting::class,
         'chunked' => Decoder_Chunked::class,
         'multipart' => Decoder_Downloading::class,
      ];

      // # Reach: every attack shape got where it aims, with no false refusal
      $reachG = true;
      foreach ($shapes as $shape => $class) {
         $leg = $g[$shape] ?? [];
         $reachG = $reachG
            && ($leg['wire'] ?? PHP_INT_MAX) < 16_384
            && ($leg['state'] ?? null) === States::Complete->name
            && ($leg['rejected'] ?? null) === false
            && ($leg['decoder'] ?? null) === $class
            && ($leg['waiting'] ?? null) === true;
      }
      $reach = [
         'a' => ($a['installed'] ?? null) === true
            && ($a['drip'] ?? null) === States::Incomplete->name
            && ($a['holding'] ?? null) === $held
            && ($a['finish'] ?? null) === States::Complete->name
            && ($a['completed'] ?? null) === $declared
            && ($a['dropped_drip'] ?? null) === States::Incomplete->name
            && ($a['dropped_holding'] ?? null) === $held
            && ($a['rejected'] ?? null) === [false, false],
         'b' => ($b['holders'] ?? null) === [
               [States::Incomplete->name, $held, false],
               [States::Incomplete->name, $held, false],
            ]
            && (($b['fourth'] ?? [])[0] ?? null) === true,
         'c' => ($c['first']['state'] ?? null) === States::Incomplete->name
            && ($c['first']['body'] ?? null) === $step - 16
            && ($c['first']['wire'] ?? null) === 0
            && ($c['second']['state'] ?? null) === States::Incomplete->name
            && ($c['second']['body'] ?? null) === $step + 48
            && ($c['second']['wire'] ?? null) === 2
            && Buffers::weigh($step - 16) < Buffers::weigh($step + 48)
            && ($c['rejected'] ?? null) === false,
         'd' => ($d['installed'] ?? null) === true
            && ($d['states'] ?? null) === [States::Incomplete->name, States::Incomplete->name]
            && ($d['field'] ?? null) === $step
            && ($d['rejected'] ?? null) === false,
         'e' => ($e['control']['state'] ?? null) === States::Incomplete->name
            && ($e['control']['closing'] ?? null) === false
            && ($e['control']['opened'] ?? null) === 3
            && ($e['control']['bodies'] ?? null) === [1 => 0, 3 => 0, 5 => 0]
            && ($e['attack']['state'] ?? null) === States::Incomplete->name
            && ($e['attack']['closing'] ?? null) === false
            && ($e['attack']['rejected'] ?? null) === false
            && ($e['attack']['opened'] ?? null) === 3
            && ($e['attack']['bodies'] ?? null) === [1 => $held, 3 => $held, 5 => $held]
            && ($e['attack']['resets'] ?? null) === array_fill(0, 3, States::Incomplete->name)
            && ($e['attack']['remaining'] ?? null) === 0
            && ($e['control']['remaining'] ?? null) === 0,
         'f' => ($f['finish'] ?? null) === States::Complete->name
            && ($f['raw'] ?? null) === $held
            && ($f['captured_raw'] ?? null) === $held
            && ($f['filler'] ?? null) === true
            && ($f['filler_plus'] ?? null) === true,
         'g' => $reachG,
         'h' => ($h['installed'] ?? null) === true
            && ($h['before'] ?? null) === 0
            && ($h['state'] ?? null) === States::Incomplete->name
            && ($h['raw'] ?? null) === ($h['adopted'] ?? -1)
            && ($h['rejected'] ?? null) === false
            && ($h['adopted'] ?? 0) >= intdiv($slice, 2)
            && Buffers::weigh($h['adopted'] ?? 0) < Buffers::weigh($slice),
         'i' => ($i['half'] ?? null) === intdiv($slice, 2)
            && ($i['parsed'] ?? null) === States::Complete->name
            && ($i['first']['state'] ?? null) === States::Incomplete->name
            && ($i['first']['body'] ?? null) === 0
            && ($i['first']['wire'] ?? null) === 0
            && ($i['second']['state'] ?? null) === States::Incomplete->name
            && ($i['second']['body'] ?? null) === $i['half']
            && ($i['second']['wire'] ?? null) === 0
            && ($i['third']['state'] ?? null) === States::Incomplete->name
            && ($i['third']['body'] ?? null) === $i['half']
            && ($i['third']['wire'] ?? null) === 1
            && ($i['rejected'] ?? null) === false
            && Buffers::weigh($i['half'] + 1) < Buffers::weigh($slice),
         'j' => ($j['installed'] ?? null) === true
            && ($j['finish'] ?? null) === States::Complete->name
            && ($j['raw'] ?? null) === ($j['adopted'] ?? -1)
            && ($j['captured_raw'] ?? null) === ($j['adopted'] ?? -1)
            && ($j['adopted'] ?? 0) >= intdiv($slice, 2)
            && Buffers::weigh($j['adopted'] ?? 0) < Buffers::weigh($slice),
      ];

      // # The ledger, per row: exact, with each kept head re-priced from
      //   the bytes sent and matched by what production declares
      $aHead = $a['head'] ?? -1;
      $bHead = $b['head'] ?? -1;
      $cHead = $c['head'] ?? -1;
      $dHead = $d['head'] ?? -1;
      $hHead = $h['head'] ?? -1;
      $iHead = $i['head'] ?? -1;
      // ! What a string that may be one whole transport read costs
      $read = Buffers::weigh($slice);
      $bHeld = 2 * ($chunk + $bHead);
      $ledgerG = ($g['start'] ?? null) === 0;
      foreach ($shapes as $shape => $class) {
         $leg = $g[$shape] ?? [];
         $head = $leg['head'] ?? -1;
         $ledgerG = $ledgerG
            && $head > 128 * 1024
            && ($leg['frame'] ?? null) === $head
            && ($leg['declared'] ?? null) === $head
            && ($leg['charged'] ?? null) === $head
            && ($leg['released'] ?? null) === 0
            && ($leg['edge'] ?? null) === [States::Complete->name, false, $head]
            && ($leg['edge_released'] ?? null) === 0
            && ($leg['short'] ?? null) === [States::Rejected->name, true, $answer, 0, false, true]
            && ($leg['short_released'] ?? null) === 0;
      }
      $ledger = [
         'a' => ($a['start'] ?? null) === 0
            && $aHead > 0
            && ($a['frame'] ?? null) === $aHead
            && ($a['declared'] ?? null) === $aHead
            && ($a['initial'] ?? null) === Buffers::weigh($slice) + $aHead
            && ($a['charged'] ?? null) === $chunk + $aHead
            && ($a['settled'] ?? null) === 0
            && ($a['dropped'] ?? null) === $chunk + ($a['dropped_head'] ?? -1)
            && ($a['released'] ?? null) === 0,
         'b' => ($b['start'] ?? null) === 0
            && $bHead > 0
            && ($b['heads'] ?? null) === array_fill(0, 4, $bHead)
            && ($b['inbound'] ?? null) === $bHeld + $bHead
            && ($b['held'] ?? null) === $bHeld
            && ($b['third'] ?? null) === [States::Rejected->name, true, $answer]
            && ($b['third_charge'] ?? null) === $bHeld
            && ($b['fourth_head'] ?? null) === $bHeld + $bHead
            && ($b['fourth'] ?? null) === [true, States::Rejected->name, true, $answer]
            && ($b['fourth_charge'] ?? null) === $bHeld
            && ($b['room'] ?? null) === ($b['budget'] ?? -1) - $bHeld
            && ($b['room'] ?? -1) >= intdiv($b['budget'] ?? PHP_INT_MAX, 2)
            && ($b['transport'] ?? null) === [true, false, $b['room'] ?? -1]
            && ($b['released'] ?? null) === 0,
         'c' => ($c['start'] ?? null) === 0
            && $cHead > 0
            && ($c['frame'] ?? null) === $cHead
            && ($c['declared'] ?? null) === $cHead
            && ($c['headed'] ?? null) === $cHead
            && ($c['first']['charged'] ?? null) === ($c['first']['price'] ?? -1) + $cHead
            && ($c['first']['charged'] ?? -1) >= ($c['first']['held'] ?? PHP_INT_MAX) + $cHead
            && ($c['second']['charged'] ?? null) === ($c['second']['price'] ?? -1) + $cHead
            && ($c['second']['charged'] ?? -1) >= ($c['second']['held'] ?? PHP_INT_MAX) + $cHead
            && ($c['released'] ?? null) === 0,
         'd' => ($d['start'] ?? null) === 0
            && $dHead > 0
            && ($d['frame'] ?? null) === $dHead
            && ($d['declared'] ?? null) === $dHead
            && ($d['block'] ?? null) === Buffers::weigh($step)
            && ($d['charged'] ?? null) === ($d['measure'] ?? -1) + $dHead
            && ($d['retained'] ?? null) === ($d['measure'] ?? -1)
            && ($d['charged'] ?? -1) >= Buffers::weigh($step) + $dHead
            && ($d['released'] ?? null) === 0,
         'e' => ($e['start'] ?? null) === 0
            && ($e['delta'] ?? null) === [3 * $chunk, 2 * $chunk, $chunk, 0]
            && ($e['control']['released'] ?? null) === 0
            && ($e['attack']['released'] ?? null) === 0,
         'f' => ($f['start'] ?? null) === 0
            && ($f['completed'] ?? null) === 0
            && ($f['charged'] ?? null) === $chunk
            && ($f['cleaned'] ?? null) === 0
            && ($f['fit'] ?? null) === $inbound
            && ($f['fit_cleaned'] ?? null) === $inbound - $chunk
            && ($f['refused'] ?? null)
               === 'HTTP deferred execution rejected: the worker retained-body budget is exhausted.'
            && ($f['refused_charge'] ?? null) === $inbound - $chunk + 1
            && ($f['released'] ?? null) === 0,
         'g' => $ledgerG,
         'h' => ($h['start'] ?? null) === 0
            && $hHead > 0
            && ($h['read'] ?? null) === $slice
            && ($h['headed'] ?? null) === $hHead
            && ($h['charged'] ?? null) === Buffers::weigh($slice) + $hHead
            && ($h['released'] ?? null) === 0,
         'i' => ($i['start'] ?? null) === 0
            && $iHead > 0
            && ($i['read'] ?? null) === $slice
            && ($i['declared'] ?? null) === $iHead
            && ($i['second']['charged'] ?? null) === 2 * $read + $iHead
            && ($i['third']['charged'] ?? null) === $read + Buffers::weigh(1) + $iHead
            && ($i['third']['charged'] ?? -1) >= $read + $iHead
            && ($i['released'] ?? null) === 0,
         'j' => ($j['start'] ?? null) === 0
            && ($j['read'] ?? null) === $slice
            && ($j['completed'] ?? null) === 0
            && ($j['charged'] ?? null) === $read
            && ($j['token'] ?? null) === [$j['adopted'] ?? -1, 0]
            && ($j['cleaned'] ?? null) === 0,
      ];

      // # Legit load: the attack premise, a private ledger that ends where it
      //   started and is handed back exactly, and every shape reached with no
      //   false refusal
      $legit = $Probe->error === ''
         && $chunk === 2 * 1_048_576
         && $step > 4_096
         && Buffers::weigh($step - 1) < $chunk
         && Buffers::weigh($step) === $chunk
         && $Probe->base === 0
         && $Probe->residue === 0
         && $Probe->drained
         && $Probe->restored
         && ! in_array(false, $reach, true);

      $verdicts = ['legit' => $legit];
      foreach ($ledger as $row => $verdict) {
         $verdicts[$row] = $verdict && $reach[$row] && ! isset($rows[$row]['error']);
      }
      $summary = (string) json_encode(array_map(
         static fn (bool $verdict): string => $verdict ? 'pass' : 'FAIL',
         $verdicts
      ));
      $Evidence = static fn (string $row): string => (string) json_encode($rows[$row] ?? null);
      $reached = (string) json_encode($reach);
      $missing = (string) json_encode($Probe->missing);
      $drained = $Probe->drained ? 'yes' : 'no';
      $restored = $Probe->restored ? 'yes' : 'no';

      yield assert(
         assertion: str_contains($response, 'HHSC3-BODIES-HARNESS-OK'),
         description: 'H-HSC-3 bodies harness did not receive its control response'
      );
      yield assert(
         assertion: $legit,
         description: "H-HSC-3 legit load: every body shape must reach its target with no false refusal, a {$held} B string must weigh a whole chunk and the private ledger must end where it started and be handed back exactly; rows={$summary} error={$Probe->error} base={$Probe->base} residue={$Probe->residue} drained={$drained} restored={$restored} inherited={$Probe->inherited} missing={$missing} step={$step} chunk={$chunk} reach={$reached}"
      );
      yield assert(
         assertion: $verdicts['a'],
         description: "H-HSC-3 (a) an unfinished Content-Length body must charge exactly Buffers::weigh() of its string ({$chunk} for {$held} B) plus its parsed head, and return both on completion and on disconnect; rows={$summary} evidence={$Evidence('a')}"
      );
      yield assert(
         assertion: $verdicts['b'],
         description: "H-HSC-3 (b) when two unfinished bodies and their heads leave room for one head, a third peer's first slice and a fourth peer's first continuation must be answered 503 and pending output must keep the rest; rows={$summary} evidence={$Evidence('b')}"
      );
      yield assert(
         assertion: $verdicts['c'],
         description: "H-HSC-3 (c) a chunked body crossing the 2 MiB chunk step inside one decode() must be charged weigh(body + wire) + weigh(wire) plus its parsed head; rows={$summary} evidence={$Evidence('c')}"
      );
      yield assert(
         assertion: $verdicts['d'],
         description: "H-HSC-3 (d) an unfinished multipart text field of {$step} B must be charged its whole 2 MiB chunk plus its parsed head; rows={$summary} evidence={$Evidence('d')}"
      );
      yield assert(
         assertion: $verdicts['e'],
         description: "H-HSC-3 (e) three unfinished HTTP/2 streams must charge weigh() of each body string, and each RST_STREAM must return exactly that stream's weight; rows={$summary} evidence={$Evidence('e')}"
      );
      yield assert(
         assertion: $verdicts['f'],
         description: "H-HSC-3 (f) a deferred Request::capture() snapshot must charge weigh() of its body in the Inbound share, release it on clean() and be refused when that share is full; rows={$summary} evidence={$Evidence('f')}"
      );
      yield assert(
         assertion: $verdicts['g'],
         description: "H-HSC-3 (g) a parsed head of 1,300 fields kept by an unfinished Content-Length, chunked or multipart body must charge exactly Request\\Frame::weigh() of that head (> 128 KiB), return it on disconnect, and be answered 503 with the ledger unchanged when the Inbound share is one byte short of it; rows={$summary} evidence={$Evidence('g')}"
      );
      yield assert(
         assertion: $verdicts['h'],
         description: "H-HSC-3 (h) a continuation read an empty body adopts whole must be charged as the whole transport read (weigh({$slice})) plus the head, not as its own length; rows={$summary} evidence={$Evidence('h')}"
      );
      yield assert(
         assertion: $verdicts['i'],
         description: "H-HSC-3 (i) a chunked body that adopted a half-read chunk whole must stay charged as the whole transport read (weigh({$slice})) plus its wire and head while one byte of the chunk's CRLF waits, not as its own length; rows={$summary} evidence={$Evidence('i')}"
      );
      yield assert(
         assertion: $verdicts['j'],
         description: "H-HSC-3 (j) a deferred Request::capture() snapshot of a completed body of half a transport read or more must charge weigh({$slice}) in its own reservation, not weigh() of its length, and clean() must return it; rows={$summary} evidence={$Evidence('j')}"
      );
   },
);
