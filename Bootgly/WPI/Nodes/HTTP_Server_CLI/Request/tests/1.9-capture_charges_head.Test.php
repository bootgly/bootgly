<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Frame;


/**
 * A deferred snapshot (`Request::capture()`) is charged to the worker memory
 * budget for what it keeps while parked — its copy of the HTTP/1 head, what
 * was already parsed from it, and what the deferred work parses later — not
 * only for its body.
 *
 * The live Request drops its head once answered (`shed()`); the snapshot keeps
 * its own until the deferral ends. A bodyless GET was charged nothing, so a
 * parked deferral kept up to ~370 KB of parsed head (cookies, refused
 * languages, query vars) outside every ledger. Pinned:
 *
 * - per shape (cookie pairs and lines, a long cookie value, refused languages,
 *   query vars, a long query value, nested keys in both bracket encodings, a
 *   long path, a minimal head, heads of bare separators, and long query and
 *   cookie names): `capture()`
 *   charges what the snapshot holds (`weigh()`), and after the deferred work
 *   parsed cookies, languages and query vars the charge covers what the
 *   snapshot keeps — measured by dropping it — and stays within twice that,
 *   so a crafted head cannot reserve the budget it does not use;
 * - a parse the budget cannot hold is refused inside the work and keeps
 *   nothing; an HTTP/2 stream keeps its head charged itself; a snapshot that
 *   does not fit the Inbound share is refused and moves nothing; `clean()`
 *   returns every charge;
 * - what the route parsed or set before `capture()` (routed name, query and
 *   its vars, refused languages, bearer and Basic credentials, file records,
 *   claims, token headers) is priced with the snapshot — each shape leans on
 *   one term of the price, so dropping any term turns it red — and body
 *   fields the deferred work parses (urlencoded, JSON maps, lists and nested
 *   maps) are charged once: a second read moves nothing;
 * - query vars, the routed name, refused languages, a bearer token, Basic
 *   credentials and body fields that do not fit are refused on the snapshot,
 *   move nothing and leave their memo unbuilt; a second languages read pays
 *   only for growth; a parse after `clean()` is not charged;
 * - `receive()` charges its parse once and then only the growth (nothing),
 *   keyed or not; reading the raw `input` decodes no fields, so it is free
 *   on a snapshot whose share holds no field decode (the fields stay
 *   refused);
 * - a Basic parse on a snapshot is charged both strings plus 128, a bearer
 *   token its string; a refused Basic parse leaves the credentials unparsed
 *   (a read once there is room returns them), and a refused languages parse
 *   keeps the refusals parsed before it;
 * - sparse integer keys (`0`..`16`, `63`, `64`) — 1,000 JSON objects (125 KB
 *   decoding into ~8.4 MB), 52 query arrays (988 vars), 40 Cookie lines of
 *   numeric names — begin as lists and end as maps of 128 slots: each shape
 *   is charged what its snapshot keeps (`Buffers\Table`), within twice that
 *   (JSON within 1.2 times), never the table their count suggests.
 */
return new Test(
   description: 'A deferred snapshot is charged for its head and what is parsed from it',
   test: function () {
      // ! One decoded head per shape: each one exercises one term of the charge
      $pairs = [];
      $lines = [];
      $ranges = [];
      $vars = [];
      $nested = [];
      $encoded = [];
      $letters = 'abcdefghijklmnopqrstuvwxyz';
      for ($index = 0; $index < 1500; $index++) {
         $pairs[] = "c{$index}=v";
         $lines[] = "Cookie: c{$index}=v\r\n";
         $ranges[] = "{$letters[intdiv($index, 26) % 26]}{$letters[$index % 26]}{$letters[intdiv($index, 676)]};q=0";
         $vars[] = "k{$index}=v";
         $nested[] = "k{$index}[a][b][c][d]=v";
         $encoded[] = $index % 2 === 0
            ? "k{$index}%5Ba%5D%5Bb%5D%5Bc%5D%5Bd%5D=v"
            : "k{$index}%5ba%5d%5bb%5d%5bc%5d%5bd%5d=v";
      }
      $cookie = implode('; ', array_slice($pairs, 0, 400));
      $cookies = implode('', array_slice($lines, 0, 60));
      $language = implode(',', $ranges);
      $query = implode('&', array_slice($vars, 0, 200));
      $nest = implode('&', array_slice($nested, 0, 30));
      $escaped = implode('&', array_slice($encoded, 0, 30));
      $path = str_repeat('p', 4000);
      $value = str_repeat('v', 12000);
      $long = str_repeat('q', 7000);
      $brackets = str_repeat('[', 4000);
      $semicolons = str_repeat(';', 4000);
      // ! Long string keys: query vars named in 200 bytes, cookies in 100
      $stem = str_repeat('k', 197);
      $crumb = str_repeat('c', 97);
      $keys = [];
      $names = [];
      for ($index = 0; $index < 100; $index++) {
         $padded = sprintf('%03d', $index);
         $keys[] = "{$stem}{$padded}=v";
         $names[] = "{$crumb}{$padded}=v";
      }
      $keyed = implode('&', array_slice($keys, 0, 35));
      $named = implode('; ', array_slice($names, 0, 25));
      $Shapes = [
         'minimal' => "GET / HTTP/1.1\r\nHost: snapshot.example\r\n\r\n",
         'cookie pairs' => "GET / HTTP/1.1\r\nHost: s\r\nCookie: {$cookie}\r\n\r\n",
         'cookie lines' => "GET / HTTP/1.1\r\nHost: s\r\n{$cookies}\r\n",
         'cookie value' => "GET / HTTP/1.1\r\nHost: s\r\nCookie: a={$value}\r\n\r\n",
         'languages' => "GET / HTTP/1.1\r\nHost: s\r\nAccept-Language: {$language}\r\n\r\n",
         'query' => "GET /q?{$query} HTTP/1.1\r\nHost: s\r\n\r\n",
         'query value' => "GET /q?a={$long} HTTP/1.1\r\nHost: s\r\n\r\n",
         'nested query' => "GET /q?{$nest} HTTP/1.1\r\nHost: s\r\n\r\n",
         'encoded nest' => "GET /q?{$escaped} HTTP/1.1\r\nHost: s\r\n\r\n",
         'path' => "GET /{$path} HTTP/1.1\r\nHost: s\r\n\r\n",
         'bare separators' => "GET /q?{$brackets} HTTP/1.1\r\nHost: s\r\nCookie: x{$semicolons}\r\n"
            . "Accept-Language: {$semicolons}\r\n\r\n",
         'long query keys' => "GET /q?{$keyed} HTTP/1.1\r\nHost: s\r\n\r\n",
         'long cookie names' => "GET / HTTP/1.1\r\nHost: s\r\nCookie: {$named}\r\n\r\n",
      ];

      // ! A decoded per-connection Request, its head in strings of its own —
      //   and its body, unparsed, when `$body` keeps it
      $Decode = static function (string $wire, bool $body = false): Request {
         $Connection = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         $Connection->ip = '127.0.0.1';
         $Connection->port = 40000;
         $Connection->encrypted = false;
         $Connection->writes = 1;
         $Package = new class extends TCPPackages {
            // ! decode() only reads the connection truth and the reject slot
            public function __construct () {}

            public function reject (string $raw): void
            {
               throw new RuntimeException("A 1.9 shape was rejected: {$raw}");
            }
         };
         $Package->Connection = $Connection;
         $Request = new Request;
         $Request->reset();
         $buffer = $wire;
         $Request->decode($Package, $buffer, strlen($buffer));
         if ($body === false) {
            $Request->scrub();
         }
         // @ The Router derives the routed path before the handler runs
         $Request->URL;

         return $Request;
      };
      $Price = static fn (Request $Request): int => new ReflectionMethod(Request::class, 'weigh')->invoke($Request);
      $Held = static fn (): int => new ReflectionProperty(Buffers::class, 'held')->getValue()[Shares::Inbound->value];
      $Scans = new ReflectionProperty(Frame::class, 'scans');
      $scans = $Scans->getValue();

      $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
      try {
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;

         // # The charge covers what the snapshots keep, shape by shape
         $count = 40;
         $rows = [];
         $Measure = static function (string $wire, bool $read) use ($Decode, $Scans, $Price, $Held, $count): array {
            $Lives = [];
            for ($index = 0; $index < $count; $index++) {
               $Lives[] = $Decode($wire);
               $Scans->setValue(null, []);
            }
            $price = $Price($Lives[0]);
            $before = $Held();
            $Snapshots = [];
            foreach ($Lives as $Live) {
               $Snapshot = $Live->capture();
               // @ The live Request is answered and drops its head
               $Live->shed();
               $Snapshots[] = $Snapshot;
            }
            $captured = intdiv($Held() - $before, $count);
            // @ The deferred work parses what it reads
            foreach ($Snapshots as $Snapshot) {
               if ($read) {
                  $Snapshot->cookies;
                  $Snapshot->languages;
                  $Snapshot->queries;
                  $Snapshot->URN;
               }
            }
            unset($Snapshot, $Live);
            $charged = intdiv($Held() - $before, $count);
            // ! What the snapshots keep: the memory dropping them returns
            gc_collect_cycles();
            $memory = memory_get_usage();
            foreach ($Snapshots as $Snapshot) {
               $Snapshot->clean();
            }
            unset($Snapshot);
            $Snapshots = [];
            gc_collect_cycles();

            // :
            return [
               'price' => $price,
               'captured' => $captured,
               'charged' => $charged,
               'kept' => intdiv($memory - memory_get_usage(), $count),
               'returned' => $Held() - $before,
            ];
         };
         foreach ($Shapes as $shape => $wire) {
            // ! One round outside the measure: first-time allocations land there
            $Measure($wire, true);
            $rows[$shape] = $Measure($wire, true);
            $rows["{$shape} (unread)"] = $Measure($wire, false);
         }
         $failed = array_filter($rows, static fn (array $row): bool => $row['captured'] !== $row['price']
            || $row['charged'] < $row['kept']
            || $row['charged'] > 2 * $row['kept']
            || $row['kept'] <= 0
            || $row['returned'] !== 0);
         $evidence = json_encode($rows);

         yield assert(
            assertion: $failed === [],
            description: "Each snapshot is charged what it holds, then what its work parses — covering what it keeps, within twice that — and clean() returns it: {$evidence}",
         );

         // # Body fields parsed before the snapshot (multipart text parts) are
         //   charged with it: the body itself streamed, so it holds no raw bytes
         $Lives = [];
         for ($index = 0; $index < $count; $index++) {
            $Live = $Decode($Shapes['minimal']);
            $Live->fields = [
               'a' => str_repeat('a', 50_000) . $index,
               'b' => str_repeat('b', 50_000) . $index,
               'c' => str_repeat('c', 50_000) . $index,
            ];
            $Lives[] = $Live;
         }
         $Scans->setValue(null, []);
         $before = $Held();
         $Snapshots = [];
         foreach ($Lives as $Live) {
            $Snapshots[] = $Live->capture();
            $Live->shed();
            $Live->scrub();
         }
         unset($Live);
         $fieldsCharged = intdiv($Held() - $before, $count);
         gc_collect_cycles();
         $memory = memory_get_usage();
         foreach ($Snapshots as $Snapshot) {
            $Snapshot->clean();
         }
         unset($Snapshot);
         $Snapshots = [];
         gc_collect_cycles();
         $fieldsKept = intdiv($memory - memory_get_usage(), $count);
         $Lives = [];
         $fieldsEvidence = json_encode(['charged' => $fieldsCharged, 'kept' => $fieldsKept, 'returned' => $Held() - $before]);

         yield assert(
            assertion: $fieldsKept > 150_000
               && $fieldsCharged >= $fieldsKept
               && $fieldsCharged <= 2 * $fieldsKept
               && $Held() === $before,
            description: "Body fields copied into a snapshot are charged with it: {$fieldsEvidence}",
         );

         // # A parse the budget cannot hold is refused and keeps nothing
         $Parsing = $Decode($Shapes['cookie pairs']);
         $Scans->setValue(null, []);
         $held = $Held();
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $Price($Parsing));
         $Parsed = $Parsing->capture();
         $Parsing->shed();
         $refused = '';
         try {
            $Parsed->cookies;
         }
         catch (RuntimeException $Exception) {
            $refused = $Exception->getMessage();
         }
         $moved = $Held() - $held - $Price($Parsed);
         $Parsed->clean();
         $parseEvidence = json_encode(['refused' => $refused, 'moved' => $moved, 'returned' => $Held() - $held]);
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;

         yield assert(
            assertion: $refused === Bodies::REFUSAL && $moved === 0 && $Held() === $held,
            description: "A cookie parse that does not fit is refused inside the work and moves nothing: {$parseEvidence}",
         );

         // ! The head the last two checks price
         $Head = $Decode($Shapes['cookie pairs']);
         $raw = $Head->Header->raw;
         $fields = $Head->Header->fields;
         $URI = $Head->URI;
         $price = $Price($Head);
         $Make = static function (int $stream) use ($Decode, $Shapes): Request {
            $Request = $Decode($Shapes['cookie pairs']);
            new ReflectionProperty(Request::class, 'stream')->setRawValue($Request, $stream);

            return $Request;
         };

         // # HTTP/2: the stream keeps the head charged
         TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
         $stream = $Price($Make(1));
         $expected = $price - Frame::weigh($raw, $fields) - Buffers::weigh(strlen($URI));
         $streamEvidence = json_encode(['stream' => $stream, 'expected' => $expected]);

         yield assert(
            assertion: $stream === $expected && $stream > 0,
            description: "An HTTP/2 snapshot is charged without the head its stream holds: {$streamEvidence}",
         );

         // # The edge of the Inbound share
         $held = $Held();
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $price);
         $Fit = $Make(0)->capture();
         $fit = $Held() - $held;
         $Fit->clean();

         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $price) - 2;
         $refused = '';
         try {
            $Over = $Make(0)->capture();
            $Over->clean();
         }
         catch (RuntimeException $Exception) {
            $refused = $Exception->getMessage();
         }
         $moved = $Held() - $held;
         $edgeEvidence = json_encode(['fit' => $fit, 'refused' => $refused, 'moved' => $moved]);

         yield assert(
            assertion: $fit === $price
               && $refused === Bodies::REFUSAL
               && $moved === 0,
            description: "A snapshot fits a share of exactly its price and is refused one byte short, moving nothing: {$edgeEvidence}",
         );


         // ---
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
         // ! A private member of a Request, read without its hooks
         $Raw = static fn (Request $Request, string $name): mixed => new ReflectionProperty(Request::class, $name)->getValue($Request);
         // ! A request carrying `$body` of media type `$type`
         $Post = static function (string $type, string $body): string {
            $length = strlen($body);

            // :
            return "POST /f HTTP/1.1\r\nHost: s\r\nContent-Type: {$type}\r\nContent-Length: {$length}\r\n\r\n{$body}";
         };
         // ! `$copies` snapshots (`$count` by default) of `$wire` (with its
         //   body when `$body`): `$Prepare` runs on each live Request before
         //   `capture()` — what the route read or set — and `$Work` twice on
         //   each snapshot after it, the first result kept with the snapshot
         //   as the deferred work would keep it
         $Gauge = static function (string $wire, bool $body, Closure $Prepare, Closure $Work, null|int $copies = null) use ($Decode, $Scans, $Price, $Held, $count): array {
            $copies ??= $count;
            $Lives = [];
            $priced = 0;
            for ($index = 0; $index < $copies; $index++) {
               $Live = $Decode($wire, $body);
               $Prepare($Live, $index);
               $Scans->setValue(null, []);
               $priced += $Price($Live) + Buffers::weigh(strlen($Live->Body->raw));
               $Lives[] = $Live;
            }
            $before = $Held();
            $Snapshots = [];
            foreach ($Lives as $Live) {
               $Snapshots[] = $Live->capture();
               // @ The live Request is answered, drops its head and body and
               //   is reused: nothing it shared with the snapshot stays its own
               $Live->shed();
               $Live->scrub();
            }
            unset($Live);
            $Lives = [];
            $captured = $Held() - $before;
            // @ The deferred work reads, and keeps what it read
            $Results = [];
            foreach ($Snapshots as $Snapshot) {
               $Results[] = $Work($Snapshot);
            }
            $charged = intdiv($Held() - $before, $copies);
            // @ … and reads it again
            $held = $Held();
            $same = true;
            foreach ($Snapshots as $index => $Snapshot) {
               $same = $same && $Work($Snapshot) === $Results[$index];
            }
            unset($Snapshot);
            $again = $Held() - $held;
            // ! What the snapshots keep: the memory dropping them returns
            gc_collect_cycles();
            $memory = memory_get_usage();
            foreach ($Snapshots as $Snapshot) {
               $Snapshot->clean();
            }
            unset($Snapshot);
            $Snapshots = [];
            $Results = [];
            gc_collect_cycles();
            $kept = intdiv($memory - memory_get_usage(), $copies);

            // :
            return [
               'priced' => $captured === $priced,
               'charged' => $charged,
               'kept' => $kept,
               'ratio' => $kept > 0 ? round($charged / $kept, 3) : 0,
               'again' => $again,
               'same' => $same,
               'returned' => $Held() - $before,
            ];
         };
         $Idle = static function (): void {};
         $Within = static fn (array $row): bool => $row['priced'] === true
            && $row['kept'] > 0
            && $row['charged'] >= $row['kept']
            && $row['charged'] <= 2 * $row['kept']
            && $row['again'] === 0
            && $row['same'] === true
            && $row['returned'] === 0;
         // ! Per-request strings of their own (a shared literal is kept once),
         //   allocated to their length as a decoder allocates them
         $fill = str_repeat('m', 100);
         $Map = static function (string $prefix, int $index) use ($fill): array {
            $padded = sprintf('%03d', $index);
            $map = [];
            for ($key = 0; $key < 20; $key++) {
               $map["{$prefix}{$key}"] = "{$fill}{$padded}";
            }

            // :
            return $map;
         };
         $upload = str_repeat('n', 200);
         $Files = static function (int $index) use ($upload): array {
            $padded = sprintf('%03d', $index);
            $files = [];
            for ($key = 0; $key < 100; $key++) {
               $files["upload{$key}"] = [
                  'name' => "{$upload}{$padded}",
                  'type' => "text/plain{$padded}",
                  'tmp_name' => '',
                  'error' => 0,
                  'size' => 1,
               ];
            }

            // :
            return $files;
         };
         // ! Strings in small bins: the charge then leans on the term each
         //   shape exercises, not on the page share a large string pays
         $segment = str_repeat('s', 1000);
         $bearer = str_repeat('t', 1000);
         $user = str_repeat('u', 600);
         $username = base64_encode("{$user}:p");
         $secret = str_repeat('p', 600);
         $password = base64_encode("u:{$secret}");
         $refusing = implode(',', array_slice($ranges, 0, 100));
         $wires = [
            'URN' => "GET /a/{$segment} HTTP/1.1\r\nHost: s\r\n\r\n",
            'query' => "GET /q?a={$segment} HTTP/1.1\r\nHost: s\r\n\r\n",
            'languages' => "GET / HTTP/1.1\r\nHost: s\r\nAccept-Language: {$refusing}\r\n\r\n",
            'token' => "GET / HTTP/1.1\r\nHost: s\r\nAuthorization: Bearer {$bearer}\r\n\r\n",
            'username' => "GET / HTTP/1.1\r\nHost: s\r\nAuthorization: Basic {$username}\r\n\r\n",
            'password' => "GET / HTTP/1.1\r\nHost: s\r\nAuthorization: Basic {$password}\r\n\r\n",
         ];

         // # What the route parsed before `capture()` is charged with the
         //   snapshot: each shape leans on one term of the price (`weigh()`)
         $Befores = [
            'URN' => [$wires['URN'], static fn (Request $Live) => $Live->URN],
            'query' => [$wires['query'], static fn (Request $Live) => $Live->query],
            'queries' => [$Shapes['query'], static fn (Request $Live) => $Live->queries],
            'languages' => [$wires['languages'], static fn (Request $Live) => $Live->languages],
            'token' => [$wires['token'], static fn (Request $Live) => $Live->token],
            'username' => [$wires['username'], static fn (Request $Live) => $Live->username],
            'password' => [$wires['password'], static fn (Request $Live) => $Live->password],
            'files' => [$Shapes['minimal'], static function (Request $Live, int $index) use ($Files): void {
               $Live->files = $Files($index);
            }],
            'claims' => [$Shapes['minimal'], static function (Request $Live, int $index) use ($Map): void {
               $Live->claims = $Map('claim', $index);
            }],
            'tokenHeaders' => [$Shapes['minimal'], static function (Request $Live, int $index) use ($Map): void {
               $Live->tokenHeaders = $Map('header', $index);
            }],
         ];
         $rows = [];
         foreach ($Befores as $shape => [$wire, $Prepare]) {
            // ! One round outside the measure: first-time allocations land there
            $Gauge($wire, false, $Prepare, $Idle);
            $rows[$shape] = $Gauge($wire, false, $Prepare, $Idle);
         }
         $failed = array_filter($rows, static fn (array $row): bool => $Within($row) === false);
         $evidence = json_encode($rows);

         yield assert(
            assertion: $failed === [],
            description: "What the route parsed or set before capture() — routed name, query and its vars, refused languages, credentials, file records, claims, token headers — is charged with the snapshot, covering what it keeps, within twice that: {$evidence}",
         );

         // # Body fields the deferred work parses are charged when parsed —
         //   once: a second read returns the same fields and moves nothing
         // ! Lists of one int: the array and its table, nothing else
         $list = implode(',', array_fill(0, 24, '[0]'));
         // ! Half the form values are one byte: PHP shares those (and,
         //   without opcache, interns every parsed key for good)
         $form = [];
         $json = [];
         $nests = [];
         for ($index = 0; $index < 900; $index++) {
            $form[] = $index % 2 === 0 ? "k{$index}=v" : "k{$index}=vw";
         }
         for ($index = 0; $index < 800; $index++) {
            $json[] = "\"key{$index}\":\"value{$index}\"";
            $nests[] = "\"k{$index}\":{\"a\":{\"b\":{$index}}}";
         }
         $form = implode('&', $form);
         $map = implode(',', $json);
         $nesting = implode(',', array_slice($nests, 0, 500));
         $payloads = [
            'urlencoded' => $Post('application/x-www-form-urlencoded', $form),
            'JSON map' => $Post('application/json', "{{$map}}"),
            'JSON list' => $Post('application/json', "[{$list}]"),
            'JSON nested maps' => $Post('application/json', "{{$nesting}}"),
         ];
         $Read = static fn (Request $Snapshot): array => $Snapshot->fields;
         $rows = [];
         foreach ($payloads as $shape => $wire) {
            $Gauge($wire, true, $Idle, $Read);
            $rows[$shape] = $Gauge($wire, true, $Idle, $Read);
         }
         $failed = array_filter($rows, static fn (array $row): bool => $Within($row) === false);
         $evidence = json_encode($rows);

         yield assert(
            assertion: $failed === [],
            description: "Body fields the deferred work parses are charged — covering what the snapshot and its work keep, within twice that — and a second read parses nothing more: {$evidence}",
         );

         // # A parse the budget cannot hold is refused on the snapshot, moves
         //   nothing and leaves its memo unbuilt
         // ! Per parse: the wire (with its body?), what the route read before
         //   capture(), the read on the snapshot, and its memo left unbuilt
         $Refusals = [
            'queries' => [
               $Shapes['query'], false,
               static fn (Request $Live) => $Live->query,
               static fn (Request $Snapshot) => $Snapshot->queries,
               static fn (Request $Snapshot): bool => $Raw($Snapshot, '_queries') === null,
            ],
            'URN' => [
               $wires['URN'], false, $Idle,
               static fn (Request $Snapshot) => $Snapshot->URN,
               static fn (Request $Snapshot): bool => $Raw($Snapshot, '_URN') === null,
            ],
            'languages' => [
               $Shapes['languages'], false, $Idle,
               static fn (Request $Snapshot) => $Snapshot->languages,
               static fn (Request $Snapshot): bool => $Snapshot->exclusions === [],
            ],
            'token' => [
               $wires['token'], false, $Idle,
               static fn (Request $Snapshot) => $Snapshot->token,
               static fn (Request $Snapshot): bool => $Raw($Snapshot, 'authToken') === ''
                  && $Raw($Snapshot, 'authTokenParsed') === false,
            ],
            'username' => [
               $wires['username'], false, $Idle,
               static fn (Request $Snapshot) => $Snapshot->username,
               static fn (Request $Snapshot): bool => $Raw($Snapshot, 'authUsername') === ''
                  && $Raw($Snapshot, 'authCredentials') === null,
            ],
            'urlencoded fields' => [
               $payloads['urlencoded'], true, $Idle, $Read,
               static fn (Request $Snapshot): bool => $Raw($Snapshot, '_fields') === [],
            ],
            'JSON fields' => [
               $payloads['JSON map'], true, $Idle, $Read,
               static fn (Request $Snapshot): bool => $Raw($Snapshot, '_fields') === [],
            ],
         ];
         $rows = [];
         foreach ($Refusals as $shape => [$wire, $body, $Prepare, $Parse, $Unbuilt]) {
            TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
            $Parsing = $Decode($wire, $body);
            $Prepare($Parsing);
            $Scans->setValue(null, []);
            $held = $Held();
            $price = $Price($Parsing) + Buffers::weigh(strlen($Parsing->Body->raw));
            // ! A share that holds the snapshot and nothing more
            TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $price);
            $Parsed = $Parsing->capture();
            $Parsing->shed();
            $Parsing->scrub();
            $captured = $Held() - $held;
            $refused = '';
            try {
               $Parse($Parsed);
            }
            catch (RuntimeException $Exception) {
               $refused = $Exception->getMessage();
            }
            $rows[$shape] = [
               'captured' => $captured === $price,
               'refused' => $refused === Bodies::REFUSAL,
               'moved' => $Held() - $held - $captured,
               'unbuilt' => $Unbuilt($Parsed),
            ];
            $Parsed->clean();
            $rows[$shape]['returned'] = $Held() - $held;
         }
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
         $failed = array_filter($rows, static fn (array $row): bool => $row !== [
            'captured' => true, 'refused' => true, 'moved' => 0, 'unbuilt' => true, 'returned' => 0,
         ]);
         $evidence = json_encode($rows);

         yield assert(
            assertion: $failed === [],
            description: "Query vars, routed name, refused languages, bearer token, Basic credentials and body fields that do not fit are refused on the snapshot, move nothing and stay unbuilt: {$evidence}",
         );

         // # Reading the languages again pays only for growth: nothing
         $Live = $Decode($Shapes['languages']);
         $Scans->setValue(null, []);
         $Snapshot = $Live->capture();
         $Live->shed();
         $held = $Held();
         $first = $Snapshot->languages;
         $once = $Held() - $held;
         $exclusions = $Snapshot->exclusions;
         $held = $Held();
         $second = $Snapshot->languages;
         $twice = $Held() - $held;
         $same = $first === $second && $exclusions === $Snapshot->exclusions;
         $Snapshot->clean();
         $againEvidence = json_encode(['once' => $once, 'twice' => $twice, 'same' => $same, 'exclusions' => count($exclusions)]);

         yield assert(
            assertion: $once > 0 && $twice === 0 && $same && count($exclusions) === 1500,
            description: "A second read of the languages moves the ledger by nothing: {$againEvidence}",
         );

         // # A parse after `clean()` is not charged: the token is released
         $Live = $Decode($Shapes['cookie pairs']);
         $Scans->setValue(null, []);
         $held = $Held();
         $Snapshot = $Live->capture();
         $Live->shed();
         $Snapshot->clean();
         $cleaned = $Held() - $held;
         $cookies = $Snapshot->cookies;
         $moved = $Held() - $held;
         $Snapshot = null;
         $cleanEvidence = json_encode(['cleaned' => $cleaned, 'moved' => $moved, 'cookies' => count($cookies[0] ?? [])]);

         yield assert(
            assertion: $cleaned === 0 && $moved === 0 && count($cookies[0] ?? []) === 400,
            description: "Cookies parsed after clean() move the ledger by nothing: {$cleanEvidence}",
         );

         // # receive() pays only for growth: the first call pays for the
         //   parse, a later one — keyed or not — parses the same body again
         //   and moves nothing, nor does reading the fields it kept
         $Live = $Decode($payloads['urlencoded'], true);
         $Scans->setValue(null, []);
         $Snapshot = $Live->capture();
         $Live->shed();
         $Live->scrub();
         $held = $Held();
         $odd = $Snapshot->receive('k1');
         $parse = $Held() - $held;
         $held = $Held();
         $even = $Snapshot->receive('k2');
         $keyed = $Held() - $held;
         $held = $Held();
         $whole = $Snapshot->receive();
         $again = $Held() - $held;
         $held = $Held();
         $read = $Snapshot->fields;
         $hooked = $Held() - $held;
         $tally = Buffers::tally($read);
         $Snapshot->clean();
         $Snapshot = null;
         $receiveEvidence = json_encode([
            'parse' => $parse, 'tally' => $tally, 'keyed' => $keyed, 'again' => $again,
            'hooked' => $hooked, 'odd' => $odd, 'even' => $even, 'fields' => count($read),
         ]);

         yield assert(
            assertion: $parse === $tally
               && $tally > 0
               && $keyed === 0
               && $again === 0
               && $hooked === 0
               && $odd === 'vw'
               && $even === 'v'
               && $whole === $read
               && count($read) === 900,
            description: "receive() charges the parse once and then only its growth — nothing: {$receiveEvidence}",
         );

         // # The raw input is free: reading it never decodes the fields, so a
         //   snapshot whose share holds it and no field decode reads it and
         //   moves nothing — the fields read after it are still refused
         $document = "{{$map}}";
         $Live = $Decode($payloads['JSON map'], true);
         $Scans->setValue(null, []);
         $held = $Held();
         $price = $Price($Live) + Buffers::weigh(strlen($Live->Body->raw));
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $price);
         $Snapshot = $Live->capture();
         $Live->shed();
         $Live->scrub();
         $captured = $Held() - $held;
         $input = '';
         $thrown = '';
         try {
            $input = $Snapshot->input;
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable->getMessage();
         }
         $inputMoved = $Held() - $held - $captured;
         $refused = '';
         try {
            $Snapshot->fields;
         }
         catch (RuntimeException $Exception) {
            $refused = $Exception->getMessage();
         }
         $fieldsMoved = $Held() - $held - $captured;
         $unbuilt = $Raw($Snapshot, '_fields') === [];
         $Snapshot->clean();
         $Snapshot = null;
         $returned = $Held() - $held;
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
         $inputEvidence = json_encode([
            'captured' => $captured === $price, 'raw' => $input === $document, 'thrown' => $thrown,
            'input moved' => $inputMoved, 'refused' => $refused, 'fields moved' => $fieldsMoved,
            'unbuilt' => $unbuilt, 'returned' => $returned,
         ]);

         yield assert(
            assertion: $captured === $price
               && $input === $document
               && $thrown === ''
               && $inputMoved === 0
               && $refused === Bodies::REFUSAL
               && $fieldsMoved === 0
               && $unbuilt
               && $returned === 0,
            description: "Reading the raw input of a snapshot decodes nothing and moves nothing; its fields are still refused: {$inputEvidence}",
         );

         // # Credentials parsed on a snapshot are charged exactly: both Basic
         //   strings plus the decoded credentials (`CREDENTIALS`, 128), and
         //   the bearer token's string
         $Credit = static function (string $wire, Closure $Parse) use ($Decode, $Scans, $Held): array {
            $Live = $Decode($wire);
            $Scans->setValue(null, []);
            $Snapshot = $Live->capture();
            $Live->shed();
            $held = $Held();
            $value = $Parse($Snapshot);
            $moved = $Held() - $held;
            $Snapshot->clean();

            // :
            return [$value, $moved];
         };
         [$named, $username] = $Credit($wires['username'], static fn (Request $Snapshot): string => $Snapshot->username);
         [$secreted, $password] = $Credit($wires['password'], static fn (Request $Snapshot): string => $Snapshot->password);
         [$tokened, $token] = $Credit($wires['token'], static fn (Request $Snapshot): string => $Snapshot->token);
         $expected = [
            'username' => Buffers::weigh(strlen($user)) + Buffers::weigh(1) + 128,
            'password' => Buffers::weigh(1) + Buffers::weigh(strlen($secret)) + 128,
            'token' => Buffers::weigh(strlen($bearer)),
         ];
         $credited = ['username' => $username, 'password' => $password, 'token' => $token];
         $creditEvidence = json_encode(['moved' => $credited, 'expected' => $expected]);

         yield assert(
            assertion: $credited === $expected
               && $named === $user
               && $secreted === $secret
               && $tokened === $bearer,
            description: "A Basic parse on a snapshot is charged both strings and 128, a bearer token its string: {$creditEvidence}",
         );

         // # A refused Basic parse leaves the credentials unparsed: once the
         //   share makes room, a later read parses them
         $Live = $Decode($wires['username']);
         $Scans->setValue(null, []);
         $held = $Held();
         $price = $Price($Live);
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $price);
         $Snapshot = $Live->capture();
         $Live->shed();
         $captured = $Held() - $held;
         $refused = '';
         try {
            $Snapshot->username;
         }
         catch (RuntimeException $Exception) {
            $refused = $Exception->getMessage();
         }
         $refusedMoved = $Held() - $held - $captured;
         $parsed = $Raw($Snapshot, 'authParsed');
         // @ Room is made: the share is raised
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
         $later = '';
         $thrown = '';
         try {
            $later = $Snapshot->username;
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable->getMessage();
         }
         $laterMoved = $Held() - $held - $captured;
         $Snapshot->clean();
         $Snapshot = null;
         $retryEvidence = json_encode([
            'captured' => $captured === $price, 'refused' => $refused, 'refused moved' => $refusedMoved,
            'authParsed' => $parsed, 'later' => $later === $user, 'thrown' => $thrown,
            'later moved' => $laterMoved, 'expected' => $expected['username'],
         ]);

         yield assert(
            assertion: $captured === $price
               && $refused === Bodies::REFUSAL
               && $refusedMoved === 0
               && $parsed === false
               && $later === $user
               && $thrown === ''
               && $laterMoved === $expected['username'],
            description: "A refused Basic parse leaves it unparsed, and a read once there is room returns the username: {$retryEvidence}",
         );

         // # A refused languages parse keeps the refusals parsed before it
         $Live = $Decode($Shapes['languages']);
         // ! Refusals the route parsed before capture(): fewer than the
         //   header's, so a parse on the snapshot grows them
         $previous = ['aa', 'bb'];
         new ReflectionProperty(Request::class, 'exclusions')->setValue($Live, $previous);
         $Scans->setValue(null, []);
         $held = $Held();
         $price = $Price($Live);
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * ($held + $price);
         $Snapshot = $Live->capture();
         $Live->shed();
         $captured = $Held() - $held;
         $refused = '';
         try {
            $Snapshot->languages;
         }
         catch (RuntimeException $Exception) {
            $refused = $Exception->getMessage();
         }
         $refusedMoved = $Held() - $held - $captured;
         $kept = $Snapshot->exclusions;
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
         $Snapshot->languages;
         $grown = $Held() - $held - $captured;
         $growth = Buffers::tally($Snapshot->exclusions) - Buffers::tally($previous);
         $refusals = count($Snapshot->exclusions);
         $Snapshot->clean();
         $Snapshot = null;
         $languagesEvidence = json_encode([
            'captured' => $captured === $price, 'refused' => $refused, 'refused moved' => $refusedMoved,
            'kept' => $kept, 'grown' => $grown, 'growth' => $growth, 'exclusions' => $refusals,
         ]);

         yield assert(
            assertion: $captured === $price
               && $refused === Bodies::REFUSAL
               && $refusedMoved === 0
               && $kept === $previous
               && $grown === $growth
               && $growth > 0
               && $refusals === 1500,
            description: "A refused languages parse keeps the refusals parsed before it, and a read once there is room pays its growth: {$languagesEvidence}",
         );

         // # Sparse integer keys: an array whose first key is `0` to `7`
         //   begins as a list, grows while over half full and turns into a
         //   map of twice its slots when full — 19 keys `0`..`16`, `63`, `64`
         //   keep 128 slots (5,120 bytes, two pages) where their count
         //   suggests 32. Body fields, query vars and cookies of that shape
         //   are charged what the snapshot keeps (`Buffers\Table`)
         $indexes = [...range(0, 16), 63, 64];
         // ! 1,000 JSON objects of those keys: 125,001 bytes that decode into
         //   ~8.27 MB — a few snapshots are enough
         $members = implode(',', array_map(static fn (int $index): string => "\"{$index}\":0", $indexes));
         $objects = implode(',', array_fill(0, 1_000, "{{$members}}"));
         // ! 52 query arrays of those keys: 988 vars, under `max_input_vars`
         $alphabet = "{$letters}ABCDEFGHIJKLMNOPQRSTUVWXYZ";
         $arrays = [];
         for ($index = 0; $index < 52; $index++) {
            foreach ($indexes as $key) {
               $arrays[] = "{$alphabet[$index]}[{$key}]=v";
            }
         }
         $sparse = implode('&', $arrays);
         // ! 40 Cookie lines of those names: each line parses into one array
         $crumbs = implode('; ', array_map(static fn (int $index): string => "{$index}={$letters[$index % 26]}", $indexes));
         $jar = str_repeat("Cookie: {$crumbs}\r\n", 40);
         $Integers = [
            'JSON objects' => [$Post('application/json', "[{$objects}]"), true, $Read, 4],
            'query arrays' => [
               "GET /q?{$sparse} HTTP/1.1\r\nHost: s\r\n\r\n", false,
               static fn (Request $Snapshot): array => $Snapshot->queries, null,
            ],
            'cookie lines' => [
               "GET / HTTP/1.1\r\nHost: s\r\n{$jar}\r\n", false,
               static fn (Request $Snapshot): array => $Snapshot->cookies, null,
            ],
         ];
         $rows = [];
         foreach ($Integers as $shape => [$wire, $body, $Work, $copies]) {
            // ! One round outside the measure: first-time allocations land there
            $Gauge($wire, $body, $Idle, $Work, $copies);
            $rows[$shape] = $Gauge($wire, $body, $Idle, $Work, $copies);
         }
         // ? Each shape kept what it was built for — never vacuous: the
         //   JSON objects ~8.27 MB, the query arrays ~433 KB, the cookie
         //   lines 40 maps of 128 slots
         $least = ['JSON objects' => 8_000_000, 'query arrays' => 400_000, 'cookie lines' => 320_000];
         $failed = array_filter(
            $rows,
            static fn (array $row, string $shape): bool => $Within($row) === false
               || $row['kept'] < $least[$shape]
               || ($shape === 'JSON objects' && $row['charged'] > 1.2 * $row['kept']),
            ARRAY_FILTER_USE_BOTH
         );
         $evidence = json_encode($rows);

         yield assert(
            assertion: $failed === [],
            description: "Sparse integer keys — JSON objects, query arrays, cookie names — are charged what their tables keep (JSON within 1.2 times it), not the table their count suggests: {$evidence}",
         );
      }
      finally {
         TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
         $Scans->setValue(null, $scans);
      }
   },
);
