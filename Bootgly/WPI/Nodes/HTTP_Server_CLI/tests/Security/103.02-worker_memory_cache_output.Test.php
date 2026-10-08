<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use const Bootgly\WPI;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as HTTPServer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Bodies as StreamBodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('HHSC3OutputStream', false)) {
   /** Deterministic writer: each stream accepts a byte allowance, then writes nothing. */
   class HHSC3OutputStream
   {
      // * Data
      /** @var array<string,int> Bytes each stream still accepts (-1: unlimited). */
      public static array $allowances = [];
      /** @var array<string,string> */
      public static array $written = [];
      /** @var array<string,int> */
      public static array $calls = [];
      public mixed $context;

      // * Metadata
      private string $key = '';


      public static function reset (): void
      {
         self::$allowances = [];
         self::$written = [];
         self::$calls = [];
      }

      public static function limit (string $key, int $bytes): void
      {
         self::$allowances[$key] = $bytes;
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path
      ): bool
      {
         $this->key = (string) (parse_url($path, PHP_URL_HOST) ?: 'default');
         self::$allowances[$this->key] ??= -1;
         self::$written[$this->key] ??= '';
         self::$calls[$this->key] ??= 0;

         return true;
      }

      public function stream_write (string $data): int
      {
         // !
         self::$calls[$this->key]++;
         $allowance = self::$allowances[$this->key];
         $sent = $allowance < 0 ? strlen($data) : min($allowance, strlen($data));

         // @
         if ($allowance >= 0) {
            self::$allowances[$this->key] = $allowance - $sent;
         }
         self::$written[$this->key] .= substr($data, 0, $sent);

         // :
         return $sent;
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('HHSC3OutputConnection', false)) {
   class HHSC3OutputConnection extends Connection
   {
      // * Metadata
      public bool $closed = false;


      /** @param resource $Socket */
      public function __construct (mixed &$Socket, int $port)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
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

      // ! No event loop in this process: close() only marks the status
      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}

if (! class_exists('HHSC3OutputPackage', false)) {
   /** Exposes the Package footprint and its full release to the probe. */
   class HHSC3OutputPackage extends TCPPackages
   {
      public function inspect (): int
      {
         return $this->measure();
      }

      public function purge (): void
      {
         $this->release();
      }

      /** Keep the unconsumed tail of `$input` as receive carry (production retain()). */
      public function hold (string $input, int $offset): void
      {
         $this->retain($input, $offset, strlen($input));
      }
   }
}

/**
 * H-HSC-3 — one worker memory budget: the route cache and pending output.
 *
 * Every retention owner charges the allocator footprint of what it holds
 * (`Buffers::weigh()` of each held string, summed) to one worker budget,
 * `TCP_Server_CLI::$maxWorkerPendingBytes`. A ledger of raw lengths admits
 * about twice what fits in memory: a 1 MiB string spends a whole 2 MiB chunk.
 *
 * (g) The L1 route cache holds its wires in the Resident share (a quarter of
 *     the budget): a store is charged at weigh(), FIFO-evicts what no longer
 *     fits, is skipped when nothing evictable is left, and expiry/flush give
 *     the footprint back exactly.
 * (h) TCP Packages charge deferred suffixes, receive carry, coalesced and
 *     queued responses and multipart pads at weigh() of each string, a new
 *     string replacing the one it supersedes; a per-connection cap (pinned
 *     at 4 MiB by the row) compares that footprint, so a 4,194,304-byte
 *     suffix (4,198,400) aborts.
 * (i) HTTP/2 stream tails (backlog + in-memory chunks), the encoder's parked
 *     body and pad chunks, and every decoder receive hold (short preface,
 *     header fragments, partial frame, feed()) are charged at weigh() of each
 *     string.
 * (j) Legit: under the DECLARED default per-connection cap, two 1,153,434-byte
 *     responses parked on two streams one HTTP/2 connection really opened
 *     (granting no send window) are both admitted — footprint charging must
 *     not refuse what a raw-length ledger admitted.
 *
 * The legit-load rows prove the same fixtures still admit, park and drain
 * ordinary traffic. Every probe runs in this runner process against the
 * production classes, on a private worker ledger: earlier cases may have left
 * reservations, body totals or cache entries behind, so the case saves every
 * worker static it moves, starts each from zero in a new ledger generation,
 * and restores them exactly at the end.
 */
$checks = [
   'legit' => [],
   'g' => [],
   'h' => [],
   'i' => [],
   'j' => [],
   'ledger' => [],
];
$evidence = [];

return new Test(
   description: 'H-HSC-3: route cache and pending output are charged at allocator footprint to one worker budget',

   request: static function () use (&$checks, &$evidence): string {
      // ! Writer double
      $scheme = 'bootgly-hhsc3-output';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, HHSC3OutputStream::class);
      }
      HHSC3OutputStream::reset();

      // ! Every static this case changes
      $WPI = WPI;
      $OldRequest = $WPI->Request;
      $OldServerRequest = isset(HTTPServer::$Request) ? HTTPServer::$Request : null;
      $OldEvent = isset(TCPServer::$Event) ? TCPServer::$Event : null;
      $savedBudget = TCPServer::$maxWorkerPendingBytes;
      $savedConnectionCap = TCPServer::$maxPendingBytes;
      $savedPending = TCPServer::$pendingBytes;
      $savedEntries = Cache::$entries;
      $savedBytes = Cache::$bytes;
      $savedURIs = Cache::$URIs;
      $savedMaxBytes = Cache::$maxBytes;
      $savedGeneration = Cache::$generation;
      $savedMultiparts = Request::$multiparts;

      // ! A private worker ledger: earlier cases in this process may have left
      //   reservations, body totals or cache entries behind. Save each private
      //   worker static (where this build has it) and start it from zero; the
      //   epoch moves to a new generation, as reset() does, so a token reserved
      //   before this case holds nothing here and releases nothing here.
      /** @var array<string,array{0:ReflectionProperty,1:mixed}> $Ledger */
      $Ledger = [];
      foreach ([
         'Buffers total' => [Buffers::class, 'total'],
         'Buffers held' => [Buffers::class, 'held'],
         'Buffers epoch' => [Buffers::class, 'epoch'],
         'Bodies total' => [Bodies::class, 'total'],
         'HTTP/2 Bodies total' => [StreamBodies::class, 'total'],
         'Cache token' => [Cache::class, 'Buffers'],
      ] as $label => [$class, $name]) {
         if (property_exists($class, $name)) {
            $Property = new ReflectionProperty($class, $name);
            $Ledger[$label] = [$Property, $Property->getValue()];
         }
      }
      foreach ($Ledger as $label => [$Property, $saved]) {
         $Property->setValue(null, match ($label) {
            'Buffers held' => [0, 0, 0],
            'Buffers epoch' => $saved + 1,
            'Cache token' => null,
            default => 0,
         });
      }
      TCPServer::$pendingBytes = 0;
      Cache::$entries = [];
      Cache::$bytes = 0;
      Cache::$URIs = [];
      Cache::$generation = 0;
      $base = TCPServer::$pendingBytes;
      $evidence['saved'] = [
         'pending' => $savedPending,
         'entries' => count($savedEntries),
         'ledger' => array_map(
            static fn (array $Entry): mixed => is_object($Entry[1]) ? $Entry[1]::class : $Entry[1],
            $Ledger
         ),
      ];

      // ! One registration double: these fixtures audit byte accounting, not
      //   descriptor admission (Select cannot watch userland streams)
      TCPServer::$Event = new class extends Select {
         public function __construct () {}

         public function add ($Socket, int $flag, mixed $payload): bool
         {
            return true;
         }

         public function del ($Socket, int $flag): bool
         {
            return true;
         }

         // ! Timers never fire in this process: arm nothing
         public function defer (float|int $deadline, Closure $Callback): int
         {
            return 1;
         }

         public function cancel (int $ID): bool
         {
            return true;
         }
      };

      $Check = static function (string $row, string $label, bool $passed) use (&$checks): void {
         $checks[$row][$label] = $passed;
      };
      /** Run one scenario; a throw fails every row it feeds instead of the case. */
      $Guard = static function (array $rows, string $scenario, Closure $Scenario) use (&$checks, &$evidence): void {
         try {
            $Scenario();
            $passed = true;
         }
         catch (Throwable $Throwable) {
            $passed = false;
            $class = $Throwable::class;
            $file = basename($Throwable->getFile());
            $evidence["{$scenario} error"] = "{$class}: {$Throwable->getMessage()} at {$file}:{$Throwable->getLine()}";
         }

         foreach ($rows as $row) {
            $checks[$row]["{$scenario} completed"] = $passed;
         }
      };
      /** A final 200 HTTP/1.1 wire of exactly `$total` bytes with a canonical Date. */
      $Wire = static function (int $total, string $fill): string {
         $head = "HTTP/1.1 200 OK\r\nDate: Thu, 08 Oct 2026 12:00:00 GMT\r\nContent-Type: application/octet-stream\r\nContent-Length: ";
         $digits = 1;
         while (strlen((string) ($total - strlen($head) - 4 - $digits)) !== $digits) {
            $digits++;
         }
         $length = $total - strlen($head) - 4 - $digits;
         $body = str_repeat($fill, $length);
         $wire = "{$head}{$length}\r\n\r\n{$body}";
         if (strlen($wire) !== $total) {
            throw new RuntimeException("The {$total}-byte cache wire fixture came out wrong.");
         }

         return $wire;
      };
      /** Whether a fetched wire is the stored one but for a canonical 29-byte Date value. */
      $Compare = static function (null|string $fetched, string $wire): bool {
         if ($fetched === null || strlen($fetched) !== strlen($wire)) {
            return false;
         }
         $offset = (int) strpos($wire, "\r\nDate: ") + 8;
         $date = substr($fetched, $offset, 29);

         return substr($fetched, 0, $offset) === substr($wire, 0, $offset)
            && substr($fetched, $offset + 29) === substr($wire, $offset + 29)
            && preg_match(
               '/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} [A-Z][a-z]{2} [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/',
               $date
            ) === 1;
      };
      /** Frame census of an HTTP/2 wire: frame types, RST_STREAM codes, GOAWAYs, unframed tail. */
      $Walk = static function (string $wire): array {
         // !
         $types = [];
         $resets = [];
         $goaways = 0;
         $offset = 0;
         $length = strlen($wire);

         // @@
         while ($length - $offset >= 9) {
            $size = (ord($wire[$offset]) << 16) | (ord($wire[$offset + 1]) << 8) | ord($wire[$offset + 2]);
            $type = ord($wire[$offset + 3]);
            if ($length - $offset - 9 < $size) {
               break;
            }

            $types[] = $type;
            if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               $resets[] = (int) unpack('N', $wire, $offset + 9)[1];
            }
            else if ($type === HTTP2::FRAME_GOAWAY) {
               $goaways++;
            }
            $offset += 9 + $size;
         }

         // :
         return ['types' => $types, 'resets' => $resets, 'goaways' => $goaways, 'tail' => $length - $offset];
      };
      /** @var array<int,resource> $Sockets */
      $Sockets = [];
      /** @var array<int,HHSC3OutputPackage> $Packages */
      $Packages = [];
      /** @return array{0:resource,1:HHSC3OutputConnection,2:HHSC3OutputPackage} */
      $Open = static function (string $key) use ($scheme, &$Sockets, &$Packages): array {
         $Socket = fopen("{$scheme}://{$key}/output", 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException("Could not open the {$key} writer.");
         }
         $Sockets[] = $Socket;
         $Connection = new HHSC3OutputConnection($Socket, 18_300 + count($Sockets));
         $Package = new HHSC3OutputPackage($Connection);
         $Packages[] = $Package;

         return [$Socket, $Connection, $Package];
      };

      try {
         // # (g) Route cache — Resident share, FIFO eviction, skip, expiry, flush
         $Guard(['g', 'legit'], 'route cache', static function () use (
            $Check, $Wire, $Compare, $base, &$evidence,
         ): void {
            // !
            $large = 1_048_538;
            $weight = Buffers::weigh($large);
            $Pressure = new Buffers;

            try {
               Cache::flush();
               Cache::$maxBytes = 64 * 1024 * 1024;
               TCPServer::$maxWorkerPendingBytes = 64 * 1024 * 1024;

               // @ One stored wire is charged at its allocator footprint
               $wire = $Wire($large, 'S');
               Cache::store('hhsc3-single', $wire, 60, '/hhsc3/single');
               $entry = Cache::$entries['hhsc3-single'] ?? null;
               $offset = (int) strpos($wire, "\r\nDate: ") + 8;
               $evidence['g single delta'] = TCPServer::$pendingBytes - $base;
               $Check('legit', 'a 1,048,538-byte final 200 wire with a canonical Date is stored', is_array($entry)
                  && $entry[0] === $wire
                  && $entry[2] === $offset
                  && Cache::$bytes === $large);
               $Check('g', 'weigh(1,048,538) is one whole 2 MiB chunk and the wire is within WIRE_LIMIT', $weight === 2_097_152
                  && $large <= Cache::WIRE_LIMIT);
               $Check('g', 'storing it moves pendingBytes by exactly weigh(1,048,538)', TCPServer::$pendingBytes - $base === $weight);
               Cache::flush();
               $Check('g', 'flush() returns the single store to the baseline', TCPServer::$pendingBytes === $base);

               // @ The Resident share of an 8 MiB budget is 2 MiB: one wire fits
               TCPServer::$maxWorkerPendingBytes = 8 * 1024 * 1024;
               $wires = [];
               foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $index => $fill) {
                  $key = "hhsc3-fifo-{$index}";
                  $wires[$key] = $Wire($large, $fill);
                  Cache::store($key, $wires[$key], 60, "/hhsc3/fifo/{$index}");
               }
               $keys = array_keys(Cache::$entries);
               $evidence['g fifo keys'] = $keys;
               $evidence['g fifo delta'] = TCPServer::$pendingBytes - $base;
               $Check('g', '6 large stores under an 8 MiB budget leave exactly the newest entry', $keys === ['hhsc3-fifo-5']
                  && Cache::$bytes === $large);
               $Check('g', 'the kept entry stays charged at weigh(1,048,538)', TCPServer::$pendingBytes - $base === $weight);
               $Check('g', 'an evicted key fetch()es null', Cache::fetch('hhsc3-fifo-0') === null
                  && Cache::fetch('hhsc3-fifo-4') === null);
               $Check('g', 'the live key fetch()es its wire byte-identical except the Date value', $Compare(
                  Cache::fetch('hhsc3-fifo-5'),
                  $wires['hhsc3-fifo-5']
               ));
               Cache::flush();

               // @ A Transport token holding L - 1 MiB leaves no room for a 2 MiB wire
               $held = TCPServer::$maxWorkerPendingBytes - 1024 * 1024;
               $reserved = $Pressure->reserve($held);
               $thrown = null;
               try {
                  Cache::store('hhsc3-skip', $Wire($large, 'K'), 60, '/hhsc3/skip');
               }
               catch (Throwable $Throwable) {
                  $thrown = $Throwable->getMessage();
               }
               $evidence['g skip'] = [
                  'reserved' => $reserved,
                  'thrown' => $thrown,
                  'stored' => isset(Cache::$entries['hhsc3-skip']),
                  'delta' => TCPServer::$pendingBytes - $base,
               ];
               $Check('g', 'a Transport token holds L - 1 MiB', $reserved && $Pressure->retained === $held);
               $Check('g', 'a store that cannot fit is skipped (entry absent) and nothing throws', $thrown === null
                  && isset(Cache::$entries['hhsc3-skip']) === false
                  && isset(Cache::$URIs['/hhsc3/skip']) === false
                  && Cache::$bytes === 0
                  && TCPServer::$pendingBytes - $base === $held);
               $small = $Wire(4096, 'M');
               Cache::store('hhsc3-small', $small, 60, '/hhsc3/small');
               $Check('legit', 'a small wire still stores under transport pressure', (Cache::$entries['hhsc3-small'][0] ?? null) === $small);
               $Check('g', 'that small wire is charged at weigh(4096)', TCPServer::$pendingBytes - $base === $held + Buffers::weigh(4096));
               $Pressure->release();
               Cache::flush();

               // @ A wire that could not fit even in an empty cache is skipped without
               //   evicting the entries that do fit
               $kept = [
                  'hhsc3-keep-0' => $Wire(4096, 'P'),
                  'hhsc3-keep-1' => $Wire(4096, 'Q'),
               ];
               foreach ($kept as $key => $keep) {
                  Cache::store($key, $keep, 60, "/hhsc3/{$key}");
               }
               $reserved = $Pressure->reserve($held);
               Cache::store('hhsc3-wipe', $Wire($large, 'W'), 60, '/hhsc3/wipe');
               $evidence['g no wipe'] = [
                  'reserved' => $reserved,
                  'keys' => array_keys(Cache::$entries),
                  'delta' => TCPServer::$pendingBytes - $base,
               ];
               $Check('g', 'a store that cannot fit even in an empty cache evicts nothing', $reserved
                  && array_keys(Cache::$entries) === array_keys($kept)
                  && TCPServer::$pendingBytes - $base === $held + (2 * Buffers::weigh(4096)));
               $Pressure->release();
               Cache::flush();

               // @ An expired entry dropped by fetch() gives back exactly its weight
               TCPServer::$maxWorkerPendingBytes = 64 * 1024 * 1024;
               $medium = 300_000;
               Cache::store('hhsc3-expired', $Wire($large, 'X'), 60, '/hhsc3/expired');
               Cache::store('hhsc3-kept', $Wire($medium, 'Y'), 60, '/hhsc3/kept');
               $both = TCPServer::$pendingBytes - $base;
               Cache::$entries['hhsc3-expired'][1] = time() - 1;
               $dropped = Cache::fetch('hhsc3-expired');
               $after = TCPServer::$pendingBytes - $base;
               $evidence['g expiry'] = ['both' => $both, 'after' => $after];
               $Check('legit', 'an expired entry is dropped and a live one kept', $dropped === null
                  && isset(Cache::$entries['hhsc3-expired']) === false
                  && isset(Cache::$entries['hhsc3-kept'])
                  && Cache::$bytes === $medium);
               $Check('g', 'two stored wires are charged at the sum of their weigh()', $both === $weight + Buffers::weigh($medium));
               $Check('g', 'the expired entry dropped by fetch() shrinks the ledger by its weight', $both - $after === $weight
                  && $after === Buffers::weigh($medium));
               Cache::flush();
               $Check('g', 'flush() returns the ledger exactly to the baseline', Cache::$entries === []
                  && Cache::$bytes === 0
                  && TCPServer::$pendingBytes === $base);
            }
            finally {
               $Pressure->release();
               Cache::flush();
            }
         });

         // # (h) TCP Packages output — deferred suffix, queued responses, pads, cap
         $Guard(['h', 'legit'], 'pending output', static function () use (
            $Check, $Open, $WPI, $base, &$evidence,
         ): void {
            // !
            TCPServer::$maxPendingBytes = 4 * 1024 * 1024;
            TCPServer::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $fixture = file_get_contents(BOOTGLY_PROJECT->path . 'statics/alphanumeric.txt');
            if (! is_string($fixture) || strlen($fixture) !== 62) {
               throw new RuntimeException('Could not read the 62-byte upload fixture.');
            }
            /** A public Range upload encoded through the production HTTP/1 encoder. */
            $Multipart = static function (HHSC3OutputPackage $Package) use ($WPI): array {
               $Request = new Request;
               $Request->method = 'GET';
               $Request->protocol = 'HTTP/1.1';
               $Request->Header->adopt(['range' => 'bytes=1-2,4-5']);
               $WPI->Request = $Request;

               $Response = new Response;
               $Response->reset($Package, $Request);
               $Response->upload('statics/alphanumeric.txt', close: false);
               $length = null;
               $head = $Response->encode($Package, $length);
               if (! str_starts_with($head, 'HTTP/1.1 206 ') || $length !== strlen($head)) {
                  throw new RuntimeException('The multipart Range response did not encode.');
               }

               return [$head, $length];
            };
            /** Pad footprint (weigh() of each string) and expected body of upload records. */
            $Weigh = static function (array $uploads) use ($fixture): array {
               $footprint = 0;
               $raw = 0;
               $strings = 0;
               $body = '';
               foreach ($uploads as $upload) {
                  $pads = $upload['pads'] ?? [];
                  foreach ($pads as $pad) {
                     foreach (['prepend', 'append'] as $side) {
                        $value = $pad[$side] ?? null;
                        if (is_string($value)) {
                           $footprint += Buffers::weigh(strlen($value));
                           $raw += strlen($value);
                           $strings++;
                        }
                     }
                  }
                  foreach ($upload['parts'] ?? [] as $key => $part) {
                     $prepend = $pads[$key]['prepend'] ?? '';
                     $append = $pads[$key]['append'] ?? '';
                     $range = substr($fixture, $part['offset'], $part['length']);
                     $body .= "{$prepend}{$range}{$append}";
                  }
               }

               return ['footprint' => $footprint, 'raw' => $raw, 'strings' => $strings, 'body' => $body];
            };

            // @ A deferred unsent suffix of 1,056,090 bytes
            [$Socket, $Connection, $Package] = $Open('h-stall');
            $suffix = 1_056_090;
            $sent = str_repeat('s', 4096);
            $unsent = str_repeat('u', $suffix);
            $first = "{$sent}{$unsent}";
            HHSC3OutputStream::limit('h-stall', 4096);
            $result = $Package->writing($Socket, buffer: $first);
            $retained = $Package->Buffers->retained;
            $evidence['h suffix'] = ['result' => $result, 'retained' => $retained, 'measure' => $Package->inspect()];
            $Check('legit', 'a short write defers its whole unsent suffix', $result === true
               && $Connection->closed === false
               && strlen($Package->pendingBuffer) - $Package->pendingOffset === $suffix
               && HHSC3OutputStream::$written['h-stall'] === $sent);
            $Check('h', 'weigh(1,056,090) is one whole 2 MiB chunk', Buffers::weigh($suffix) === 2_097_152);
            $Check('h', 'the deferred suffix makes Package->Buffers->retained == weigh(1,056,090)', $retained === Buffers::weigh($suffix)
               && $Package->inspect() === $retained
               && TCPServer::$pendingBytes - $base === $retained);

            // @ A multipart response staged behind it: pads at weigh() of each string
            [$head, $headLength] = $Multipart($Package);
            $staged = $Weigh($Package->stagedUploading);
            $measured = $Package->inspect() - $retained;
            $result = $Package->writing($Socket, length: $headLength, buffer: $head);
            $queued = $Package->Buffers->retained;
            $evidence['h staged'] = [
               'strings' => $staged['strings'],
               'raw' => $staged['raw'],
               'footprint' => $staged['footprint'],
               'measured' => $measured,
               'queued_growth' => $queued - $retained,
               'head' => strlen($head),
            ];
            $Check('legit', 'a multipart response queues behind the stalled output', $result === true
               && $Connection->closed === false
               && count($Package->pendingResponses) === 1
               && $Package->stagedUploading === []);
            $Check('h', 'staged multipart pads are measured at weigh() of each prepend/append string', $staged['strings'] >= 3
               && $measured === $staged['footprint']
               && $staged['footprint'] !== $staged['raw']);
            $Check('h', 'queueing it adds weigh(strlen(head)) + weigh() of each pad', $queued - $retained === Buffers::weigh(strlen($head)) + $staged['footprint']
               && $Package->inspect() === $queued);

            // @ A plain response queued behind them adds weigh(strlen(buffer))
            $plain = str_repeat('p', 5_000);
            $result = $Package->writing($Socket, buffer: $plain);
            $after = $Package->Buffers->retained;
            $evidence['h plain'] = ['result' => $result, 'growth' => $after - $queued];
            $Check('legit', 'a plain response queues behind them', $result === true
               && count($Package->pendingResponses) === 2);
            $Check('h', 'a queued response adds exactly weigh(strlen(buffer))', $after - $queued === Buffers::weigh(strlen($plain))
               && $Package->inspect() === $after
               && TCPServer::$pendingBytes - $base === $after);

            // @ Partial progress re-defers the rest beside the queued strings
            HHSC3OutputStream::limit('h-stall', 2048);
            $result = $Package->writing($Socket, buffer: '');
            $rest = $suffix - 2048;
            $resumed = $Package->Buffers->retained;
            $evidence['h resumed'] = ['result' => $result, 'retained' => $resumed];
            $Check('legit', 'partial progress keeps the remaining suffix pending', $result === true
               && strlen($Package->pendingBuffer) - $Package->pendingOffset === $rest
               && HHSC3OutputStream::$written['h-stall'] === substr($first, 0, 4096 + 2048));
            $Check('h', 're-deferral charges weigh(rest) beside each queued string, never weigh() of their sum', $resumed === Buffers::weigh($rest)
               + Buffers::weigh(strlen($head))
               + $staged['footprint']
               + Buffers::weigh(strlen($plain))
               && $Package->inspect() === $resumed);

            // @ Unblocked, everything drains whole, in order, and the token empties
            HHSC3OutputStream::limit('h-stall', -1);
            for (
               $round = 0;
               $round < 16
                  && $Connection->closed === false
                  && (
                     $Package->pendingBuffer !== ''
                     || $Package->pendingResponses !== []
                     || $Package->uploading !== []
                  );
               $round++
            ) {
               $Package->writing($Socket, buffer: '');
            }
            $expected = "{$first}{$head}{$staged['body']}{$plain}";
            $Check('legit', 'the stalled output drains whole and in order', $Connection->closed === false
               && HHSC3OutputStream::$written['h-stall'] === $expected
               && $Package->Buffers->retained === 0);
            $Package->purge();

            // @ An active multipart queue: its pads are charged before the head defers
            [$Socket, $Connection, $Package] = $Open('h-pads');
            HHSC3OutputStream::limit('h-pads', 0);
            [$head, $headLength] = $Multipart($Package);
            $active = $Weigh($Package->uploading);
            $measured = $Package->inspect();
            $result = $Package->writing($Socket, length: $headLength, buffer: $head);
            $evidence['h active'] = [
               'footprint' => $active['footprint'],
               'measured' => $measured,
               'retained' => $Package->Buffers->retained,
            ];
            $Check('legit', 'a stalled multipart head defers with its file queue', $result === true
               && $Connection->closed === false
               && $Package->pendingBuffer === $head
               && $Package->uploading !== []);
            $Check('h', 'active multipart pads are charged at weigh() of each string', $active['strings'] >= 3
               && $measured === $active['footprint']
               && $Package->Buffers->retained === Buffers::weigh(strlen($head)) + $active['footprint']);
            $Package->purge();

            // @ Coalescing: a plain response joined to the pending suffix is
            //   projected as ONE joined string, at the exact per-connection cap
            $joined = 100_000 + 5_000;
            $Join = static function (string $key, int $cap) use ($Open): array {
               [$Socket, $Connection, $Package] = $Open($key);
               HHSC3OutputStream::limit($key, 0);
               TCPServer::$maxPendingBytes = $cap;
               $first = $Package->writing($Socket, buffer: str_repeat('j', 100_000));
               $deferred = $Package->Buffers->retained;
               $second = $Package->writing($Socket, buffer: str_repeat('k', 5_000));
               $outcome = [
                  'first' => $first,
                  'deferred' => $deferred,
                  'second' => $second,
                  'closed' => $Connection->closed,
                  'pending' => strlen($Package->pendingBuffer) - $Package->pendingOffset,
                  'retained' => $Package->Buffers->retained,
                  'measure' => $Package->inspect(),
               ];
               $Package->purge();
               TCPServer::$maxPendingBytes = 4 * 1024 * 1024;

               return $outcome;
            };
            $exact = $Join('h-join', Buffers::weigh($joined));
            $tight = $Join('h-join-tight', Buffers::weigh($joined) - 1);
            $evidence['h coalesce'] = ['exact' => $exact, 'tight' => $tight];
            $Check('legit', 'a response coalesced into a pending suffix is admitted at its exact footprint cap', $exact['first'] === true
               && $exact['second'] === true
               && $exact['closed'] === false
               && $exact['pending'] === $joined);
            $Check('h', 'coalescing charges weigh() of the joined string, replacing the old pending one', $exact['deferred'] === Buffers::weigh(100_000)
               && $exact['retained'] === Buffers::weigh($joined)
               && $exact['measure'] === $exact['retained']
               && Buffers::weigh($joined) !== Buffers::weigh(100_000) + Buffers::weigh(5_000));
            $Check('h', 'one byte under that footprint the coalesced response is refused and aborts', $tight['first'] === true
               && $tight['deferred'] === Buffers::weigh(100_000)
               && $tight['second'] === false
               && $tight['closed'] === true
               && $tight['retained'] === 0
               && TCPServer::$pendingBytes === $base);

            // @ Receive carry: retain() replaces the old carry in the footprint
            [$Socket, $Connection, $Package] = $Open('h-carry');
            HHSC3OutputStream::limit('h-carry', 0);
            $Package->writing($Socket, buffer: str_repeat('w', 100_000));
            $pending = $Package->Buffers->retained;
            $Package->hold(str_repeat('r', 3_000), 0);
            $small = $Package->Buffers->retained;
            $input = str_repeat('R', 6_000);
            $Package->hold($input, 1_000);
            $grown = $Package->Buffers->retained;
            $Package->hold($input, strlen($input));
            $consumed = $Package->Buffers->retained;
            $evidence['h carry'] = [
               'pending' => $pending,
               'small' => $small,
               'grown' => $grown,
               'consumed' => $consumed,
               'closed' => $Connection->closed,
            ];
            $Check('legit', 'a receive carry is kept beside pending output', $Connection->closed === false
               && $Package->carry === ''
               && strlen($Package->pendingBuffer) === 100_000);
            $Check('h', 'the carry is charged at weigh() beside the pending suffix and replaced, never summed', $pending === Buffers::weigh(100_000)
               && $small === $pending + Buffers::weigh(3_000)
               && $grown === $pending + Buffers::weigh(5_000)
               && $consumed === $pending);
            $Package->purge();

            // @ A suffix whose footprint is exactly the 4 MiB cap is admitted
            [$Socket, $Connection, $Package] = $Open('h-fit');
            HHSC3OutputStream::limit('h-fit', 0);
            $fit = 4_194_272;
            $payload = str_repeat('f', $fit);
            $result = $Package->writing($Socket, buffer: $payload);
            $evidence['h fit'] = ['result' => $result, 'retained' => $Package->Buffers->retained];
            $Check('legit', 'a suffix within the 4 MiB per-connection cap is admitted', $result === true
               && $Connection->closed === false
               && strlen($Package->pendingBuffer) === $fit);
            $Check('h', 'that suffix is charged its whole footprint, exactly the cap', Buffers::weigh($fit) === TCPServer::$maxPendingBytes
               && $Package->Buffers->retained === Buffers::weigh($fit));
            HHSC3OutputStream::limit('h-fit', -1);
            $Package->writing($Socket, buffer: '');
            $Check('legit', 'the admitted suffix drains whole', HHSC3OutputStream::$written['h-fit'] === $payload
               && $Package->pendingBuffer === ''
               && $Package->Buffers->retained === 0);
            $Package->purge();

            // @ A 4,194,304-byte suffix (footprint 4,198,400) is refused and aborts
            [$Socket, $Connection, $Package] = $Open('h-cap');
            HHSC3OutputStream::limit('h-cap', 0);
            $oversize = 4 * 1024 * 1024;
            $result = $Package->writing($Socket, buffer: str_repeat('z', $oversize));
            $evidence['h cap'] = [
               'result' => $result,
               'closed' => $Connection->closed,
               'pending' => strlen($Package->pendingBuffer),
               'retained' => $Package->Buffers->retained,
               'calls' => HHSC3OutputStream::$calls['h-cap'] ?? 0,
            ];
            $Check('h', 'weigh(4,194,304) is 4,198,400, past the 4,194,304-byte per-connection cap', Buffers::weigh($oversize) === 4_198_400
               && TCPServer::$maxPendingBytes === 4_194_304);
            $Check('h', 'the 4,194,304-byte suffix is refused and the connection aborted', (HHSC3OutputStream::$calls['h-cap'] ?? 0) >= 1
               && $result === false
               && $Connection->closed === true
               && $Package->pendingBuffer === ''
               && $Package->Buffers->retained === 0
               && TCPServer::$pendingBytes === $base);
            $Package->purge();
         });

         // # (i) HTTP/2 — Stream tail, encoder parked body, decoder carry
         $Guard(['i', 'legit'], 'HTTP/2 tails', static function () use (
            $Check, $Open, $WPI, $base, &$evidence,
         ): void {
            // !
            TCPServer::$maxPendingBytes = 4 * 1024 * 1024;
            TCPServer::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            /** @var array<int,Decoder_HTTP2> $Decoders */
            $Decoders = [];

            try {
               // @ Stream::measure(): the unread backlog plus each unconsumed in-memory chunk
               $Stream = new Stream(1, 65_535, 65_535, new StreamBodies(1024, 1024));
               $Stream->backlog = str_repeat('b', 300_000);
               $Stream->chunks = [
                  ['data' => str_repeat('c', 9_000), 'position' => 9_000],
                  ['data' => str_repeat('d', 1_000), 'position' => 100],
                  ['file' => 'statics/alphanumeric.txt', 'offset' => 0, 'length' => 62, 'position' => 0],
                  ['data' => str_repeat('e', 70_000)],
               ];
               $Stream->chunk = 1;
               $expected = Buffers::weigh(300_000) + Buffers::weigh(1_000) + Buffers::weigh(70_000);
               $evidence['i stream'] = ['measure' => $Stream->measure(), 'expected' => $expected];
               $Check('i', 'Stream::measure() == weigh(backlog) + weigh() of each unconsumed in-memory chunk', $Stream->measure() === $expected);
               $Stream->close();

               // @ Encoder_HTTP2: a body the send window (0) cannot carry parks on Stream->Buffers
               [, , $Package] = $Open('i-tail');
               $H2 = new Decoder_HTTP2;
               $Decoders[] = $H2;
               $Package->Decoder = $H2;
               $Package->decoded = $H2;
               /** An open stream whose peer granted no send window. */
               $Park = static function (int $id) use ($H2): Stream {
                  $Stream = new Stream($id, 0, 65_535, $H2->Bodies);
                  $Stream->method = 'GET';
                  $Stream->ended = true;
                  $H2->Streams[$id] = $Stream;
                  $H2->opened++;
                  $H2->last = $id;

                  return $Stream;
               };
               /** Encode one response for `$id` through the production Raw -> Encoder_HTTP2 path. */
               $Encode = static function (int $id, string $body, bool $upload) use ($WPI, $Package): string {
                  $Request = new Request;
                  $Request->method = 'GET';
                  $Request->protocol = 'HTTP/2';
                  $Request->stream = $id;
                  if ($upload) {
                     $Request->Header->adopt(['range' => 'bytes=1-2,4-5']);
                  }
                  $WPI->Request = $Request;

                  $Response = new Response;
                  $Response->reset($Package, $Request);
                  if ($upload) {
                     $Response->upload('statics/alphanumeric.txt', close: false);
                  }
                  else {
                     $Response(body: $body);
                  }
                  $length = null;

                  return $Response->encode($Package, $length);
               };

               $Stream = $Park(1);
               $body = str_repeat('t', 1_048_600);
               $wire = $Encode(1, $body, false);
               $tail = $Stream->Buffers->retained;
               $evidence['i tail'] = [
                  'retained' => $tail,
                  'measure' => $Stream->measure(),
                  'delta' => TCPServer::$pendingBytes - $base,
                  'wire' => strlen($wire),
               ];
               $Check('legit', 'a response the send window cannot carry stays parked on its open stream', isset($H2->Streams[1])
                  && $Stream->backlog === $body
                  && $Stream->responded === true
                  && $wire !== ''
                  && $Package->rejected === false);
               $Check('i', 'weigh(1,048,600) is one whole 2 MiB chunk', Buffers::weigh(1_048_600) === 2_097_152);
               $Check('i', 'the parked tail charges weigh(strlen(body)) on Stream->Buffers', $tail === Buffers::weigh(strlen($body))
                  && $Stream->measure() === $tail
                  && TCPServer::$pendingBytes - $base === $tail);

               // @ A parked multipart tail charges weigh() of each in-memory pad chunk
               $Upload = $Park(3);
               $Encode(3, '', true);
               $pads = 0;
               $files = 0;
               $footprint = 0;
               $raw = 0;
               foreach ($Upload->chunks as $chunk) {
                  if (is_string($chunk['data'] ?? null)) {
                     $pads++;
                     $footprint += Buffers::weigh(strlen($chunk['data']));
                     $raw += strlen($chunk['data']);
                  }
                  else if (isset($chunk['file'])) {
                     $files++;
                  }
               }
               $evidence['i pads'] = [
                  'pads' => $pads,
                  'files' => $files,
                  'raw' => $raw,
                  'footprint' => $footprint,
                  'retained' => $Upload->Buffers->retained,
               ];
               $Check('legit', 'a multipart response the send window cannot carry stays parked', isset($H2->Streams[3])
                  && $Upload->responded === true
                  && $Upload->chunk === 0
                  && $pads >= 3
                  && $files === 2);
               $Check('i', 'the parked pads charge weigh() of each chunk string on Stream->Buffers', $Upload->Buffers->retained === $footprint
                  && $footprint !== $raw
                  && $Upload->measure() === $footprint
                  && TCPServer::$pendingBytes - $base === $tail + $footprint);

               // @ The per-connection cap admits a parked body by its footprint, not its length
               $Capped = $Park(5);
               $used = (int) (new ReflectionMethod(Decoder_HTTP2::class, 'weigh'))->invoke($H2);
               $before = TCPServer::$pendingBytes;
               TCPServer::$maxPendingBytes = $used + Buffers::weigh(1_048_600) - 1;
               $Encode(5, str_repeat('u', 1_048_600), false);
               TCPServer::$maxPendingBytes = 4 * 1024 * 1024;
               $evidence['i cap'] = [
                  'used' => $used,
                  'parked' => isset($H2->Streams[5]),
                  'retained' => $Capped->Buffers->retained,
                  'delta' => TCPServer::$pendingBytes - $before,
               ];
               $Check('i', 'a body whose footprint passes the per-connection cap is reset, though its length fits', isset($H2->Streams[5]) === false
                  && $Capped->Buffers->retained === 0
                  && TCPServer::$pendingBytes === $before);
               $H2->disconnect();
               $Check('legit', 'the parked tails are released with their connection', $Stream->Buffers->retained === 0
                  && $Upload->Buffers->retained === 0
                  && TCPServer::$pendingBytes === $base);

               // @ Decoder_HTTP2: a preface shorter than 24 bytes is held at weigh()
               [, , $Package] = $Open('i-preface');
               $H2 = new Decoder_HTTP2;
               $Decoders[] = $H2;
               $Package->Decoder = $H2;
               $Package->decoded = $H2;
               $prefix = substr(HTTP2::PREFACE, 0, 10);
               $State = $H2->decode($Package, $prefix, 10);
               $held = (new ReflectionMethod(Decoder_HTTP2::class, 'measure'))->invoke($H2);
               $evidence['i preface'] = [$State->name, strlen($H2->buffer), $held, $H2->Buffers->retained];
               $Check('legit', 'a short preface is held for the next read', $State->name === 'Incomplete'
                  && $H2->buffer === $prefix
                  && $Package->rejected === false);
               $Check('i', 'the short preface holds weigh(10) and measure() agrees', $held === Buffers::weigh(10)
                  && $H2->Buffers->retained === $held);
               $H2->disconnect();

               // @ Decoder_HTTP2: every receive-hold site charges weigh() of each held string
               [, , $Package] = $Open('i-carry');
               $H2 = new Decoder_HTTP2;
               $Decoders[] = $H2;
               $Package->Decoder = $H2;
               $Package->decoded = $H2;
               $Fragments = new ReflectionProperty(Decoder_HTTP2::class, 'fragments');
               $Measure = new ReflectionMethod(Decoder_HTTP2::class, 'measure');
               /** Decoder state after one read: [state, buffer, fragments, measure(), retained]. */
               $Read = static function (string $input) use ($H2, $Package, $Fragments, $Measure): array {
                  $State = $H2->decode($Package, $input, strlen($input));

                  return [
                     $State->name,
                     strlen($H2->buffer),
                     strlen((string) $Fragments->getValue($H2)),
                     $Measure->invoke($H2),
                     $H2->Buffers->retained,
                  ];
               };
               $preface = HTTP2::PREFACE;
               $settings = Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0);
               $headers = Frame::pack(HTTP2::FRAME_HEADERS, 0, 1, str_repeat('h', 3_000));
               $continuation = Frame::pack(HTTP2::FRAME_CONTINUATION, 0, 1, str_repeat('c', 5_000));
               $partial = substr(
                  Frame::pack(HTTP2::FRAME_CONTINUATION, HTTP2::FLAG_END_HEADERS, 1, str_repeat('k', 4_000)),
                  0,
                  1_009
               );
               // HEADERS without END_HEADERS: the first fragment awaits CONTINUATION
               $awaiting = $Read("{$preface}{$settings}{$headers}");
               // CONTINUATION without END_HEADERS: the fragments grow
               $continued = $Read($continuation);
               // A partial frame: the carry joins the fragments
               $carried = $Read($partial);
               // feed(): un-dispatched bytes grow the carry
               $H2->feed('qqq');
               $fed = [
                  strlen($H2->buffer),
                  strlen((string) $Fragments->getValue($H2)),
                  $Measure->invoke($H2),
                  $H2->Buffers->retained,
               ];
               $evidence['i carry'] = [
                  'awaiting' => $awaiting,
                  'continued' => $continued,
                  'carried' => $carried,
                  'fed' => $fed,
               ];
               $Check('legit', 'header fragments and a partial frame are held without refusal', $awaiting[0] === 'Incomplete'
                  && $continued[0] === 'Incomplete'
                  && $carried[0] === 'Incomplete'
                  && [$awaiting[1], $awaiting[2]] === [0, 3_000]
                  && [$continued[1], $continued[2]] === [0, 8_000]
                  && [$carried[1], $carried[2]] === [1_009, 8_000]
                  && [$fed[0], $fed[1]] === [1_012, 8_000]
                  && $Package->rejected === false
                  && $H2->closing === false);
               $Check('i', 'awaiting CONTINUATION holds weigh(fragment) and measure() agrees', $awaiting[3] === Buffers::weigh(3_000)
                  && $awaiting[4] === $awaiting[3]);
               $Check('i', 'a CONTINUATION holds weigh() of the grown fragments, never weigh() of each frame', $continued[3] === Buffers::weigh(8_000)
                  && $continued[4] === $continued[3]);
               $Check('i', 'Decoder_HTTP2::measure() == weigh(strlen(buffer)) + weigh(strlen(fragments)) for a partial frame', $carried[3] === Buffers::weigh(1_009) + Buffers::weigh(8_000)
                  && $carried[4] === $carried[3]);
               $Check('i', 'feed() holds weigh() of the grown carry beside the fragments', $fed[2] === Buffers::weigh(1_012) + Buffers::weigh(8_000)
                  && $fed[3] === $fed[2]);
               $H2->disconnect();
               $Check('legit', 'the decoder carry is released with its connection', $H2->Buffers->retained === 0
                  && TCPServer::$pendingBytes === $base);
            }
            finally {
               foreach ($Decoders as $Decoder) {
                  $Decoder->disconnect();
               }
            }
         });

         // # (j) Per-connection cap — the declared default admits two parked 1.1 MiB tails
         $Guard(['j', 'i'], 'default connection cap', static function () use (
            $Check, $Open, $Walk, $WPI, $base, &$evidence,
         ): void {
            // ! The DECLARED default per-connection cap: earlier rows pin their
            //   own, and this row never chooses one
            $default = (new ReflectionProperty(TCPServer::class, 'maxPendingBytes'))->getDefaultValue();
            TCPServer::$maxPendingBytes = $default;
            TCPServer::$maxWorkerPendingBytes = 64 * 1024 * 1024;
            $size = 1_153_434;
            $body = str_repeat('j', $size);
            $weight = Buffers::weigh($size);
            /** @var array<int,Decoder_HTTP2> $Decoders */
            $Decoders = [];

            try {
               // ! One HTTP/2 connection whose peer grants no send window
               [, , $Package] = $Open('j-cap');
               $H2 = new Decoder_HTTP2;
               $Decoders[] = $H2;
               $Package->Decoder = $H2;
               $Package->decoded = $H2;
               $settings = Frame::pack(
                  HTTP2::FRAME_SETTINGS,
                  0,
                  0,
                  pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 0)
               );
               /** A bodyless GET HEADERS frame opening stream `$id`. */
               $Head = static function (int $id): string {
                  return Frame::pack(
                     HTTP2::FRAME_HEADERS,
                     HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                     $id,
                     HPACK::encode([
                        [':method', 'GET'],
                        [':scheme', 'http'],
                        [':path', "/hhsc3/j/{$id}"],
                        [':authority', 'localhost'],
                     ])
                  );
               };
               /** Decode one read, then answer the stream it opened with the body. */
               $Answer = static function (string $input, int $id) use ($H2, $Package, $WPI, $body): array {
                  $State = $H2->decode($Package, $input, strlen($input));
                  if ($State->name !== 'Complete') {
                     throw new RuntimeException("Stream {$id} did not dispatch: {$State->name}.");
                  }
                  $Request = HTTPServer::$Request;
                  $WPI->Request = $Request;
                  $opened = $Request->stream === $id
                     && $Request->protocol === 'HTTP/2'
                     && $Package->consumed === strlen($input)
                     && isset($H2->Streams[$id]);
                  $streams = $H2->opened;

                  $before = TCPServer::$pendingBytes;
                  $Response = new Response;
                  $Response->reset($Package, $Request);
                  $Response(body: $body);
                  $length = null;
                  $wire = $Response->encode($Package, $length);

                  return [
                     'opened' => $opened,
                     'streams' => $streams,
                     'wire' => $wire,
                     'charged' => TCPServer::$pendingBytes - $before,
                  ];
               };

               // @ Two GETs on one connection, each answered with 1,153,434 bytes
               $preface = HTTP2::PREFACE;
               $first = $Head(1);
               $one = $Answer("{$preface}{$settings}{$first}", 1);
               $two = $Answer($Head(3), 3);
               $One = $H2->Streams[1] ?? null;
               $Two = $H2->Streams[3] ?? null;
               $census = [
                  'one' => $Walk($one['wire']),
                  'two' => $Walk($two['wire']),
                  'transport' => $Walk(HHSC3OutputStream::$written['j-cap'] ?? ''),
               ];
               $calm = 0;
               foreach ($census as $frames) {
                  foreach ($frames['resets'] as $code) {
                     $calm += $code === Errors::EnhanceYourCalm->value ? 1 : 0;
                  }
               }
               $evidence['j cap'] = [
                  'default' => $default,
                  'weight' => $weight,
                  'connection' => (int) (new ReflectionMethod(Decoder_HTTP2::class, 'weigh'))->invoke($H2),
                  'streams' => array_keys($H2->Streams),
                  'charged' => [$one['charged'], $two['charged']],
                  'retained' => [$One?->Buffers->retained, $Two?->Buffers->retained],
                  'census' => $census,
                  'calm' => $calm,
               ];
               $Check('j', 'two GETs open two streams on one HTTP/2 connection', $one['opened']
                  && $two['opened']
                  && [$one['streams'], $two['streams']] === [1, 2]);
               $Check('j', 'under the declared default cap both 1,153,434-byte responses stay parked on their open streams', $One !== null
                  && $Two !== null
                  && $One->backlog === $body
                  && $Two->backlog === $body
                  && $One->responded === true
                  && $Two->responded === true
                  && $Package->rejected === false
                  && $H2->closing === false);
               $Check('j', 'no RST_STREAM (ENHANCE_YOUR_CALM) or GOAWAY rides the wire', $calm === 0
                  && $census['one']['resets'] === []
                  && $census['two']['resets'] === []
                  && $census['transport']['resets'] === []
                  && $census['one']['goaways'] + $census['two']['goaways'] + $census['transport']['goaways'] === 0
                  && in_array(HTTP2::FRAME_HEADERS, $census['one']['types'], true)
                  && in_array(HTTP2::FRAME_HEADERS, $census['two']['types'], true));
               $Check('i', 'the two parked tails are charged at weigh(1,153,434) each, never at their length', $weight !== $size
                  && $One?->Buffers->retained === $weight
                  && $Two?->Buffers->retained === $weight
                  && $one['charged'] + $two['charged'] === 2 * $weight);

               // @ The connection gives every tail back
               $H2->disconnect();
               $Check('j', 'the parked tails are released with their connection', ($One?->Buffers->retained ?? 0) === 0
                  && ($Two?->Buffers->retained ?? 0) === 0
                  && TCPServer::$pendingBytes === $base);
            }
            finally {
               foreach ($Decoders as $Decoder) {
                  $Decoder->disconnect();
               }
            }
         });
      }
      finally {
         // @ Release every transport owner and the case's cache token
         foreach ($Packages as $Package) {
            $Package->purge();
            $Package->Decoder = null;
            $Package->decoded = null;
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }
         Cache::flush();

         // ! What the private ledger holds once every owner the case created let go
         $drained = ['pending' => TCPServer::$pendingBytes, 'entries' => count(Cache::$entries), 'bytes' => Cache::$bytes];
         foreach ($Ledger as $label => [$Property]) {
            if ($label !== 'Buffers epoch' && $label !== 'Cache token') {
               $drained[$label] = $Property->getValue();
            }
         }

         // @ Restore every changed static exactly
         foreach ($Ledger as [$Property, $saved]) {
            $Property->setValue(null, $saved);
         }
         TCPServer::$pendingBytes = $savedPending;
         Cache::$entries = $savedEntries;
         Cache::$bytes = $savedBytes;
         Cache::$URIs = $savedURIs;
         Cache::$maxBytes = $savedMaxBytes;
         Cache::$generation = $savedGeneration;
         Request::$multiparts = $savedMultiparts;
         TCPServer::$maxWorkerPendingBytes = $savedBudget;
         TCPServer::$maxPendingBytes = $savedConnectionCap;
         if ($OldEvent !== null) {
            TCPServer::$Event = $OldEvent;
         }
         if ($OldServerRequest !== null) {
            HTTPServer::$Request = $OldServerRequest;
         }
         $WPI->Request = $OldRequest;
         HHSC3OutputStream::reset();
      }

      // # Ledger hygiene — the case drains its private ledger and leaves the worker where it found it
      $restored = true;
      foreach ($Ledger as [$Property, $saved]) {
         $restored = $restored && $Property->getValue() === $saved;
      }
      $evidence['drained'] = $drained;
      $evidence['final'] = TCPServer::$pendingBytes;
      $Check('ledger', 'every owner the case created gives its footprint back: the private ledger drains to zero', $drained['pending'] === 0
         && $drained['entries'] === 0
         && $drained['bytes'] === 0
         && ($drained['Buffers total'] ?? null) === 0
         && ($drained['Buffers held'] ?? [0, 0, 0]) === [0, 0, 0]
         && ($drained['Bodies total'] ?? null) === 0
         && ($drained['HTTP/2 Bodies total'] ?? null) === 0);
      $Check('ledger', 'every saved worker static, ledger and cache token is restored exactly', $restored
         && TCPServer::$pendingBytes === $savedPending
         && TCPServer::$maxWorkerPendingBytes === $savedBudget
         && TCPServer::$maxPendingBytes === $savedConnectionCap
         && Cache::$entries === $savedEntries
         && Cache::$bytes === $savedBytes
         && Cache::$URIs === $savedURIs
         && Cache::$maxBytes === $savedMaxBytes
         && Cache::$generation === $savedGeneration
         && Request::$multiparts === $savedMultiparts
         && $WPI->Request === $OldRequest
         && ($OldServerRequest === null || HTTPServer::$Request === $OldServerRequest));

      return "GET /hhsc3-cache-output-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/hhsc3-cache-output-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'HHSC3-CACHE-OUTPUT-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$checks, &$evidence): Generator {
      // ?
      if (str_contains($response, 'HHSC3-CACHE-OUTPUT-HARNESS-OK') === false) {
         yield 'H-HSC-3 harness request did not reach its control route.';
      }

      // ! Every failing check up front, so one red run names them all
      $rows = [
         'legit' => 'legit load: the same fixtures still store, park, queue and drain ordinary traffic',
         'g' => '(g) the route cache holds its wires at weigh() in the Resident share and gives them back on evict, expiry and flush',
         'h' => '(h) TCP Packages charge deferred suffixes, receive carry, coalesced and queued responses and multipart pads at weigh() of each string against both caps',
         'i' => '(i) HTTP/2 stream tails, parked bodies and pads, and every decoder receive hold are charged at weigh() of each string',
         'j' => '(j) legit load: under the declared default per-connection cap, two 1,153,434-byte responses parked on two streams of one HTTP/2 connection are both admitted',
         'ledger' => 'ledger: the case runs on a private worker ledger, drains it, and restores every static it found',
      ];
      $failures = [];
      foreach ($rows as $row => $title) {
         if ($checks[$row] === []) {
            $failures[$row] = ['no evidence: the row never ran'];
            continue;
         }
         foreach ($checks[$row] as $label => $passed) {
            if ($passed !== true) {
               $failures[$row][] = $label;
            }
         }
      }
      $summary = json_encode(['failures' => $failures, 'evidence' => $evidence], JSON_UNESCAPED_SLASHES);

      // @@
      foreach ($rows as $row => $title) {
         if (isset($failures[$row])) {
            $list = implode('; ', $failures[$row]);
            yield "{$title} — FAILED: {$list} | {$summary}";
            continue;
         }

         Assertion::$description = $title;
         yield true;
      }
   },
);
