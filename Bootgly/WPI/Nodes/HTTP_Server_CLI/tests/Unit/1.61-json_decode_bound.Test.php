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
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * H-HSC-5 — `Request::check()` refuses a JSON body whose decode could not fit
 * the bodies share of the worker memory budget (half of
 * `maxWorkerPendingBytes`); the encoders answer it `413` (Unit 1.60).
 *
 * `json_decode()` of `[[0],[0],…]` spends about fifty times the body's bytes,
 * so a 1 MB body killed a 128M worker before any handler could refuse it.
 * `check()` decides in tiers:
 *
 * - invalid JSON (`json_validate()`) passes: `input()` validates first and
 *   decodes nothing from it, so NDJSON, JSON text sequences, concatenated or
 *   compressed bodies labelled `json` build nothing;
 * - valid JSON is reduced to its shape: escaped backslashes, then escaped
 *   quotes, become `__` — two bytes that are no digit — so every quote left
 *   delimits a string and a key that held one is never kept (R6: deleted,
 *   `"\\5"` read as the index 5); one possessive pass — no backtracking, no
 *   `ini_set()` — empties every string to `""` but keeps a key of up to 20
 *   digits and minus signs, escaped or not (PHP may read it as an index; a
 *   longer one never is); a PCRE failure refuses the body;
 * - the quick bound, `price()` — `CONTAINER` (400) per `[`/`{`, `MEMBER`
 *   (128) per `:`, `ELEMENT` (64) per other value, `STRING` (48) per string,
 *   twice the string content, once the shape — accepts at or under the
 *   share. It is skipped when an object's first key is `0`…`7`: PHP begins
 *   such an object as a packed list and may convert it to a map of twice its
 *   slots, which `price()` does not bound;
 * - the lower bound — `max(216 × filled, 72 × filled + 16 × commas)`,
 *   filled = containers minus the empty ones — refuses over the share. It is
 *   a floor of the walk: it spares the walk and never changes a verdict;
 * - else `walk()`: each non-empty container at 56 + `Buffers::weigh()` of
 *   its table less 25. An object whose first key is kept follows its keys
 *   through a `Buffers\Table` (packed list, growth, a repeated index that
 *   replaces its value, conversion to a map), and a key PHP reads as an
 *   index is no string; other objects take 40 B a slot, lists 16 B a slot +
 *   8, 8 slots doubling to fit. Strings and bytes count as in `price()`.
 *
 * `price()` and `walk()` are called through reflection; the shape, the
 * quick-bound gate and the lower bound are mirrored (they are inline in
 * `check()`). Pinned:
 *
 * - a body that is not JSON passes whatever its size or the budget;
 * - ~1.2 MB of `[[0],…]` or `[{"":0},…]` is refused at 64 MiB and accepted
 *   at 512 MiB;
 * - brackets, commas and colons inside a string are string bytes (19-byte
 *   body: price 655, walk 503, decided at 503);
 * - for every shape — page and size-class edges, and integer keys: sparse
 *   (`0`…`16`, `63`, `64`, plain and `\u`-escaped; with a repeated `0`
 *   before `63`, `64` or `63`, `127`, or a repeat in the middle — R6;
 *   pretty-printed, a space before each colon, `[]`, `{}`, `[0]` or
 *   `{"0":0}` values), from `1`, 1-based, from `5` on, negative (the minus
 *   plain or escaped), a hole filled, a name after indexes, timestamps,
 *   numeric strings, repeated; keys holding an escaped backslash or quote
 *   next to digits, first or later, sparse or dense (R6: never an index) —
 *   the walk is at or over the real decode, the quick bound too wherever it
 *   applies, the lower bound at or under the walk (and the real decode,
 *   keys unique); `check()` refuses one byte below the real decode and
 *   accepts at the walk; at the lower bound, one byte below it, the walk and
 *   one byte below it, its verdict is the quick bound's or the walk's (the
 *   lower bound changes none);
 * - the walk is tight: within 1.6 × the real decode where containers or
 *   indexes dominate (1.143–1.417 measured), 2.25 × where short strings do
 *   (1.966);
 * - at the defaults bulk records (walk tier), tabular rows, ~2 MB of
 *   numbers, ~1 MB of JSON in strings, a ~1 MB API payload and ~3 MB of
 *   empty containers (whitespace inside or not) pass; 1.16 MB of `[[0],…]`
 *   does not;
 * - invalid JSON — 2.5 MB of NDJSON, a JSON text sequence, concatenated
 *   values, gzip, unterminated `[[0],…` — passes at any budget, and
 *   `input()` returns `[]` without building it (the unterminated `[[0],…`
 *   peaks under 1 MiB: 71 MB decoded without the check);
 * - escapes cost the string pass no backtracking: 1 MB of unterminated
 *   escaped quotes passes in under 2 s (`pcre.jit` 0); with
 *   `pcre.backtrack_limit` at 1,000 a valid 3 MB string of escapes, ~2 MB of
 *   escape-dense strings and 3 MB of `\n[` escapes pass; at 1, PCRE fails
 *   and a small valid body is refused; a child decides ~10 MB of
 *   escape-dense strings well within its 20 s deadline;
 * - a run of digits costs the pass no budget either: a child without PCRE
 *   JIT, at the default `pcre.backtrack_limit`, passes a value or a key of
 *   1,000,001 digits and a key of 166,667 escaped digits;
 * - `check()` leaves `pcre.backtrack_limit` as it found it, and runs with
 *   `ini_set` and `ini_get` disabled (a child);
 * - integer keys at the defaults: the R5 attack (13,783 sparse objects,
 *   1,722,876 bytes: real 113,948,480, walk 116,650,537), the R6 repeat
 *   attack (22,142 objects of `0`, `0`, `1`…`16`, `63`, `127`, 2,922,745
 *   bytes: real 183,155,656, walk 188,037,823) and the R6 escaped-key
 *   attack (9,992 objects of `\\0`…`\\64`, 5,765,385 bytes: real
 *   103,463,672, walk 122,851,503) are refused — built, decoded and checked
 *   in a child (`memory_limit` 1G); 1,000 sparse objects pass (real
 *   8,268,536), 6,000 — plain or escaped — are refused though the quick
 *   bound prices them at 23.6 / 24.4 MB (real 49,623,224, walk 50.8 MB);
 *   20,000 1-based arrays of 10 strings pass (real 14,448,440, walk
 *   20,468,633), 50,000 do not (real 35,852,728);
 * - the walk boundary: a walk of exactly the share passes, one byte or one
 *   value more is refused.
 *
 * The spec runs within `memory_limit` 128M (≈ 65 MB peak): no body is
 * decoded in-process past ~50 MB.
 */
return new Test(
   description: 'H-HSC-5: a JSON body is refused when its decode bound exceeds the bodies share',
   test: new Assertions(Case: function (): Generator {
      // ! A request carrying `$body` under `$type`
      $Build = static function (null|string $type, string $body): Request {
         $Request = new Request;
         $Request->reset();
         $Request->Header->adopt($type === null ? [] : ['content-type' => $type]);
         $Request->Body->raw = $body;
         $Request->Body->length = strlen($body);

         return $Request;
      };
      // ! A JSON list of `$count` copies of `$item`, after `$pad` spaces
      $List = static function (int $count, string $item, int $pad = 0): string {
         $space = str_repeat(' ', $pad);
         $items = implode(',', array_fill(0, $count, $item));

         return "[{$space}{$items}]";
      };
      // ! A JSON object of `$count` short keys (`k0`, `k1`, …), each `$value`
      $Object = static function (int $count, string $value = '0'): string {
         $members = [];
         for ($index = 0; $index < $count; $index++) {
            $members[] = "\"k{$index}\":{$value}";
         }
         $joined = implode(',', $members);

         return "{{$joined}}";
      };
      // ! A JSON object of the keys `$keys` (raw JSON text), in order, each
      //   `$value`, each `$colon` between
      $Keyed = static function (array $keys, string $value = '0', string $colon = ':'): string {
         $members = [];
         foreach ($keys as $key) {
            $members[] = "\"{$key}\"{$colon}{$value}";
         }
         $joined = implode(',', $members);

         return "{{$joined}}";
      };
      // ! A key spelled in `\u` escapes, one per byte
      $Escape = static function (int|string $key): string {
         return implode('', array_map(
            static fn (string $char): string => sprintf('\\u%04x', ord($char)),
            str_split((string) $key)
         ));
      };
      // ! The memory the decoded structure keeps (held alive while measured)
      $Measure = static function (string $body): int {
         $before = memory_get_usage();
         $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
         $real = memory_get_usage() - $before;
         unset($decoded);

         return $real;
      };
      // ! The tiers' own prices (private: reflection)
      $Price = new ReflectionMethod(Request::class, 'price');
      $Walk = new ReflectionMethod(Request::class, 'walk');
      // ! Mirror: the shape — escaped backslashes, then escaped quotes, made
      //   `__` (no digit: a key that held one is never kept), every string
      //   emptied but a key of up to 20 digits and minus signs — and the
      //   string content bytes
      $Split = static function (string $body): array {
         $shape = strpos($body, '\\') === false
            ? $body
            : str_replace(['\\\\', '\\"'], '__', $body);
         $shape = preg_replace(
            '/"((?:[0-9-]|\\\\u00(?:3[0-9]|2[dD])){1,20}+)"(?=\\s*+:)|"[^"]*+"/',
            '"$1"',
            $shape
         );
         if ($shape === null) {
            throw new RuntimeException('The shape mirror gave up on a body');
         }

         return [$shape, strlen($body) - strlen($shape)];
      };
      // ! Mirror: the quick bound applies — no object's first key is `0`…`7`
      $Quick = static function (string $shape): bool {
         return preg_match('/\\{\\s*+"(?:[0-7]|\\\\u003[0-7])"/', $shape) === 0;
      };
      // ! Mirror: the lower bound `check()` refuses over (216 / 72 / 16)
      $Least = static function (string $shape): int {
         $containers = substr_count($shape, '[') + substr_count($shape, '{');
         $filled = $containers - (int) preg_match_all('/\\[\\s*+\\]|\\{\\s*+\\}/', $shape);

         return max(216 * $filled, 72 * $filled + 16 * substr_count($shape, ','));
      };
      // ! The peak memory `input()` reaches past what is already held
      $Peak = static function (Request $Request): array {
         $Request->Body->input = $Request->Body->raw;
         gc_collect_cycles();
         memory_reset_peak_usage();
         $before = memory_get_usage();
         $inputs = $Request->input();
         $peak = memory_get_peak_usage() - $before;

         return [$inputs, $peak];
      };

      // ! Bodies
      $Record = static function (int $index): string {
         return (string) json_encode([
            'id' => $index,
            'name' => "User {$index}",
            'email' => "user{$index}@example.com",
            'active' => true,
            'tags' => ['admin', 'beta'],
            'score' => 12.5,
            'address' => ['city' => 'Lisbon', 'zip' => '1000-001'],
         ]);
      };
      $Payload = static function (int $count) use ($Record): string {
         $records = [];
         for ($index = 0; $index < $count; $index++) {
            $records[] = $Record($index);
         }
         $joined = implode(',', $records);

         return "[{$joined}]";
      };
      // # A paginated API answer: records nesting objects three deep
      $Nested = static function (int $count): string {
         $items = [];
         for ($index = 0; $index < $count; $index++) {
            $items[] = [
               'id' => $index,
               'user' => [
                  'id' => $index * 7,
                  'name' => "User {$index}",
                  'profile' => [
                     'locale' => 'pt-BR',
                     'roles' => ['editor'],
                     'settings' => ['theme' => 'dark', 'beta' => false],
                  ],
               ],
               'meta' => ['created' => '2026-10-12T00:00:00Z', 'version' => 3],
            ];
         }

         return (string) json_encode([
            'data' => $items,
            'page' => ['next' => null, 'size' => $count],
         ]);
      };
      $Embedded = static function (int $count) use ($List): string {
         $inner = (string) json_encode([
            'a' => [1, 2, 3],
            'b' => ['c' => 'd', 'e' => [true, null]],
         ]);

         return $List($count, (string) json_encode($inner));
      };
      $Dense = static function (int $count): string {
         $numbers = [];
         for ($index = 0; $index < $count; $index++) {
            $numbers[] = 1_000 + $index % 9_000;
         }
         $joined = implode(',', $numbers);

         return "[{$joined}]";
      };
      // # Small records (`{"id":N,"v":2}`) and tabular rows (`[N,1.5,"a",true]`)
      $Bulk = static function (int $count): string {
         $records = [];
         for ($index = 0; $index < $count; $index++) {
            $records[] = "{\"id\":{$index},\"v\":2}";
         }
         $joined = implode(',', $records);

         return "[{$joined}]";
      };
      $Rows = static function (int $count): string {
         $rows = [];
         for ($index = 0; $index < $count; $index++) {
            $rows[] = "[{$index},1.5,\"a\",true]";
         }
         $joined = implode(',', $rows);

         return "[{$joined}]";
      };
      // # PHP's `json_encode()` of 1-based arrays (`[1 => 'v1', …, 10 => 'v10']`)
      $Based = static function (int $count): string {
         $row = [];
         for ($index = 1; $index <= 10; $index++) {
            $row[$index] = "v{$index}";
         }

         return (string) json_encode(array_fill(0, $count, $row));
      };
      // # Objects of the sparse keys `0`…`16`, `63`, `64` (R5): PHP begins each as
      //   a list, grows it to 64 slots for `63` and turns it into a map of 128
      //   for `64` — 5,120 bytes of table, two pages (8,248 B decoded)
      $sparse = [...range(0, 16), 63, 64];
      $Sparse = static function (int $count, bool $escaped = false) use ($List, $Keyed, $Escape, $sparse): string {
         $keys = $escaped ? array_map($Escape, $sparse) : $sparse;

         return $List($count, $Keyed($keys));
      };
      $Quote = static function (string $text): string {
         return "\"{$text}\"";
      };
      // # A JSON string riddled with escapes: `\"`, `\\`, `\n`, `\t`, `\/`,
      //   `\u00e9` and a surrogate pair (126 bytes)
      $riddled = (string) json_encode(str_repeat("a\"b\\c\n\t/\u{E9}\u{1F600}", 4));
      // # A small JSON body
      $small = '{"name":"bootgly","tags":["http","cli"],"version":1}';

      // ! A child PHP booting this tree (a scrubbed environment, `-d`
      //   `$settings`, `pcre.jit` and `opcache.jit` off) runs `$code` with
      //   `$Check($body)` at the default budget; still running at `$seconds`,
      //   it is killed: [finished, exit code, stdout, stderr]
      $root = BOOTGLY_ROOT_DIR;
      $prologue = strtr(<<<'PHP'
         use Bootgly\WPI\Interfaces\TCP_Server_CLI;
         use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

         $_SERVER['SCRIPT_FILENAME'] = '';
         require AUTOBOOT;
         TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
         $Check = static function (string $body): bool {
            $Request = new Request;
            $Request->reset();
            $Request->Header->adopt(['content-type' => 'application/json']);
            $Request->Body->raw = $body;
            $Request->Body->length = strlen($body);

            return $Request->check();
         };
         PHP, ['AUTOBOOT' => var_export("{$root}autoboot.php", true)]);
      $Run = static function (string $code, array $settings, int $seconds) use ($prologue): array {
         $command = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'pcre.jit=0'];
         foreach ($settings as $name => $value) {
            $command[] = '-d';
            $command[] = "{$name}={$value}";
         }
         $command[] = '-r';
         $command[] = "{$prologue}\n{$code}";
         $Process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')]
         );
         // ?
         if ($Process === false) {
            return [false, -1, '', 'proc_open() failed'];
         }
         fclose($pipes[0]);
         stream_set_blocking($pipes[1], false);
         stream_set_blocking($pipes[2], false);
         $outputs = [1 => '', 2 => ''];
         $deadline = hrtime(true) + $seconds * 1_000_000_000;
         $status = proc_get_status($Process);

         // @@ Read until it exits or the deadline passes
         while ($status['running'] && hrtime(true) < $deadline) {
            $read = [1 => $pipes[1], 2 => $pipes[2]];
            $write = null;
            $except = null;
            if ((int) stream_select($read, $write, $except, 0, 100_000) > 0) {
               foreach ($read as $index => $pipe) {
                  $outputs[$index] .= (string) fread($pipe, 65_536);
               }
            }
            $status = proc_get_status($Process);
         }
         $finished = $status['running'] === false;
         // ? Past the deadline: kill it
         if ($finished === false) {
            proc_terminate($Process, 9);
         }
         foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], true);
            $outputs[$index] .= (string) stream_get_contents($pipes[$index]);
            fclose($pipes[$index]);
         }
         proc_close($Process);

         return [$finished, $finished ? $status['exitcode'] : -1, $outputs[1], $outputs[2]];
      };

      $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
      $JIT = ini_get('pcre.jit');
      $backtrack = ini_get('pcre.backtrack_limit');
      $default = 64 * 1024 * 1024;
      $share = intdiv($default, 2);

      try {
         // ! Without JIT, as R4 measured: the string pass at its slowest
         ini_set('pcre.jit', '0');
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;

         // @@ 1) A body that is not JSON passes regardless of its size
         $brackets = str_repeat('[[0],', 240_000);
         $Others = [
            'urlencoded' => $Build('application/x-www-form-urlencoded', "a={$brackets}"),
            'multipart' => $Build(
               'multipart/form-data; boundary=x',
               "--x\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n{$brackets}\r\n--x--\r\n"
            ),
            'text' => $Build('text/plain', $brackets),
            'no type' => $Build(null, $brackets),
         ];
         $results = [];
         foreach ($Others as $name => $Request) {
            $results[$name] = [$Request->check()];
         }
         TCP_Server_CLI::$maxWorkerPendingBytes = 2;
         foreach ($Others as $name => $Request) {
            $results[$name][] = $Request->check();
         }
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;
         unset($Others, $brackets);

         yield new Assertion(
            description: 'A body that is not JSON passes at any size and any budget',
         )
            ->expect($results)
            ->to->be([
               'urlencoded' => [true, true],
               'multipart' => [true, true],
               'text' => [true, true],
               'no type' => [true, true],
            ])
            ->assert();

         // @@ 2) A JSON body whose decode fits passes; a `+json` type is JSON
         $Small = $Build('application/json; charset=utf-8', $small);
         $Vendor = $Build('application/vnd.api+json', $small);
         $fits = [$Small->check(), $Vendor->check()];
         TCP_Server_CLI::$maxWorkerPendingBytes = 2;
         $tiny = [$Small->check(), $Vendor->check()];
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;

         yield new Assertion(
            description: 'A JSON body that fits passes, and a +json type is checked as JSON',
         )
            ->expect([$fits, $tiny])
            ->to->be([[true, true], [false, false]])
            ->assert();

         // @@ 3) ~1.2 MB amplifiers: refused at the default, accepted when raised
         $lists = $List(300_000, '[0]');
         $objects = $List(171_428, '{"":0}');
         $Lists = $Build('application/json', $lists);
         $Objects = $Build('application/json', $objects);
         $refused = [$Lists->check(), $Objects->check()];
         TCP_Server_CLI::$maxWorkerPendingBytes = 512 * 1024 * 1024;
         $raised = [$Lists->check(), $Objects->check()];
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;
         $sizes = json_encode([strlen($lists), strlen($objects)]);
         unset($lists, $objects, $Lists, $Objects);

         yield new Assertion(
            description: '~1.2 MB of [[0],…] or [{"":0},…] is refused at 64 MiB and accepted at 512 MiB',
            fallback: "Bytes: {$sizes}"
         )
            ->expect([$refused, $raised])
            ->to->be([[false, false], [true, true]])
            ->assert();

         // @@ 4) Structure inside a string is string content
         //   `{"q":"a\"{[,:]}\\"}` (19 bytes, valid) has the shape `{"":""}`
         //   (7 bytes: the escapes go first, then both strings empty) and 12
         //   content bytes (`q` and `a\"{[,:]}\\`):
         //   price = 400 (one `{`) + 128 (one `:`) + 0 elements (1 value −
         //     1 member) + 2 × 48 (two strings) + 2 × 12 + 7 = 655;
         //   lower bound = max(216 × 1, 72 × 1 + 16 × 0) = 216;
         //   walk = 2 × 48 + 2 × 12 + 7 + the one object: 8 slots × 40 = 320,
         //     56 + weigh(320 − 25 = 295) = 56 + 320 → 127 + 376 = 503.
         //   Share 655: the price passes it; share 503: the walk passes it;
         //   share 502: price over, bound under, walk over → refused
         $quoted = (string) json_encode(['q' => 'a"{[,:]}\\']);
         $Quoted = $Build('application/json', $quoted);
         [$shape, $content] = $Split($quoted);
         $verdicts = [];
         foreach ([655, 503, 502] as $half) {
            TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $half;
            $verdicts[$half] = $Quoted->check();
         }
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;
         $prices = [
            strlen($quoted),
            $shape,
            $Price->invoke(null, $shape, $content),
            $Least($shape),
            $Walk->invoke(null, $shape, $content),
         ];
         $evidence = json_encode($prices);

         yield new Assertion(
            description: 'Brackets, commas and colons inside a string (escaped quotes included) count as string bytes',
            fallback: "Body {$quoted}: bytes, shape, price, lower bound, walk {$evidence}"
         )
            ->expect([$prices, $verdicts])
            ->to->be([
               [19, '{"":""}', 655, 216, 503],
               [655 => true, 503 => true, 502 => false],
            ])
            ->assert();
         unset($quoted, $Quoted);

         // @@ 5) The tiers against what the decode really keeps
         //   Bodies are built one at a time (closures), each decoded and kept
         //   alive while measured: none past ~33 MB (the spec runs within
         //   `memory_limit` 128M; the attacks are decoded in a child, § 10)
         $GraphQL = static function (): string {
            return (string) json_encode([
               'operationName' => 'Feed',
               'query' => 'query Feed($id: ID!, $first: Int = 10, $after: String) {
                  user(id: $id) { id name avatar(size: 64)
                     friends(first: $first, after: $after) {
                        edges { cursor node { id name status { text emoji } } }
                        pageInfo { hasNextPage endCursor }
                     }
                  }
               }',
               'variables' => ['id' => 'VXNlcjox', 'first' => 20, 'after' => null],
            ]);
         };
         $Shapes = [
            'nested lists' => static fn (): string => $List(50_000, '[0]'),
            'empty lists' => static fn (): string => $List(100_000, '[]'),
            'short keys' => static fn (): string => $List(30_000, '{"a1":0,"b2":1,"c3":2}'),
            'wide object' => static fn (): string => $Object(32_769),
            // # Hash tables just past a page (65 members: 128 × 40 B → 8 KiB)
            'objects of 9 keys' => static fn (): string => $List(10_000, $Object(9)),
            'objects of 65 keys' => static fn (): string => $List(3_076, $Object(65)),
            'objects of 70 keys' => static fn (): string => $List(2_857, $Object(70)),
            'objects of 80 keys' => static fn (): string => $List(2_500, $Object(80)),
            'objects of 129 keys' => static fn (): string => $List(1_550, $Object(129)),
            'objects of 257 keys' => static fn (): string => $List(778, $Object(257)),
            'objects of 65 long strings' => static fn (): string => $List(
               4,
               $Object(65, $Quote(str_repeat('s', 4_072)))
            ),
            // # Packed lists just past a page (129 elements: 256 × 16 + 8 B → 8 KiB)
            'lists of 9 digits' => static fn (): string => $List(10_000, $List(9, '0')),
            'lists of 129 digits' => static fn (): string => $List(3_892, $List(129, '0')),
            'lists of 257 digits' => static fn (): string => $List(1_949, $List(257, '0')),
            'lists of 513 digits' => static fn (): string => $List(976, $List(513, '0')),
            'lists of 1025 digits' => static fn (): string => $List(488, $List(1_025, '0')),
            // # Strings just past a size class (3,049 + 25 > 3,072) or a page (4,072 + 25 > 4,096)
            'strings of 3049 bytes' => static fn (): string => $List(129, $Quote(str_repeat('s', 3_049))),
            'strings of 3050 bytes' => static fn (): string => $List(129, $Quote(str_repeat('s', 3_050))),
            'strings of 4072 bytes' => static fn (): string => $List(129, $Quote(str_repeat('s', 4_072))),
            'strings of 4073 bytes' => static fn (): string => $List(129, $Quote(str_repeat('s', 4_073))),
            'strings of 4074 bytes' => static fn (): string => $List(129, $Quote(str_repeat('s', 4_074))),
            'strings of 4075 bytes' => static fn (): string => $List(129, $Quote(str_repeat('s', 4_075))),
            'JSON in strings' => static fn (): string => $Embedded(30_000),
            'dense digits' => static fn (): string => $List(500_000, '0'),
            'dense numbers' => static fn (): string => $Dense(400_000),
            'numbers' => static fn (): string => $List(1, implode(',', range(0, 65_536))),
            'base64 strings' => static fn (): string => $List(
               1_000,
               $Quote(base64_encode(str_repeat("\x9C\x00\xFF", 250)))
            ),
            'base64 field' => static fn (): string => (string) json_encode([
               'name' => 'upload.bin',
               'content' => base64_encode(str_repeat("\x9C\x00\xFF", 250_000)),
            ]),
            'escape-dense strings' => static fn (): string => $List(16_000, $riddled),
            'deep nesting' => static function () use ($List): string {
               $opening = str_repeat('[', 200);
               $closing = str_repeat(']', 200);

               return $List(500, "{$opening}0{$closing}");
            },
            'bulk records' => static fn (): string => $Bulk(60_000),
            'tabular rows' => static fn (): string => $Rows(40_000),
            'API payload' => static fn (): string => $Payload(6_500),
            'nested API objects' => static fn (): string => $Nested(5_000),
            'GraphQL body' => $GraphQL,
            // # Integer keys (R5): objects PHP may begin as a list
            'sparse keys' => static fn (): string => $Sparse(1_000),
            'escaped sparse keys' => static fn (): string => $Sparse(1_000, escaped: true),
            // # A repeated index replaces its value: the list keeps growing
            //   to 64 slots and turns into a map of 128 (R6: priced as a
            //   hole, a map of 32 — a fifth of it)
            'sparse keys, a repeated 0' => static fn (): string => $List(
               1_000,
               $Keyed([0, ...$sparse])
            ),
            'sparse keys to 127, a repeated 0 (R6)' => static fn (): string => $List(
               1_000,
               $Keyed([0, 0, ...range(1, 16), 63, 127])
            ),
            'sparse keys, a repeat in the middle' => static fn (): string => $List(
               1_000,
               $Keyed([...range(0, 8), 4, ...range(9, 16), 63, 64])
            ),
            // # Whitespace before the first key or a colon, and values that are
            //   containers: the keys are still followed
            'sparse keys, pretty-printed' => static fn (): string => (string) json_encode(
               array_fill(0, 1_000, array_fill_keys($sparse, 0)),
               JSON_PRETTY_PRINT
            ),
            'sparse keys, a space before each colon' => static fn (): string => $List(
               1_000,
               $Keyed($sparse, colon: ' :')
            ),
            'sparse keys of []' => static fn (): string => $List(1_000, $Keyed($sparse, '[]')),
            'sparse keys of {}' => static fn (): string => $List(1_000, $Keyed($sparse, '{}')),
            'sparse keys of [0]' => static fn (): string => $List(1_000, $Keyed($sparse, '[0]')),
            'sparse keys of {"0":0}' => static fn (): string => $List(500, $Keyed($sparse, '{"0":0}')),
            // # A first key from `1` also begins a list
            'sparse keys from 1' => static fn (): string => $List(1_000, $Keyed([...range(1, 17), 63, 64])),
            'escaped sparse keys from 1' => static fn (): string => $List(
               1_000,
               $Keyed(array_map($Escape, [...range(1, 17), 63, 64]))
            ),
            // # Keys holding an escaped backslash or quote next to digits are
            //   strings, a map from the first (R6: read as indexes, priced as
            //   a list whose keys cost nothing)
            'keys escaping a backslash or quote, first' => static function () use ($List, $Keyed): string {
               $objects = [];
               foreach (['\\\\0', '\\"0', '0\\\\', '1\\"'] as $key) {
                  $objects[] = $Keyed([$key, ...range(1, 16), 63, 64]);
               }

               return $List(250, implode(',', $objects));
            },
            'keys escaping a backslash or quote, later' => static function () use ($List, $Keyed): string {
               $objects = [];
               foreach (['\\\\0', '\\"0', '0\\\\', '1\\"'] as $key) {
                  $objects[] = $Keyed([0, $key, ...range(2, 16), 63, 64]);
               }

               return $List(250, implode(',', $objects));
            },
            'keys escaping a backslash (R6)' => static fn (): string => $List(
               300,
               $Keyed(array_map(static fn (int $key): string => "\\\\{$key}", range(0, 64)))
            ),
            'indexes, then a key escaping a backslash or quote' => static fn (): string => $List(
               500,
               implode(',', [
                  $Keyed([...range(0, 15), '\\\\16']),
                  $Keyed([...range(0, 15), '16\\"']),
               ])
            ),
            // # `-1` after `0`, `7`: the full list doubles into a map of 16
            'a minus key' => static fn (): string => $List(1_000, $Keyed([0, 7, -1])),
            'an escaped minus key' => static function () use ($List, $Keyed, $Escape): string {
               $minus = $Escape('-');

               return $List(1_000, $Keyed([0, 7, "{$minus}1"]));
            },
            'an escaped minus key, uppercase' => static function () use ($List, $Keyed): string {
               $minus = sprintf('\\u%04X', ord('-'));

               return $List(1_000, $Keyed([0, 7, "{$minus}1"]));
            },
            '1-based arrays' => static fn (): string => $Based(20_000),
            // # `5`, `6`, `7` fill the list; `8` turns it into a map of 16 slots
            'keys from 5' => static fn (): string => $List(10_000, $Keyed(range(5, 20))),
            'negative keys after 0' => static fn (): string => $List(
               10_000,
               $Keyed([0, ...range(-1, -12)])
            ),
            'hole filled' => static fn (): string => $List(20_000, $Keyed([0, 2, 1])),
            'name after indexes' => static fn (): string => $List(10_000, $Keyed([0, 1, 2, 'name', 3, 4])),
            'timestamp keys' => static fn (): string => $List(
               2_000,
               $Keyed(range(1_700_000_000, 1_700_000_019))
            ),
            // # `01`, `-0` and an index past PHP_INT_MAX stay string keys
            'numeric strings' => static fn (): string => $List(
               10_000,
               $Keyed(['01', '-0', '9223372036854775808', 0, 1])
            ),
            'numeric strings after 0' => static fn (): string => $List(
               10_000,
               $Keyed([0, '01', '-0', '9223372036854775808', 1])
            ),
            'repeated indexes' => static fn (): string => $List(10_000, $Keyed([0, 0, 1, 1, 2, 2, 3, 3])),
         ];
         // # A repeated key is one value in PHP but one member to the bounds:
         //   the lower bound may pass the real decode there (never the walk)
         $Repeated = ['repeated indexes' => true];
         // # The walk's ceiling over the real decode. Measured (PHP 8.5):
         //   where containers or indexes dominate 1.143 (empty lists) to
         //   1.417 (1-based arrays — 2.081 when an index was priced as the
         //   48-byte string PHP never allocates); where short strings do
         //   1.966 (JSON in strings). Each ceiling keeps ≥ 12% margin over its
         //   largest ratio and stays under 1.5 × its smallest (1.143 × 1.5 =
         //   1.71 > 1.6; 1.966 × 1.5 = 2.95 > 2.25): a walk inflated by half
         //   breaks it
         $Ceilings = [
            'bulk records' => 1.6,
            'tabular rows' => 1.6,
            'API payload' => 1.6,
            'nested API objects' => 1.6,
            'dense numbers' => 1.6,
            'objects of 9 keys' => 1.6,
            'objects of 65 keys' => 1.6,
            'empty lists' => 1.6,
            '1-based arrays' => 1.6,
            'JSON in strings' => 2.25,
         ];
         $bounds = [];
         $under = [];
         $biased = [];
         $undecided = [];
         $loose = [];
         $measures = [];
         foreach ($Shapes as $name => $Shape) {
            $body = $Shape();
            $real = $Measure($body);
            [$shape, $content] = $Split($body);
            $quick = $Quick($shape);
            $price = $Price->invoke(null, $shape, $content);
            $walk = $Walk->invoke(null, $shape, $content);
            $least = $Least($shape);
            $Request = $Build('application/json', $body);
            // ! `check()` at the shares the tiers turn on
            $verdicts = [];
            foreach (array_unique([$real - 1, $least - 1, $least, $walk - 1, $walk]) as $half) {
               TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $half;
               $verdicts[$half] = $Request->check();
            }
            TCP_Server_CLI::$maxWorkerPendingBytes = $default;
            $measures[$name] = [
               'bytes' => strlen($body),
               'real' => $real,
               'price' => $quick ? $price : "{$price} (skipped)",
               'walk' => $walk,
               'least' => $least,
            ];

            // ? The walk at or over the real decode, the quick bound too where
            //   it applies; the lower bound at or under the walk — and under
            //   the real decode where keys are unique
            if (
               $walk < $real
               || ($quick && $price < $real)
               || $least > $walk
               || (isSet($Repeated[$name]) === false && $least > $real)
            ) {
               $bounds[] = $name;
            }
            // ? The share one byte below the real decode refuses the body
            if ($verdicts[$real - 1] !== false) {
               $under[] = $name;
            }
            // ? Every verdict is the quick bound's or the walk's: the lower
            //   bound only spares the walk
            foreach ($verdicts as $half => $verdict) {
               if ($verdict !== (($quick && $price <= $half) || $walk <= $half)) {
                  $biased[] = "{$name} @ {$half}";
               }
            }
            // ? The walk decides it: exactly the walk passes, one byte below is
            //   refused (where the quick bound does not already pass it)
            if (
               $verdicts[$walk] !== true
               || (($quick && $price <= $walk - 1) === false && $verdicts[$walk - 1] !== false)
            ) {
               $undecided[] = $name;
            }
            // ? Tight: within its ceiling over the real decode
            if (isSet($Ceilings[$name]) && $walk > $Ceilings[$name] * $real) {
               $loose[] = $name;
            }
            unset($body, $shape, $Request);
         }
         $evidence = json_encode($measures);
         $listed = array_map('json_encode', [$bounds, $under, $biased, $undecided, $loose]);

         yield new Assertion(
            description: 'For every shape: the walk (and the quick bound where it applies) at or over the real decode, the lower bound at or under the walk',
            fallback: "Shapes out of bounds: {$listed[0]} — {$evidence}"
         )
            ->expect($bounds)
            ->to->be([])
            ->assert();

         yield new Assertion(
            description: 'check() refuses every shape when the share is one byte below its real decode',
            fallback: "Shapes accepted below their real decode: {$listed[1]} — {$evidence}"
         )
            ->expect($under)
            ->to->be([])
            ->assert();

         yield new Assertion(
            description: 'At its lower bound, the walk and one byte below each, check() decides every shape as the quick bound and the walk would',
            fallback: "Verdicts the lower bound changed: {$listed[2]} — {$evidence}"
         )
            ->expect($biased)
            ->to->be([])
            ->assert();

         yield new Assertion(
            description: 'check() accepts every shape at its walk and refuses it one byte below',
            fallback: "Shapes not decided at their walk: {$listed[3]} — {$evidence}"
         )
            ->expect($undecided)
            ->to->be([])
            ->assert();

         yield new Assertion(
            description: 'The walk stays within 1.6 × the real decode where containers or indexes dominate, 2.25 × where short strings do',
            fallback: "Shapes over their ceiling: {$listed[4]} — {$evidence}"
         )
            ->expect($loose)
            ->to->be([])
            ->assert();
         unset($Shapes, $measures, $evidence);

         // @@ 6) Legit bodies pass at the defaults; the amplifier does not
         //   The bulk records are priced over the share (50.3 MB) and pass on
         //   their walk (31.7 MB, real 27.5 MB): R4's case of the single bound.
         //   Empty containers are the shared empty array: ~3 MB of them walk
         //   to ~20 MB, but counted as filled their lower bound is ~216 MB
         $Legit = [
            'bulk records' => $Bulk(60_000),
            'tabular rows' => $Rows(40_000),
            'dense numbers' => $Dense(400_000),
            'JSON in strings' => $Embedded(18_000),
            'API payload' => $Payload(6_500),
            'empty containers' => $List(500_000, '[],{}'),
            'empty containers, spaced' => $List(350_000, '[ ], { }'),
            'nested lists' => $List(290_000, '[0]'),
         ];
         $accepted = [];
         $legit = [];
         foreach ($Legit as $name => $body) {
            $accepted[$name] = $Build('application/json', $body)->check();
            $legit[$name] = strlen($body);
         }
         [$shape, $content] = $Split($Legit['bulk records']);
         $priced = $Price->invoke(null, $shape, $content) > $share;
         $sizes = [
            $legit['bulk records'] >= 1_000_000,
            $legit['dense numbers'] >= 2_000_000,
            $legit['JSON in strings'] >= 1_000_000,
            $legit['API payload'] >= 1_000_000,
            $legit['empty containers'] >= 3_000_000,
            $legit['empty containers, spaced'] >= 3_000_000,
            $legit['nested lists'] >= 1_160_000,
         ];
         $evidence = json_encode($legit);
         unset($Legit, $shape);

         yield new Assertion(
            description: 'Bulk records, tabular rows, ~2 MB of numbers, ~1 MB of JSON in strings, a ~1 MB API payload and ~3 MB of empty containers pass at the defaults; 1.16 MB of [[0],…] does not',
            fallback: "Bytes: {$evidence}"
         )
            ->expect([$sizes, $priced, $accepted])
            ->to->be([
               [true, true, true, true, true, true, true],
               true,
               [
                  'bulk records' => true,
                  'tabular rows' => true,
                  'dense numbers' => true,
                  'JSON in strings' => true,
                  'API payload' => true,
                  'empty containers' => true,
                  'empty containers, spaced' => true,
                  'nested lists' => false,
               ],
            ])
            ->assert();

         // @@ 6.1) Invalid JSON passes at any budget and builds nothing
         //   `input()` validates before it decodes: a failing `json_decode()`
         //   keeps what it parsed until it unwinds — ~71 MB for the
         //   unterminated `[[0],…` (R4), whose `input()` peak stays under
         //   1 MiB (the others fail within a few values either way) — and
         //   `check()` prices valid JSON only
         $lines = [];
         $sequence = [];
         for ($index = 0; $index < 20_000; $index++) {
            $lines[] = "{\"index\":{\"_index\":\"logs\",\"_id\":\"{$index}\"}}";
            $lines[] = (string) json_encode([
               'message' => "GET /items/{$index} served",
               'level' => 'info',
               'status' => 200,
               'ts' => 1_700_000_000 + $index,
            ]);
            $item = (string) json_encode(['id' => $index, 'tags' => [$index, $index + 1]]);
            $sequence[] = "\x1E{$item}\n";
         }
         $ndjson = implode("\n", $lines);
         $Invalid = [
            'NDJSON (Elasticsearch bulk)' => $Build('application/x-ndjson', "{$ndjson}\n"),
            'JSON text sequence' => $Build('application/json-seq', implode('', $sequence)),
            'concatenated values' => $Build(
               'application/json',
               str_repeat('[[0],[0],[0],[0],[0],[0],[0],[0],[0],[0]]', 40_000)
            ),
            'gzip' => $Build('application/json', (string) gzencode($ndjson, 9)),
            'unterminated [[0],…' => $Build('application/json', substr($List(290_000, '[0]'), 0, -1)),
         ];
         unset($lines, $sequence, $ndjson);
         $passes = [];
         $inputs = [];
         $peaks = [];
         $notes = [];
         foreach ($Invalid as $name => $Request) {
            $passes[$name] = [$Request->check()];
            TCP_Server_CLI::$maxWorkerPendingBytes = 2;
            $passes[$name][] = $Request->check();
            TCP_Server_CLI::$maxWorkerPendingBytes = $default;
            [$inputs[$name], $peaks[$name]] = $Peak($Request);
            $notes[$name] = "{$Request->Body->length} B, input() peak {$peaks[$name]} B";
         }
         $large = $Invalid['NDJSON (Elasticsearch bulk)']->Body->length > 1_300_000;
         $cut = $peaks['unterminated [[0],…'] < 1_048_576;
         $evidence = json_encode($notes);
         unset($Invalid);

         yield new Assertion(
            description: 'Invalid JSON (NDJSON, JSON text sequences, concatenated, gzip, unterminated) passes at any budget, and input() returns [] building nothing (the unterminated [[0],… peaks under 1 MiB)',
            fallback: "Bodies: {$evidence}"
         )
            ->expect([$large, $passes, $inputs, $cut])
            ->to->be([
               true,
               [
                  'NDJSON (Elasticsearch bulk)' => [true, true],
                  'JSON text sequence' => [true, true],
                  'concatenated values' => [true, true],
                  'gzip' => [true, true],
                  'unterminated [[0],…' => [true, true],
               ],
               [
                  'NDJSON (Elasticsearch bulk)' => [],
                  'JSON text sequence' => [],
                  'concatenated values' => [],
                  'gzip' => [],
                  'unterminated [[0],…' => [],
               ],
               true,
            ])
            ->assert();

         // @@ 7) Escapes cost the string pass no backtracking
         // # a) R4 DoS: an unterminated string of escaped quotes (1 MB) is
         //   invalid JSON — validated in one linear pass and passed, at any
         //   budget (R4 priced it byte by byte: 15,000,003; its string regex
         //   was quadratic there, ~3,700 s without JIT)
         $unterminated = str_repeat('\\"', 500_000);
         $Unterminated = $Build('application/json', "\"{$unterminated}");
         $started = hrtime(true);
         $verdicts = [$Unterminated->check()];
         $elapsed = intdiv(hrtime(true) - $started, 1_000_000);
         TCP_Server_CLI::$maxWorkerPendingBytes = 2;
         $verdicts[] = $Unterminated->check();
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;
         unset($unterminated, $Unterminated);

         yield new Assertion(
            description: '1 MB of unterminated escaped quotes passes at any budget in under 2 s (pcre.jit 0)',
            fallback: "Elapsed {$elapsed} ms"
         )
            ->expect([$elapsed < 2_000, $verdicts])
            ->to->be([true, [true, true]])
            ->assert();

         // # b) No backtrack budget to raise: with `pcre.backtrack_limit` at
         //   1,000 a valid 3 MB string of escapes, ~2 MB of escape-dense
         //   strings and 3 MB of `\n[` escapes (a million `[` in a string)
         //   still pass at the defaults
         ini_set('pcre.backtrack_limit', '1000');
         $Limited = [
            '3 MB of \\n' => $Build('application/json', $List(1, $Quote(str_repeat('\\n', 1_500_000)))),
            '2 MB of escape-dense strings' => $Build('application/json', $List(16_000, $riddled)),
            '3 MB of \\n[' => $Build('application/json', $List(1, $Quote(str_repeat('\\n[', 1_000_000)))),
         ];
         $passes = [];
         $bytes = [];
         foreach ($Limited as $name => $Request) {
            $passes[$name] = $Request->check();
            $bytes[$name] = $Request->Body->length;
         }
         ini_set('pcre.backtrack_limit', (string) $backtrack);
         $evidence = json_encode($bytes);
         unset($Limited);

         yield new Assertion(
            description: 'With pcre.backtrack_limit at 1,000 (pcre.jit 0) valid escape-heavy bodies of 2-3 MB still pass',
            fallback: "Bytes: {$evidence}"
         )
            ->expect($passes)
            ->to->be([
               '3 MB of \\n' => true,
               '2 MB of escape-dense strings' => true,
               '3 MB of \\n[' => true,
            ])
            ->assert();

         // # c) PCRE failing anyway refuses: what cannot be priced is not
         //   decoded (`pcre.backtrack_limit` 1 fails the string pass)
         $Small = $Build('application/json', $small);
         ini_set('pcre.backtrack_limit', '1');
         $failed = $Small->check();
         ini_set('pcre.backtrack_limit', (string) $backtrack);
         $passed = $Small->check();

         yield new Assertion(
            description: 'A small valid JSON body is refused when PCRE fails on its shape (pcre.backtrack_limit 1), and passes otherwise',
         )
            ->expect([$failed, $passed])
            ->to->be([false, true])
            ->assert();

         // # d) ~10 MB of escape-dense strings, decided in a child (20 s
         //   deadline: a hang fails here instead of blocking the runner)
         $item = var_export($riddled, true);
         [$finished, $code, $output, $errors] = $Run(<<<PHP
            \$joined = implode(',', array_fill(0, 80_000, {$item}));
            \$body = "[{\$joined}]";
            \$started = hrtime(true);
            \$verdict = \$Check(\$body);
            echo json_encode([strlen(\$body), \$verdict, intdiv(hrtime(true) - \$started, 1_000_000)]);
            PHP, [], 20);
         $result = json_decode($output, true);
         $decided = is_array($result) ? [$result[0] >= 10_000_000, $result[1]] : null;
         $gist = substr("{$output} {$errors}", 0, 600);

         yield new Assertion(
            description: 'A child decides ~10 MB of escape-dense strings within its 20 s deadline: it passes',
            fallback: "Finished: {$finished}, exit {$code}, output (bytes, verdict, ms): {$gist}"
         )
            ->expect([$finished, $code, $decided])
            ->to->be([true, 0, [true, true]])
            ->assert();

         // @@ 8) `check()` leaves `pcre.backtrack_limit` as it found it
         //   `["` + 1,500,000 × `\n` + `"]` (3,000,004 bytes): shape `[""]`,
         //   content 3,000,000 → price 400 + 64 + 48 + 6,000,000 + 4 =
         //   6,000,516, walk 48 + 6,000,000 + 4 + (56 + weigh(111) = 216) =
         //   6,000,268: at a share of 6,000,268 the walk accepts it, one byte
         //   below refuses it. Valid, invalid, keyed and plain bodies leave
         //   the limit where it was set
         ini_set('pcre.backtrack_limit', '999999');
         $escapes = $List(1, $Quote(str_repeat('\\n', 1_500_000)));
         $Escapes = $Build('application/json', $escapes);
         [$shape, $content] = $Split($escapes);
         $prices = [
            $shape,
            $content,
            $Price->invoke(null, $shape, $content),
            $Walk->invoke(null, $shape, $content),
         ];
         $passes = [];
         $limits = [];
         foreach ([$default, 2 * 6_000_268, 2 * (6_000_268 - 1)] as $pending) {
            TCP_Server_CLI::$maxWorkerPendingBytes = $pending;
            $passes[] = $Escapes->check();
            $limits[] = (string) ini_get('pcre.backtrack_limit');
         }
         TCP_Server_CLI::$maxWorkerPendingBytes = $default;
         foreach (
            [
               $small,
               $Sparse(1_000),
               $Sparse(10, escaped: true),
               '{"a":[1,2',
               "{$escapes}x",
            ] as $body
         ) {
            $passes[] = $Build('application/json', $body)->check();
            $limits[] = (string) ini_get('pcre.backtrack_limit');
         }
         ini_set('pcre.backtrack_limit', (string) $backtrack);
         unset($escapes, $Escapes);

         yield new Assertion(
            description: 'check() never moves pcre.backtrack_limit; a 3 MB string of escapes is decided at its walk (6,000,268)',
            fallback: 'Shape, content, price, walk; verdicts (defaults, at the walk, one below, then five bodies) and limits after each'
         )
            ->expect([$prices, $passes, array_unique($limits)])
            ->to->be([
               ['[""]', 3_000_000, 6_000_516, 6_000_268],
               [true, true, false, true, true, true, true, true],
               ['999999'],
            ])
            ->assert();

         // @@ 9) The walk boundary: `[` + pad + N × `[0]` (commas between) + `]`
         //   No string: the shape is the body, 4N + 1 + pad bytes. Each `[0]`:
         //   8 slots × 16 + 8 = 136, 56 + weigh(111) = 56 + 160 = 216. The
         //   outer list of N ∈ (131,072, 262,144] values: 262,144 slots ×
         //   16 + 8 = 4,194,312, 56 + weigh(4,194,287) = 56 + 4,198,400
         //   (1,025 pages) = 4,198,456. walk = 220N + 1 + pad + 4,198,456:
         //   N = ⌊(33,554,432 − 4,198,457) / 220⌋ = 133,436, pad = 55.
         //   price = 400(N + 1) + 64 × 2N + 4N + 1 + pad = 70,988,408 (over
         //   the share), lower bound = 216(N + 1) = 28,822,392 (under it):
         //   the walk decides. One more byte adds 1; one more value (`,0`) 2.
         $count = 133_436;
         $pad = 55;
         $exact = $List($count, '[0]', $pad);
         $open = substr($exact, 0, -1);
         $Exact = $Build('application/json', $exact);
         $Byte = $Build('application/json', "{$exact} ");
         $Value = $Build('application/json', "{$open},0]");
         $prices = [
            $Walk->invoke(null, $exact, 0),
            $Price->invoke(null, $exact, 0),
            $Least($exact),
            $Walk->invoke(null, "{$exact} ", 0),
            $Walk->invoke(null, "{$open},0]", 0),
         ];
         $boundary = json_encode([$prices, $share]);

         yield new Assertion(
            description: 'A walk of exactly the share passes; one byte or one value more is refused',
            fallback: "Walk, price, lower bound, walk +1 byte, walk +1 value; share: {$boundary}"
         )
            ->expect([$prices, $Exact->check(), $Byte->check(), $Value->check()])
            ->to->be([
               [$share, 70_988_408, 28_822_392, $share + 1, $share + 2],
               true,
               false,
               false,
            ])
            ->assert();
         unset($exact, $open, $Exact, $Byte, $Value);

         // @@ 10) Integer keys at the defaults (R5, R6)
         //   6,000 objects of the keys `0`…`16`, `63`, `64` (plain or escaped)
         //   are priced under the share by the quick bound (23.6 / 24.4 MB)
         //   yet decode to 49.6 MB — each object a list grown to 64 slots and
         //   turned into a map of 128: its gate leaves them to the walk
         //   (50.8 MB), which refuses them
         $Integers = [
            'sparse keys × 1,000' => $Sparse(1_000),
            'sparse keys × 6,000' => $Sparse(6_000),
            'escaped sparse keys × 6,000' => $Sparse(6_000, escaped: true),
            '1-based arrays × 20,000' => $Based(20_000),
            '1-based arrays × 50,000' => $Based(50_000),
         ];
         $verdicts = [];
         $fits = [];
         $measures = [];
         foreach ($Integers as $name => $body) {
            $real = $Measure($body);
            [$shape, $content] = $Split($body);
            $price = $Price->invoke(null, $shape, $content);
            $verdicts[$name] = $Build('application/json', $body)->check();
            $fits[$name] = $real <= $share;
            $measures[$name] = ['bytes' => strlen($body), 'real' => $real, 'price' => $price];
            unset($shape);
         }
         unset($Integers);
         // # The attacks decode past 100 MB: a child (`memory_limit` 1G, 30 s
         //   deadline) builds each, measures its decode and checks it at the
         //   defaults — R5: 13,783 sparse objects; R6: 22,142 of them with a
         //   repeated `0` (to `127`), 9,992 of the keys `\\0`…`\\64`
         $escaped = array_map(static fn (int $key): string => "\\\\{$key}", range(0, 64));
         $attacks = var_export([
            'sparse keys × 13,783 (R5 attack)' => [$Keyed($sparse), 13_783],
            'a repeated 0 × 22,142 (R6 attack)' => [$Keyed([0, 0, ...range(1, 16), 63, 127]), 22_142],
            'escaped backslash keys × 9,992 (R6 attack)' => [$Keyed($escaped), 9_992],
         ], true);
         [$finished, $code, $output, $errors] = $Run(<<<PHP
            \$results = [];
            foreach ({$attacks} as \$name => [\$object, \$count]) {
               \$joined = implode(',', array_fill(0, \$count, \$object));
               \$body = "[{\$joined}]";
               unset(\$joined);
               \$before = memory_get_usage();
               \$decoded = json_decode(\$body, true, 512, JSON_THROW_ON_ERROR);
               \$real = memory_get_usage() - \$before;
               unset(\$decoded);
               \$results[\$name] = ['bytes' => strlen(\$body), 'real' => \$real, 'verdict' => \$Check(\$body)];
            }
            echo json_encode(\$results);
            PHP, ['memory_limit' => '1G'], 30);
         $attacked = json_decode($output, true);
         $children = [];
         if (is_array($attacked)) {
            foreach ($attacked as $name => $attack) {
               $fits[$name] = $attack['real'] <= $share;
               $verdicts[$name] = $attack['verdict'];
               $measures[$name] = $attack;
               $children[$name] = $attack['bytes'];
            }
         }
         $evidence = json_encode($measures);
         $gist = substr("{$output} {$errors}", 0, 600);

         yield new Assertion(
            description: 'Integer keys at the defaults: the R5 attack, the R6 attacks and 6,000 sparse objects are refused, 1,000 pass; 20,000 1-based arrays pass, 50,000 do not',
            fallback: "Child: finished {$finished}, exit {$code}, {$gist} — bodies: {$evidence}"
         )
            ->expect([
               [$finished, $code],
               $children,
               $measures['sparse keys × 6,000']['price'] <= $share,
               $measures['escaped sparse keys × 6,000']['price'] <= $share,
               $fits,
               $verdicts,
            ])
            ->to->be([
               [true, 0],
               [
                  'sparse keys × 13,783 (R5 attack)' => 1_722_876,
                  'a repeated 0 × 22,142 (R6 attack)' => 2_922_745,
                  'escaped backslash keys × 9,992 (R6 attack)' => 5_765_385,
               ],
               true,
               true,
               [
                  'sparse keys × 1,000' => true,
                  'sparse keys × 6,000' => false,
                  'escaped sparse keys × 6,000' => false,
                  '1-based arrays × 20,000' => true,
                  '1-based arrays × 50,000' => false,
                  'sparse keys × 13,783 (R5 attack)' => false,
                  'a repeated 0 × 22,142 (R6 attack)' => false,
                  'escaped backslash keys × 9,992 (R6 attack)' => false,
               ],
               [
                  'sparse keys × 1,000' => true,
                  'sparse keys × 6,000' => false,
                  'escaped sparse keys × 6,000' => false,
                  '1-based arrays × 20,000' => true,
                  '1-based arrays × 50,000' => false,
                  'sparse keys × 13,783 (R5 attack)' => false,
                  'a repeated 0 × 22,142 (R6 attack)' => false,
                  'escaped backslash keys × 9,992 (R6 attack)' => false,
               ],
            ])
            ->assert();
         unset($measures, $evidence);

         // @@ 11) `check()` runs where `ini_set` and `ini_get` are disabled
         //   (R5: every valid JSON request threw `Error`). A child decides a
         //   small body (quick bound), 1,000 sparse objects (walk) and ~2 MB
         //   of escape-dense strings (escape strip) — 20 s deadline
         $body = var_export($small, true);
         $object = var_export($Keyed($sparse), true);
         $item = var_export($riddled, true);
         [$finished, $code, $output, $errors] = $Run(<<<PHP
            \$objects = implode(',', array_fill(0, 1_000, {$object}));
            \$strings = implode(',', array_fill(0, 16_000, {$item}));
            echo json_encode([
               \$Check({$body}),
               \$Check("[{\$objects}]"),
               \$Check("[{\$strings}]"),
            ]);
            PHP, ['disable_functions' => 'ini_set,ini_get'], 20);
         $gist = substr("{$output} {$errors}", 0, 600);

         yield new Assertion(
            description: 'check() runs with ini_set and ini_get disabled (a child): valid JSON passes, no Error',
            fallback: "Finished: {$finished}, exit {$code}, output: {$gist}"
         )
            ->expect([$finished, $code, $output])
            ->to->be([true, 0, '[true,true,true]'])
            ->assert();

         // @@ 12) A run of digits costs the string pass no budget (R5 review)
         //   Without PCRE JIT each repeat of an unbounded key group spent the
         //   match budget: a string of ~1,000,000 digits — key or value —
         //   exhausted the default `pcre.backtrack_limit` and was refused. The
         //   kept key is bounded to 20 digits (no index is longer). A child
         //   (`pcre.jit` 0 — the compiled-pattern cache would mask an
         //   in-process switch) at the default limit — 20 s deadline
         [$finished, $code, $output, $errors] = $Run(<<<'PHP'
            $digits = str_repeat('7', 1_000_001);
            $escapes = str_repeat('\\' . 'u0037', 166_667);
            echo json_encode([
               $Check("{\"pi\":\"{$digits}\"}"),
               $Check("{\"{$digits}\":0}"),
               $Check("{\"{$escapes}\":0}"),
            ]);
            PHP, ['pcre.backtrack_limit' => '1000000'], 20);
         $gist = substr("{$output} {$errors}", 0, 600);

         yield new Assertion(
            description: 'Without PCRE JIT, a value or key of 1,000,001 digits and a key of 166,667 escaped digits pass at the default backtrack limit',
            fallback: "Finished: {$finished}, exit {$code}, output: {$gist}"
         )
            ->expect([$finished, $code, $output])
            ->to->be([true, 0, '[true,true,true]'])
            ->assert();
      }
      finally {
         TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
         ini_set('pcre.jit', (string) $JIT);
         ini_set('pcre.backtrack_limit', (string) $backtrack);
      }
   }),
);
