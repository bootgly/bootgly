<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC H-HSC-5 (live) — a deferred request's head is charged while
 * it parks, and neither the live connection nor the pooled Fiber that ran
 * the work keeps any of it once answered.
 *
 * A deferral works on its own snapshot of the request (`Request::capture()`).
 * Before the fix that snapshot's head — the raw header block, the field map
 * and whatever the work parses from it, such as a large cookie jar — was
 * charged to no ledger, so parked deferrals could hold any amount of worker
 * memory; and the live per-connection Request kept its own copy of the head
 * until the peer's next request. Now `capture()` charges the snapshot's price
 * (`Request::weigh()`: its objects, head, URI and what was already parsed —
 * multipart text fields included) to the worker budget's Inbound share, what
 * the work parses later (its cookies, query vars) is charged when it is built
 * (`Decoders\Bodies::grow()`), and either is refused when it does not fit;
 * the encoder sheds the live head as soon as the deferral is scheduled, and a
 * finished Fiber drops the snapshot before it parks in the reuse pool.
 *
 * Each row boots a REAL HTTP_Server_CLI (one worker, production `Encoder_`)
 * in an isolated child process (`setsid`, hard `timeout`, a scrubbed
 * environment, its own loopback port in 19600-19699,
 * `-d memory_limit=128M -d opcache.jit=0`) and talks to it with plain sockets.
 * A deferral parks on a socket pair until the `/w` route writes to it:
 *
 * - D1 snapshot charge: `maxWorkerPendingBytes` 16 MiB (an 8 MiB Inbound
 *   share). 60 keep-alive peers each send one 16,376-byte head — Host plus one
 *   Cookie line of 1,938 short pairs — to a route whose deferred work parses
 *   `$Snapshot->cookies` before it parks. Every capture moves the Inbound
 *   ledger by exactly `Request::weigh()` of its request (read through
 *   reflection on the decoded request); a parked snapshot then holds that plus
 *   its parsed jar (`Buffers::tally()`); exactly as many park as fit the share
 *   at that charge, every other one is answered `500` at once (refused at the
 *   cookie parse — the current mapping, tracked as ENC-8); the ledger holds
 *   the parked snapshots, `/w` wakes every one, each answers with its jar and
 *   the ledger returns to its base. Measured (PHP 8.5, 2026-10-12): capture
 *   39,817 B, jar 215,485 B, so 255,302 B per parked snapshot — 32 parked
 *   (8,169,664 B held), 28 refused. HEAD b98692a0 parked all 60 with the
 *   ledger at 0 (no `weigh()`, no `tally()`).
 * - D2 live head after a deferral: 200 keep-alive peers each send one head of
 *   exactly 100 field lines and 16,380 bytes to a route whose deferred work
 *   parks without reading the head; `/w` wakes them, each answers and stays
 *   idle. `/m` reports `memory_get_usage()` (after `gc_collect_cycles()`)
 *   before the first peer and once all 200 are idle again, plus the head
 *   bytes the idle peers' live Requests still hold (must be 0). Before the
 *   fix a pooled deferral Fiber kept its last job's snapshot until its next
 *   job (~51 KB each here) — and the snapshot shares its strings with the
 *   live head — so 200 warm-up deferrals fill the pool and 200 tiny
 *   deferrals on other peers replace whatever a pooled Fiber holds before
 *   each reading. Measured per idle peer (PHP 8.5, 2026-10-12): HEAD
 *   b98692a0 57,077 B (3,272,200 live head bytes), the fix 5,377 B (0);
 *   threshold 16,384 B.
 * - D3 pooled Fibers: `Response::loop()` parks up to 256 finished Fibers for
 *   reuse, and its pre-park reset did not clear the snapshot it had just
 *   cleaned (`$Request`), so every pooled Fiber kept its last request — head
 *   and parsed cookie jar — after its ledger charge was already returned.
 *   100 cheap deferrals at once fill the pool, then 4 rounds each park 50
 *   heavy deferrals (the D1 head; the work parses `$Snapshot->cookies`)
 *   interleaved with 50 cheap ones, wake them, read every answer and close
 *   every peer. Once each round's ledger is back at its base, worker memory
 *   must be back within 1 MiB of the pre-round base. Measured (PHP 8.5,
 *   2026-10-12): HEAD b98692a0 +8,926,352 B after every round (the 50 heavy
 *   pooled Fibers, ~178 KB each), the fix +19,624 B; threshold 1,048,576 B.
 * - D4 multipart snapshot: default budget (a 32 MiB Inbound share). 16 peers,
 *   one at a time — the first a GET, the rest POST: fields are parsed by media
 *   type, not by method — each send one `multipart/form-data` body of 9 text
 *   fields of 1,000,000 bytes to a route whose deferred work reads
 *   `$Snapshot->fields` and parks. A parked snapshot is charged `weigh()`
 *   with its fields' tally; exactly as many park as fit the share at that
 *   price, and every later upload is refused `503` by the multipart decoder
 *   while it parses, because the parked snapshots already hold the share.
 *   `/w` wakes every parked one, each answers with its 9 fields, and the
 *   ledger returns to its base. Measured (PHP 8.5, 2026-10-12): 18,878,256 B
 *   per snapshot (fields 18,875,480 B — each 1 MB string is a whole 2 MiB
 *   chunk), so 1 parked (the GET), 15 POSTs refused 503, worker memory
 *   3.1 → 12.3 → 3.2 MB. HEAD b98692a0 charged nothing (ledger 0): 13
 *   deferrals parked, the 14th upload killed the worker (`Allowed memory
 *   size` in `Decoder_Downloading`) and the parked peers never answered.
 * - D5 deferred work parses its body: default budget. 12 peers, one at a
 *   time, alternate a 125,619-byte urlencoded form of 990 vars nested 40 deep
 *   (`k<i>[a]…[a]=v`, under `max_input_vars` and `max_input_nesting_level`)
 *   and its 248,381-byte JSON twin, to a route whose deferred work reads
 *   `$Snapshot->fields` and parks. The fields getter parses once, through
 *   `receive()`, which charges the snapshot what the parse keeps
 *   (`Buffers::tally()` — predicted here by parsing the same bytes); the work
 *   records the ledgers right before its parse, so a peer must park exactly
 *   when that charge fits the Inbound share and the budget, and be answered
 *   `500` (refused inside the work) otherwise. `/w` wakes every parked one,
 *   each answers with its 990 fields and the ledger returns to its base.
 *   Measured (PHP 8.5, 2026-10-12): 18,801,220 B per parse (either body),
 *   capture 142,747 B (form) / 302,498 B (JSON), so 1 parked (18,943,967 B
 *   held), 11 refused 500, worker memory 3.2 → 19.7 → 3.3 MB, no refork.
 *   HEAD b98692a0 charged no parse (the getter decoded a second copy through
 *   `input()`): every POST parked, worker memory +32.7 MB per POST, the
 *   worker died (`Allowed memory size`) on every 4th POST — 3 reforks.
 * - D6 one JSON request: a 1,160,001-byte `[[0],[0],…]` JSON POST to a
 *   synchronous route that reads `$Request->fields`, then to one that does
 *   not, each on its own keep-alive connection; then an ordinary
 *   186,901-byte JSON body of 2,000 records to the reading route.
 *   `Request::check()` prices them through its own `price()` and `walk()`
 *   (read here by reflection): the `[[0],…]` body is over its quick bound
 *   (154,280,401 B) and refused at the least its 290,001 filled containers
 *   spend (62,640,216 B — the walk would price 72,192,761 B), past the
 *   32 MiB bodies share; the ordinary body is accepted under the quick bound
 *   (2,071,882 B). Both `[[0],…]` POSTs must be answered `413` and closed
 *   before any handler runs (the routes count their calls), the worker must
 *   survive, and the ordinary body must be answered `200` with its 2,000
 *   records (an md5 of its decoded fields). Measured (PHP 8.5, 2026-10-12):
 *   413/413/200, handlers 0 → 0 → 1, worker peak 4.8 MB. HEAD b98692a0: the
 *   reading route killed the worker (`Allowed memory size`, refork), the
 *   other answered 200 — its handler ran. Without the encoder's refusal
 *   (and with the fields getter's single parse) the worker decodes it at a
 *   76.6 MB peak and answers both 200.
 * - D7 refused bodies behind owed bytes: default budget. 14 peers, one at a
 *   time, each with a 4 KiB receive buffer, pipeline 48 small GETs whose
 *   64 KiB answers they never read — more than the loopback buffers absorb,
 *   so the worker owes each peer output (`Shares::Transport` above its base)
 *   and a refused request's close waits for that drain — then a
 *   9,999,999-byte `[0,0,…]` JSON POST (under `maxBodySize`; refused at the
 *   least tier, 80,000,040 B — a 16-byte slot per value; quick bound
 *   330,000,335 B, walk 144,221,879 B) to a route that does not read it. `/m` reports the body
 *   bytes the other connections' live Requests still hold. Every body must
 *   be refused before any handler, with nothing held while every peer stays
 *   connected, and each peer, once it reads, gets its 48 answers, then
 *   `413 Request Entity Too Large`, then the close. Measured (PHP 8.5,
 *   2026-10-12): 2,097,152 B owed per peer (29,360,128 B with all 14 held),
 *   0 body bytes held, handlers 0, worker memory 3.2 → 22.3 → 3.2 MB (peak
 *   32.3 MB). Before the encoder dropped the refused request (`scrub()`,
 *   `shed()`) every refused body stayed on its connection while the owed
 *   bytes drained, outside every ledger: 9,999,999 B per peer, 99,999,990 B
 *   at the 10th (worker 116.9 MB), and the 11th body killed the worker
 *   (`Allowed memory size` in `Decoder_Waiting`, refork). HEAD b98692a0
 *   refused nothing: every body reached its handler (200, 14 handlers),
 *   nothing held.
 * - D8 nothing runs behind a refusal: one such peer (48 unread 64 KiB
 *   answers) sends, in one write, the D6 `[[0],…]` body and a pipelined GET
 *   to a route that counts its runs. The body is refused while the writer
 *   owes bytes (2,097,152 B measured), and the pipelined GET must never run
 *   (its counter stays 0) nor be answered: the peer reads its 48 answers,
 *   the 413 and the close. The body completes as the first request of its
 *   read, so `Packages::reading()` stops after the first write. Without
 *   that stop (its `|| $this->rejected`) the GET ran (counter 0 → 1) and
 *   its answer followed the 413 on the wire (measured 2026-10-12); HEAD
 *   b98692a0 refused nothing (the body answered 200, the GET ran).
 * - D9 nothing runs behind a refusal in a pipelined write: a 4 MiB worker
 *   budget (a 2 MiB bodies share). One such peer sends everything in ONE
 *   write of 49,857 bytes — one worker read: 48 GETs whose 64 KiB answers it
 *   does not read, a 48,001-byte `[[0],…]` JSON body (12,000 cells, refused
 *   at the least tier: 2,592,216 B) and a GET to the counting route. The
 *   body is a pipelined request of that read, refused while the writer owes
 *   2,097,152 B; the GET must never run nor be answered: the peer reads its
 *   48 answers, the 413 and the close. A lone 64 KiB answer leaves nothing
 *   owed — the loopback buffers absorb it and the refusal closes at once,
 *   stopping the loop either way — hence the 48. Measured (PHP 8.5,
 *   2026-10-12): owed 0 → 2,097,152 B, counter 0, 48 answers + `413 0`.
 *   Without the pipelined write's stop (`|| $this->rejected` in the
 *   pipeline loop of `Packages::reading()`) the GET ran (counter 0 → 1) and
 *   its answer followed the 413 (`413 139`); D8 stays green, as D9 does
 *   without the first-write stop.
 * - D10 an unterminated escape string: a 16 MiB worker budget (an 8 MiB
 *   bodies share). One POST of `"` and 500,000 escaped quotes (`\"`),
 *   1,000,001 bytes, to the route that reads its JSON fields; 100 ms after
 *   it is written, a plain GET on another connection. It is invalid JSON,
 *   which `check()` passes — `input()` validates a JSON body first and
 *   decodes nothing from an invalid one — so it meets no pricing pass and
 *   no string-emptying pattern (one is quadratic on it). The body must be
 *   answered `200` with no fields (`check()` itself, run here on the same
 *   bytes, passes it) and the GET `200`, each within 2 s, and the handler
 *   run once. Measured (PHP 8.5, 2026-10-12): both in ~0.10 s (the 100 ms
 *   pause included). The R4 tree priced invalid JSON byte by byte
 *   (15,000,003 B: 250,000 strings, the body twice as content and once as
 *   structure — over this share, hence the lower budget) and answered
 *   `413`; before the `json_validate()` gate the worker froze in the
 *   pattern: the GET unanswered after 3 s, no answer after 9 s, `/m` silent.
 * - D11 bodies labelled JSON that are not one JSON text: default budget. A
 *   1,423,670-byte Elasticsearch `_bulk` body (15,500 action and document
 *   line pairs) as `application/x-ndjson`, then D6's `[[0],…]` without its
 *   closing bracket (1,160,000 bytes) as `application/json`, each to the
 *   route that reads its JSON fields. Both are invalid JSON: each must be
 *   answered `200` with no fields and kept alive, the handler run once per
 *   body, and the worker peak grow less than 16 MiB — a decode of the cut
 *   body builds its ~71 MB prefix before it fails. Measured (PHP 8.5,
 *   2026-10-12): 200/200, handlers 0 → 1 → 2, worker peak 3.9 → 4.7 MB. The
 *   R4 tree priced them byte by byte (42,215,010 B and 156,600,400 B, over
 *   the 32 MiB share) and answered both `413`; with `check()` passing them
 *   but `input()` decoding without its `json_validate()` gate, the cut body
 *   was answered 200 at a worker peak of 75.4 MB.
 * - D12 objects begun as lists: default budget. One POST of 13,783 objects
 *   keyed `0`…`16`, `63`, `64` (1,722,876 bytes) to the route that reads its
 *   JSON fields, then 940,001 bytes of what PHP's `json_encode()` writes for
 *   10,000 arrays of 10 strings keyed from 1. PHP builds each object as a
 *   list, doubles it twice, then turns it into a map of twice its slots
 *   (~8 KB an object): the first decodes into ~114 MB. A first key from 0
 *   to 7 skips the quick bound and the walk follows each such table
 *   (`Buffers\Table`): the sparse body is priced over the bodies share and
 *   must be answered `413` and closed before any handler runs, with no
 *   refork; the rows must be answered `200` with their 10,000 rows.
 *   Measured (PHP 8.5, 2026-10-12): walk 129,220,633 B → 413, rows walk
 *   14,859,583 B → 200, handlers 0 → 0 → 1, worker peak 12.4 MB. With
 *   `walk()` sizing those objects as maps (the R4 tree, or the fix without
 *   its table) the sparse body was priced 33,056,642 B, under the share,
 *   and answered 200 once the worker had decoded it (peak 120.7 MB of its
 *   128M).
 * - D13 a parked snapshot of such objects: default budget. 24 peers, one at
 *   a time, each POST 1,000 of D12's objects (125,001 bytes) to D5's route,
 *   whose deferred work reads `$Snapshot->fields` and parks. The parse is
 *   charged its `Buffers::tally()` — which must cover what the same decode
 *   really spends, measured here — so a peer must park exactly when that
 *   charge fits, and be answered `500` otherwise; `/w` wakes every parked
 *   one, each answers with its 1,000 objects, the ledger returns to its
 *   base. Measured (PHP 8.5, 2026-10-12): decode 8,268,536 B, tally
 *   8,333,820 B, so 3 parked (25,429,605 B held), 21 refused 500, worker
 *   memory 3.2 → 28.8 → 3.4 MB. With `tally()` sizing those objects as maps
 *   (the R4 tree) each parse was charged 1,612,820 B: the parked snapshots
 *   held five times their charge and the worker died (`Allowed memory
 *   size`, refork).
 *
 * Every row records the worker PID before/after, the `/m` metrics route and
 * the server log; a reforked worker or an `Allowed memory size` fatal fails
 * it. The child gets only PATH and HOME — the runner's variables (its
 * agent-output flag makes Bootgly's bootstrap call `posix_setsid()`) would
 * move the server out of the group the `timeout` bound and the cleanup kill.
 * The process groups the child tree really runs in are read from `/proc` once
 * it answers and killed on stop; a row whose server left its group, or left a
 * process or a listener behind, fails. Every row is judged before any is
 * asserted, so the first failure reports every verdict and the measured
 * figures.
 *
 * Local-only: the Security suite never runs in CI.
 */


// # Server child
// ! The servers never run in the Security suite's shared worker: the spec
//   re-executes this file as an isolated, session-led PHP process that boots
//   one real HTTP_Server_CLI (one worker) on its own loopback port.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--hhsc5-deferred-server'
) {
   // !
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $base = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][4] ?? 0);
   $row = (string) ($_SERVER['argv'][5] ?? '');

   // ?
   if (
      $root === ''
      || is_file("{$root}/autoboot.php") === false
      || $base === ''
      || is_dir($base) === false
      || $port < 1024
   ) {
      fwrite(STDERR, "H-HSC-5 server child received an invalid root, base or port.\n");
      exit(2);
   }

   define('BOOTGLY_STORAGE_BASE', "{$base}/storage");
   define('BOOTGLY_STORAGE_DIR', "{$base}/storage/");
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // @ One worker; D1 and D10 lower the worker budget to a 16 MiB total, D9
   //   to 4 MiB
   $Server = new HTTP_Server_CLI(Modes::Foreground);
   $Server->configure(
      new HTTP_Server_CLI\Configs(
         host: '127.0.0.1',
         port: $port,
         workers: 1,
         maxWorkerPendingBytes: match ($row) {
            'D1', 'D10' => 16 * 1024 * 1024,
            'D9' => 4 * 1024 * 1024,
            default => null,
         },
      ),
   );

   // ! The worker budget's Inbound share: unfinished bodies and parked snapshots
   $Inbound = static function (): int {
      try {
         $held = new ReflectionProperty(Buffers::class, 'held')->getValue();
      }
      catch (Throwable) {
         return -1;
      }

      return is_array($held) && is_int($held[Shares::Inbound->value] ?? null)
         ? $held[Shares::Inbound->value]
         : -1;
   };
   // ! Park a deferral on a socket pair until `/w` writes to its other end
   $Park = static function (Response $Response): void {
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         throw new RuntimeException('H-HSC-5 could not allocate a socket pair.');
      }
      [$Never, $Hold] = $Pair;
      stream_set_blocking($Never, false);
      $GLOBALS['hhsc5_holds'][] = $Hold;

      try {
         $Response->wait(Readiness::read($Never, microtime(true) + 60.0));
      }
      finally {
         fclose($Never);
      }
   };

   // ! The fix's snapshot price (`Request::weigh()`) and parsed-value tally
   //   (`Buffers::tally()`); -1 where they do not exist
   $Price = static function (Request $Request): int {
      try {
         $price = new ReflectionMethod(Request::class, 'weigh')->invoke($Request);
      }
      catch (Throwable) {
         return -1;
      }

      return is_int($price) ? $price : -1;
   };
   $Tally = static function (array $values): int {
      return method_exists(Buffers::class, 'tally') ? Buffers::tally($values) : -1;
   };

   $Server->on(Events::RequestReceived, static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Inbound, $Park, $Price, $Tally, $row): Generator {
      // # Metrics: the worker PID, its connections, memory and ledgers
      yield $Router->route('/m', static function (
         Request $Request,
         Response $Response,
      ) use ($Inbound): Response {
         // ! Garbage cycles of departed peers never count as held memory
         gc_collect_cycles();
         $captures = $GLOBALS['hhsc5_captures'] ?? [];
         $parks = $GLOBALS['hhsc5_parks'] ?? [];
         // ! Head and body bytes the other connections' live Requests still
         //   hold, and how many of them carry a whole body
         $live = 0;
         $raws = 0;
         $bodies = 0;
         foreach (Connections::$Connections as $Connection) {
            $Decoded = $Connection->decoded;
            if ($Decoded instanceof Request && $Decoded !== $Request) {
               $live += strlen($Decoded->Header->raw ?? '');
               $raws += strlen($Decoded->Body->raw ?? '');
               if (($Decoded->Body->length ?? 0) > 0 && ($Decoded->Body->waiting ?? true) === false) {
                  $bodies++;
               }
            }
         }
         // ! The worker budget's Transport share: pending output, receive carry
         try {
            $held = new ReflectionProperty(Buffers::class, 'held')->getValue();
            $transport = is_array($held) && is_int($held[Shares::Transport->value] ?? null)
               ? $held[Shares::Transport->value]
               : -1;
         }
         catch (Throwable) {
            $transport = -1;
         }

         return $Response(body: (string) json_encode([
            'pid' => getmypid(),
            'group' => posix_getpgid(0),
            'connections' => count(Connections::$Connections),
            'usage' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'budget' => TCP_Server_CLI::$maxWorkerPendingBytes,
            'inbound' => $Inbound(),
            'parked' => count($GLOBALS['hhsc5_holds'] ?? []),
            'live' => $live,
            'raws' => $raws,
            'bodies' => $bodies,
            'transport' => $transport,
            'captured' => count($captures),
            'prices' => array_count_values(array_column($captures, 0)),
            'charges' => array_count_values(array_column($captures, 1)),
            'holds' => array_count_values(array_column($parks, 0)),
            'tallies' => array_count_values(array_column($parks, 1)),
            'attempts' => $GLOBALS['hhsc5_attempts'] ?? [],
            'parses' => $GLOBALS['hhsc5_parses'] ?? [],
            'handled' => $GLOBALS['hhsc5_handled'] ?? 0,
            'counted' => $GLOBALS['hhsc5_counted'] ?? 0,
         ]));
      }, GET);

      // # D7-D9: a 64 KiB answer the peer never reads, and one that counts its runs
      yield $Router->route('/o', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: str_repeat('o', 65_536));
      }, GET);
      yield $Router->route('/c', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $GLOBALS['hhsc5_counted'] = ($GLOBALS['hhsc5_counted'] ?? 0) + 1;

         return $Response(body: 'counted');
      }, GET);

      // # D1/D3: the work parses the snapshot's cookie jar, then parks
      yield $Router->route('/d', static function (
         Request $Request,
         Response $Response,
      ) use ($Inbound, $Park, $Price, $Tally, $row): Response {
         // ! The snapshot's price, read on the decoded request, and the ledger
         //   before the snapshot is taken. Recorded in D1 only: the record
         //   would itself grow D3's worker memory.
         $price = $Price($Request);
         $before = $Inbound();
         $record = $row === 'D1';

         // @ A snapshot refused at capture throws out of defer(), one refused
         //   at the cookie parse throws inside the work: both answer 500
         $Response->defer(static function (Response $Response, Request $Snapshot) use ($Inbound, $Park, $Tally, $price, $before, $record): void {
            if ($record) {
               $GLOBALS['hhsc5_captures'][] = [$price, $Inbound() - $before];
            }
            $cookies = $Snapshot->cookies;
            if ($record) {
               $GLOBALS['hhsc5_parks'][] = [$Inbound() - $before, $Tally($cookies)];
            }
            $jar = count($cookies[0] ?? []);

            $Park($Response);
            $Response(body: "woke {$jar}");
         });

         return $Response;
      }, GET);

      // # D4: multipart text fields — the work reads them, then parks
      yield $Router->route('/u', static function (
         Request $Request,
         Response $Response,
      ) use ($Inbound, $Park, $Price, $Tally): Response {
         // ! The snapshot's price and the ledger before the snapshot is taken
         $price = $Price($Request);
         $before = $Inbound();

         $Response->defer(static function (Response $Response, Request $Snapshot) use ($Inbound, $Park, $Tally, $price, $before): void {
            $GLOBALS['hhsc5_captures'][] = [$price, $Inbound() - $before];
            $fields = $Snapshot->fields;
            $GLOBALS['hhsc5_parks'][] = [$Inbound() - $before, $Tally($fields)];
            $count = count($fields);

            $Park($Response);
            $Response(body: "woke {$count}");
         });

         return $Response;
      }, [GET, POST]);

      // # D5/D13: the work parses the snapshot's body fields, then parks
      yield $Router->route('/f', static function (
         Request $Request,
         Response $Response,
      ) use ($Inbound, $Park, $Tally): Response {
         // ! The peer, and the ledger before the snapshot is taken
         $peer = (int) $Request->Header->get('X-Peer');
         $before = $Inbound();

         // @ A parse refused inside the work answers 500
         $Response->defer(static function (Response $Response, Request $Snapshot) use ($Inbound, $Park, $Tally, $peer, $before): void {
            // ! The ledgers right before the parse: Inbound, the whole budget
            //   and what the capture moved
            $at = $Inbound();
            $total = property_exists(TCP_Server_CLI::class, 'pendingBytes') ? TCP_Server_CLI::$pendingBytes : -1;
            $GLOBALS['hhsc5_attempts'][$peer] = [$at, $total, $at - $before];
            $fields = $Snapshot->fields;
            $GLOBALS['hhsc5_parses'][$peer] = [$Inbound() - $at, $Tally($fields)];
            $count = count($fields);

            $Park($Response);
            $Response(body: "woke {$count}");
         });

         return $Response;
      }, POST);

      // # D6-D12: synchronous routes — one reads its JSON fields, one does not
      yield $Router->route('/j', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $GLOBALS['hhsc5_handled'] = ($GLOBALS['hhsc5_handled'] ?? 0) + 1;
         $fields = $Request->fields;
         $count = count($fields['items'] ?? $fields);
         $digest = md5((string) json_encode($fields));

         return $Response(body: "fields {$count} {$digest}");
      }, POST);
      yield $Router->route('/n', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $GLOBALS['hhsc5_handled'] = ($GLOBALS['hhsc5_handled'] ?? 0) + 1;

         return $Response(body: 'ignored');
      }, POST);

      // # D2: the work parks without reading the head
      yield $Router->route('/p', static function (
         Request $Request,
         Response $Response,
      ) use ($Park): Response {
         $Response->defer(static function (Response $Response) use ($Park): void {
            $Park($Response);
            $Response(body: 'woke');
         });

         return $Response;
      }, GET);

      // # Wake every parked deferral
      yield $Router->route('/w', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $woken = 0;
         foreach ($GLOBALS['hhsc5_holds'] ?? [] as $Hold) {
            if (@fwrite($Hold, 'w') === 1) {
               $woken++;
            }
            @fclose($Hold);
         }
         $GLOBALS['hhsc5_holds'] = [];

         return $Response(body: "woken {$woken}");
      }, GET);
   });

   $Server->start();

   exit(0);
}


// # Spec
$Probe = new class {
   // * Data
   /** Root of the per-run temp area (server storage + logs). */
   public string $base = '';
   /** Next port offset inside the 19600-19699 range. */
   public int $cursor = -1;
   /** @var array<string,array<string,mixed>> Evidence by row. */
   public array $rows = [];
};

// ! The HTTP/1 head byte limit: `Request\Frame::parse()` answers `413` past it
$limit = 16_384;
// ! The default field-line cap (`Request::$maxHeaderFields`)
$cap = 100;
// ! D1's and D10's worker budget: its Inbound share is half of it
$budget = 16 * 1024 * 1024;
// ! D9's worker budget: a 2 MiB bodies share refuses a JSON body of ~48 KB,
//   and its whole leaves room for the 2 MiB of output owed to the peer
$lowered = 4 * 1024 * 1024;
// ! The transport's default worker budget (D4-D6): fits half of the
//   children's 128M `memory_limit`
$default = 64 * 1024 * 1024;
// ! Growth per idle peer (bytes) D2 must stay under — between the HEAD and
//   the fixed measurements written in the docblock
$threshold = 16_384;
// ! Worker memory (bytes) D3 may stay above its pre-round base once every
//   round answered and the ledger is back at its base — between the HEAD and
//   the fixed measurements written in the docblock
$drift = 1_048_576;
// ! GETs a D7-D9 peer pipelines and never reads before its body: their 64 KiB
//   answers (3 MiB) outgrow what the loopback socket buffers absorb, so the
//   worker owes the peer output (2 MiB of footprint measured)
$owed = 48;
// ! What the JSON route answers for a body that decodes into nothing: no
//   fields, and the md5 of their empty JSON list
$empty = md5('[]');
$nothing = "fields 0 {$empty}";
// ! Worker peak growth (bytes) D11 must stay under — the bodies' own bytes
//   fit it, the ~71 MB its cut body's prefix decodes into does not
$bounded = 16 * 1024 * 1024;

$Clean = static function (string $path) use (&$Clean): void {
   // ?
   if ($path === '' || str_starts_with($path, sys_get_temp_dir()) === false) {
      return;
   }
   // ? Anything but a directory: files, links and the server's log socket
   if (is_link($path) || (file_exists($path) && is_dir($path) === false)) {
      @unlink($path);

      return;
   }
   if (is_dir($path) === false) {
      return;
   }

   // @@
   foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
         continue;
      }

      $Clean("{$path}/{$entry}");
   }
   @rmdir($path);
};

/**
 * Exchange one request on an open connection: write it whole, then read one
 * answer — its head and exactly Content-Length body bytes, or everything up
 * to the server's close (a rejection carries no Content-Length).
 *
 * @return array{status:string,line:string,head:string,body:string,closed:bool}
 */
$Exchange = static function (mixed $Socket, string $request, float $timeout = 3.0): array {
   // !
   stream_set_blocking($Socket, false);
   $deadline = microtime(true) + $timeout;
   $wire = '';
   $closed = false;

   // @@ Write
   $offset = 0;
   $length = strlen($request);
   while ($offset < $length && microtime(true) < $deadline) {
      $Reads = null;
      $Writes = [$Socket];
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 50_000) < 1) {
         continue;
      }
      $written = @fwrite($Socket, substr($request, $offset));
      if ($written === false) {
         break;
      }
      $offset += $written;
   }

   // @@ Read one answer
   $expected = null;
   while (microtime(true) < $deadline) {
      $Reads = [$Socket];
      $Writes = null;
      $Except = null;
      $ready = @stream_select($Reads, $Writes, $Except, 0, 50_000);
      if ($ready === false) {
         break;
      }
      if ($ready === 0) {
         continue;
      }
      $chunk = @fread($Socket, 1 << 20);
      if ($chunk === false || ($chunk === '' && feof($Socket))) {
         $closed = true;
         break;
      }
      $wire .= $chunk;

      if ($expected === null) {
         $separator = strpos($wire, "\r\n\r\n");
         if (
            $separator !== false
            && preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', substr($wire, 0, $separator + 2), $matches) === 1
         ) {
            $expected = $separator + 4 + (int) $matches[1];
         }
      }
      if ($expected !== null && strlen($wire) >= $expected) {
         break;
      }
   }

   // :
   $separator = strpos($wire, "\r\n\r\n");
   $line = strpos($wire, "\r\n");

   return [
      'status' => (string) substr($wire, 9, 3),
      'line' => $line === false ? $wire : substr($wire, 0, $line),
      'head' => $separator === false ? $wire : substr($wire, 0, $separator + 4),
      'body' => $separator === false ? '' : substr($wire, $separator + 4),
      'closed' => $closed,
   ];
};

/**
 * Exchange one request on a fresh loopback connection, then leave.
 *
 * @return array{status:string,line:string,head:string,body:string,closed:bool}
 */
$Fetch = static function (int $port, string $request, float $timeout = 3.0) use ($Exchange): array {
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, $timeout);
   if ($Socket === false) {
      return ['status' => '', 'line' => '', 'head' => '', 'body' => '', 'closed' => true];
   }

   try {
      return $Exchange($Socket, $request, $timeout);
   }
   finally {
      fclose($Socket);
   }
};

/**
 * Read the server's `/m` route.
 *
 * @return null|array<string,mixed>
 */
$Measure = static function (int $port, float $timeout = 3.0) use ($Fetch): null|array {
   $exchange = $Fetch($port, "GET /m HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n", $timeout);
   $metrics = json_decode($exchange['body'], true);

   // ?:
   if ($exchange['status'] !== '200' || is_array($metrics) === false) {
      return null;
   }

   $metrics['status'] = $exchange['status'];

   return $metrics;
};

/**
 * Poll `/m` until every `$fields` value equals `$targets`.
 *
 * @param array<int,string> $fields
 * @param array<int,int> $targets
 *
 * @return null|array<string,mixed>
 */
$Settle = static function (int $port, array $fields, array $targets, float $timeout = 6.0) use ($Measure): null|array {
   $deadline = microtime(true) + $timeout;
   $metrics = null;

   // @@
   do {
      $metrics = $Measure($port, 2.0);
      if ($metrics !== null) {
         $values = [];
         foreach ($fields as $field) {
            $values[] = $metrics[$field] ?? null;
         }

         // ?:
         if ($values === $targets) {
            return $metrics;
         }
      }
      usleep(100_000);
   }
   while (microtime(true) < $deadline);

   // :
   return $metrics;
};

$Locate = static function () use ($Probe): int {
   // @@ The 19600-19699 range, starting at a random offset
   if ($Probe->cursor < 0) {
      $Probe->cursor = random_int(0, 99);
   }
   for ($attempt = 0; $attempt < 100; $attempt++) {
      $port = 19600 + ($Probe->cursor % 100);
      $Probe->cursor++;

      $Listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
      if ($Listener !== false) {
         fclose($Listener);

         return $port;
      }
   }

   throw new RuntimeException('H-HSC-5 found no free loopback port in 19600-19699.');
};

/**
 * Map every live process of one server child to its process group, read from
 * `/proc/<pid>/stat`: the tree rooted at `$root`, every member of `$groups`
 * and every PID of `$members` still in its recorded group. Zombies are dead
 * already and never listed.
 *
 * @param array<int,int> $groups
 * @param array<int,int> $members group by PID
 *
 * @return array<int,int> group by PID
 */
$Survey = static function (int $root, array $groups = [], array $members = []): array {
   // ! Every live process: PID => [parent, group]
   $table = [];
   foreach (scandir('/proc') ?: [] as $entry) {
      if (ctype_digit($entry) === false) {
         continue;
      }
      $stat = @file_get_contents("/proc/{$entry}/stat");
      $close = is_string($stat) ? strrpos($stat, ')') : false;
      if ($close === false) {
         continue;
      }
      // ! After `(comm)`: state, parent, group
      $fields = explode(' ', substr((string) $stat, $close + 2), 4);
      if (count($fields) < 3 || $fields[0] === 'Z' || $fields[0] === 'X') {
         continue;
      }
      $table[(int) $entry] = [(int) $fields[1], (int) $fields[2]];
   }

   // @@ The tree rooted at `$root`
   $live = [];
   $queue = [$root];
   while ($queue !== []) {
      $pid = array_shift($queue);
      if ($pid < 2 || isset($live[$pid]) || isset($table[$pid]) === false) {
         continue;
      }
      $live[$pid] = $table[$pid][1];
      foreach ($table as $child => [$parent]) {
         if ($parent === $pid) {
            $queue[] = $child;
         }
      }
   }

   // @@ Every member of the recorded groups, every recorded PID still in its group
   foreach ($table as $pid => [, $group]) {
      if (in_array($group, $groups, true) || ($members[$pid] ?? null) === $group) {
         $live[$pid] = $group;
      }
   }

   // :
   return $live;
};

/**
 * Stop one server child by the process groups it really runs in, then reap
 * it and return its log (ANSI-stripped) with what outlived the stop: any live
 * process of its tree or of its groups, and any listener on its port. The
 * row's temp directory is removed.
 *
 * @param array<string,mixed> $server
 *
 * @return array{log:string,groups:array<int,int>,survivors:array<int,int>,listening:bool}
 */
$Stop = static function (array $server) use ($Clean, $Survey): array {
   // !
   $wrapper = (int) ($server['wrapper'] ?? 0);
   $Process = $server['Process'] ?? null;
   $port = (int) ($server['port'] ?? 0);
   $own = posix_getpgid(0);
   /** @var array<int,int> $groups */
   $groups = $server['groups'] ?? [];
   /** @var array<int,int> $members */
   $members = $server['members'] ?? [];

   // @@ TERM, then KILL: each group the child tree runs in as a whole — never
   //   this process' own group, whose members only die by their PID
   foreach ([SIGTERM, SIGKILL] as $signal) {
      $live = $Survey($wrapper, $groups, $members);
      if ($live === []) {
         break;
      }
      $members = $live + $members;
      foreach ($live as $group) {
         if ($group > 1 && $group !== $own && in_array($group, $groups, true) === false) {
            $groups[] = $group;
         }
      }

      foreach ($groups as $group) {
         @posix_kill(-$group, $signal);
      }
      foreach ($live as $pid => $group) {
         if ($group === $own) {
            @posix_kill($pid, $signal);
         }
      }

      $deadline = microtime(true) + ($signal === SIGTERM ? 5.0 : 3.0);
      do {
         if (is_resource($Process)) {
            proc_get_status($Process);
         }
         if ($Survey($wrapper, $groups, $members) === []) {
            break 2;
         }
         usleep(50_000);
      }
      while (microtime(true) < $deadline);
   }

   // @ Reap
   if (is_resource($Process)) {
      $deadline = microtime(true) + 3.0;
      while (($status = proc_get_status($Process)) && ($status['running'] ?? false) && microtime(true) < $deadline) {
         usleep(50_000);
      }
      proc_close($Process);
   }

   // @ What outlived the stop: a process, or a listener on the row's port
   $survivors = $Survey($wrapper, $groups, $members);
   $Socket = $port > 0 ? @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.5) : false;
   $listening = is_resource($Socket);
   if (is_resource($Socket)) {
      fclose($Socket);
   }

   $log = (string) @file_get_contents((string) ($server['log'] ?? ''));
   $Clean((string) ($server['base'] ?? ''));

   // :
   return [
      'log' => (string) preg_replace('/\e\[[0-9;]*m/', '', $log),
      'groups' => $groups,
      'survivors' => $survivors,
      'listening' => $listening,
   ];
};

/**
 * Launch one server child for `$row`, wait until `/m` answers and record the
 * process groups its tree really runs in.
 *
 * @return array<string,mixed>
 */
$Launch = static function (string $row) use ($Probe, $Locate, $Measure, $Stop, $Survey): array {
   // !
   $port = $Locate();
   $base = "{$Probe->base}/{$row}";
   if (mkdir("{$base}/storage", 0700, true) === false) {
      throw new RuntimeException("H-HSC-5 could not create the {$row} server storage.");
   }
   $log = "{$base}/server.log";
   // ! Only what the server needs: the runner's own variables never reach it
   //   (its agent-output flag makes Bootgly's bootstrap call posix_setsid(),
   //   which would move the server out of the group stopped below)
   $environment = [];
   foreach (['PATH', 'HOME'] as $name) {
      $value = getenv($name);
      if (is_string($value) && $value !== '') {
         $environment[$name] = $value;
      }
   }

   // @ setsid: the child leads its own session and process group, so the
   //   group id is its PID; timeout bounds that group no matter what.
   $Process = proc_open(
      [
         'setsid',
         'timeout',
         '-s',
         'KILL',
         '120',
         PHP_BINARY,
         '-d',
         'memory_limit=128M',
         '-d',
         'opcache.jit=0',
         __FILE__,
         '--hhsc5-deferred-server',
         BOOTGLY_ROOT_BASE,
         $base,
         (string) $port,
         $row,
      ],
      [
         0 => ['file', '/dev/null', 'r'],
         1 => ['file', $log, 'a'],
         2 => ['file', $log, 'a'],
      ],
      $pipes,
      BOOTGLY_ROOT_BASE,
      $environment,
   );
   if (is_resource($Process) === false) {
      throw new RuntimeException("H-HSC-5 could not start the {$row} server child.");
   }
   $status = proc_get_status($Process);
   $server = [
      'row' => $row,
      'Process' => $Process,
      'wrapper' => (int) ($status['pid'] ?? 0),
      'groups' => [],
      'members' => [],
      'confined' => false,
      'base' => $base,
      'log' => $log,
      'port' => $port,
      'metrics' => null,
   ];

   // @@ Readiness
   $deadline = microtime(true) + 15.0;
   do {
      $metrics = $Measure($port, 1.0);
      if ($metrics !== null) {
         // @ The groups the tree really runs in — the wrapper, the server and
         //   its worker, as /proc lists them and as the worker reports itself
         $own = posix_getpgid(0);
         $worker = (int) ($metrics['pid'] ?? 0);
         $members = $Survey($server['wrapper']);
         $reported = [...array_values($members), (int) ($metrics['group'] ?? 0)];
         foreach ($reported as $group) {
            if ($group > 1 && $group !== $own && in_array($group, $server['groups'], true) === false) {
               $server['groups'][] = $group;
            }
         }
         $server['members'] = $members;
         // ? Confined: the worker is in the tree and every process of it runs
         //   in the wrapper's own group — the one `timeout` bounds
         $server['confined'] = $server['wrapper'] > 1
            && $server['wrapper'] !== $own
            && isset($members[$worker])
            && array_values(array_unique($reported)) === [$server['wrapper']];
         $server['metrics'] = $metrics;

         return $server;
      }
      if ((proc_get_status($Process)['running'] ?? false) === false) {
         break;
      }
      usleep(100_000);
   }
   while (microtime(true) < $deadline);

   $stopped = $Stop($server);
   $output = substr($stopped['log'], 0, 2_000);
   $survivors = (string) json_encode($stopped['survivors']);

   throw new RuntimeException("H-HSC-5 {$row} server never answered /m (survivors: {$survivors}): {$output}");
};

/**
 * Run one row: launch its server, talk to it, record the worker PID before
 * and after plus the log evidence, and always stop the server.
 */
$Run = static function (string $row, Closure $Attack) use ($Probe, $Launch, $Measure, $Stop, $Clean): string {
   // ! The case-wide temp area
   if ($Probe->base === '') {
      $token = bin2hex(random_bytes(6));
      $temporary = sys_get_temp_dir();
      $Probe->base = "{$temporary}/bootgly-hhsc5-deferred-{$token}";
      @mkdir($Probe->base, 0700, true);
   }

   $evidence = [
      'error' => '',
      'before' => 0,
      'after' => 0,
      'fatal' => -1,
      'fatality' => '',
      'confined' => false,
      'groups' => [],
      'survivors' => null,
      'listening' => null,
   ];
   $server = null;

   try {
      $server = $Launch($row);
      $evidence['before'] = (int) ($server['metrics']['pid'] ?? 0);

      $evidence = $Attack($server) + $evidence;

      $metrics = $Measure($server['port']);
      $evidence['after'] = (int) ($metrics['pid'] ?? 0);
   }
   catch (Throwable $Throwable) {
      $origin = $Throwable::class;
      $evidence['error'] = "{$origin}: {$Throwable->getMessage()}";
   }
   finally {
      if ($server !== null) {
         $stopped = $Stop($server);
         $log = $stopped['log'];
         $evidence['confined'] = $server['confined'];
         $evidence['groups'] = $stopped['groups'];
         $evidence['survivors'] = $stopped['survivors'];
         $evidence['listening'] = $stopped['listening'];
         $evidence['fatal'] = substr_count($log, 'Allowed memory size');
         if (preg_match('/^.*Allowed memory size.*$/m', $log, $matches) === 1) {
            $evidence['fatality'] = trim($matches[0]);
         }
      }
      $Probe->rows[$row] = $evidence;

      // ? The last row removes the case's temp area
      if ($row === 'D13') {
         $Clean($Probe->base);
      }
   }

   // :
   return "GET /hhsc5-deferred-harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
};

/**
 * Build one GET head of exactly `$lines` field lines — Host plus padding
 * fields — and exactly `$bytes` bytes. Every padding value starts with `$tag`,
 * so no two heads share their bytes.
 */
$Pad = static function (string $target, int $lines, int $bytes, string $tag): string {
   // !
   $start = "GET {$target} HTTP/1.1\r\nHost: localhost\r\n";
   $count = $lines - 1;
   $names = [];
   for ($index = 1; $index <= $count; $index++) {
      $names[] = "X-Pad-{$index}: ";
   }
   // ! Request line, Host, every padding name and CRLF, the final CRLF
   $room = $bytes - strlen($start) - strlen(implode('', $names)) - 2 * $count - 2;

   // ?
   if ($count < 1 || $room < $count * (strlen($tag) + 1)) {
      throw new RuntimeException("H-HSC-5 cannot pad {$lines} field lines into {$bytes} bytes.");
   }

   // @@ Spread the room over the padding values
   $each = intdiv($room, $count);
   $extra = $room % $count;
   $fields = '';
   foreach ($names as $index => $name) {
      $value = str_pad($tag, $each + ($index < $extra ? 1 : 0), 'v');
      $fields .= "{$name}{$value}\r\n";
   }

   // :
   return "{$start}{$fields}\r\n";
};

/**
 * Field lines of one request head (between the request line and the blank line).
 */
$Count = static function (string $head): int {
   return substr_count($head, "\r\n") - 2;
};

/**
 * Build one GET head to `$target` of Host plus one Cookie line of short
 * `c<n>=1` pairs, as many as fit just under the head byte limit.
 *
 * @return array{0:string,1:int} The head and its pair count.
 */
$Jar = static function (string $target) use ($limit): array {
   // !
   $prefix = "GET {$target} HTTP/1.1\r\nHost: localhost\r\nCookie: ";
   $length = strlen($prefix) + 4;
   $pairs = [];

   // @@
   for ($index = 0; ; $index++) {
      $pair = "c{$index}=1";
      $added = strlen($pair) + ($pairs === [] ? 0 : 2);
      if ($length + $added > $limit - 4) {
         break;
      }
      $pairs[] = $pair;
      $length += $added;
   }
   $jar = implode('; ', $pairs);

   // :
   return ["{$prefix}{$jar}\r\n\r\n", count($pairs)];
};

/**
 * Write one request whole on an open connection, reading nothing.
 */
$Send = static function (mixed $Socket, string $request, float $timeout = 3.0): bool {
   // !
   stream_set_blocking($Socket, false);
   $deadline = microtime(true) + $timeout;
   $offset = 0;
   $length = strlen($request);

   // @@ At most 1 MiB per write: a 10 MB request is never copied whole
   while ($offset < $length && microtime(true) < $deadline) {
      $Reads = null;
      $Writes = [$Socket];
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 50_000) < 1) {
         continue;
      }
      $written = @fwrite($Socket, substr($request, $offset, 1_048_576));
      if ($written === false) {
         return false;
      }
      $offset += $written;
   }

   // :
   return $offset === $length;
};

/**
 * Whether one peer's bytes hold a whole answer: its head, and exactly
 * Content-Length body bytes when it declares them.
 */
$Complete = static function (string $wire): bool {
   // !
   $separator = strpos($wire, "\r\n\r\n");

   // ?:
   if ($separator === false) {
      return false;
   }
   if (preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', substr($wire, 0, $separator + 2), $matches) !== 1) {
      return true;
   }

   // :
   return strlen($wire) >= $separator + 4 + (int) $matches[1];
};

/**
 * Read the peers' answers for up to `$timeout` seconds — into `$wires`, by
 * peer — until `$wanted` of them hold a whole answer; returns how many do.
 *
 * @param array<int,resource> $Sockets
 * @param array<int,string> $wires
 */
$Gather = static function (array $Sockets, array &$wires, int $wanted, float $timeout) use ($Complete): int {
   // !
   $deadline = microtime(true) + $timeout;
   $done = 0;

   // @@
   do {
      $done = 0;
      $Reads = [];
      foreach ($Sockets as $index => $Socket) {
         if ($Complete($wires[$index] ?? '')) {
            $done++;
            continue;
         }
         $Reads[$index] = $Socket;
      }
      if ($done >= $wanted || $Reads === []) {
         break;
      }

      $Writes = null;
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 50_000) > 0) {
         foreach ($Reads as $index => $Socket) {
            $chunk = @fread($Socket, 65_536);
            if (is_string($chunk)) {
               $wires[$index] ??= '';
               $wires[$index] .= $chunk;
            }
         }
      }
   }
   while (microtime(true) < $deadline);

   // :
   return $done;
};

/**
 * Label one peer's answer: `200 <body>`, or the bare status of anything
 * else (`''` when nothing came back).
 */
$Label = static function (string $wire): string {
   // !
   $status = (string) substr($wire, 9, 3);
   $separator = strpos($wire, "\r\n\r\n");
   $body = $separator === false ? '' : substr($wire, $separator + 4);

   // :
   return $status === '200' ? "200 {$body}" : $status;
};

/**
 * Park one deferral per request on its own keep-alive peer, wake them all
 * through `/w` and read every answer. The peers stay connected in
 * `$Sockets`: the caller closes them.
 *
 * @param array<int,string> $requests
 * @param array<int,resource> $Sockets
 *
 * @return array<string,mixed>
 */
$Cycle = static function (int $port, array $requests, array &$Sockets) use ($Fetch, $Settle, $Send, $Gather, $Label): array {
   // !
   $peers = count($requests);
   $wires = [];

   // @@ Every peer sends its head and parks
   foreach ($requests as $index => $request) {
      $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
      if ($Socket === false) {
         throw new RuntimeException("H-HSC-5 deferred peer {$index} could not connect: {$message}");
      }
      $Sockets[$index] = $Socket;
      $wires[$index] = '';
      $Send($Socket, $request);
   }
   $parked = $Settle($port, ['parked'], [$peers], 8.0) ?? [];
   $early = $Gather($Sockets, $wires, $peers, 0.0);

   // @ Wake them all, then read every answer
   $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\n\r\n");
   $Gather($Sockets, $wires, $peers, 8.0);

   // :
   return [
      'parked' => $parked['parked'] ?? null,
      'held' => $parked['inbound'] ?? null,
      'early' => $early,
      'wake' => "{$wake['status']} {$wake['body']}",
      'answers' => array_count_values(array_map($Label, $wires)),
      'sample' => [(string) ($parked['status'] ?? 'none'), (int) ($parked['pid'] ?? 0)],
   ];
};


/**
 * The memory PHP's allocator spends to keep an array of parsed values, as the
 * fix charges it (`Buffers::tally()`); -1 where it does not exist.
 *
 * @param array<mixed> $values
 */
$Tally = static function (array $values): int {
   return method_exists(Buffers::class, 'tally') ? Buffers::tally($values) : -1;
};

/**
 * The tier at which `Request::check()` decides a JSON body against a bodies
 * share of `$share` bytes, and the price it decides on — its own private
 * `price()` and `walk()` (through reflection) behind a mirror of its gates —
 * next to the verdict `check()` itself gives a request carrying the body as
 * `$type` at that share (`checked`; `null` where it does not exist):
 *
 * - invalid JSON passes, `input()` decodes nothing from it (`invalid`; its
 *   price is the byte-by-byte one the tier before refused it at);
 * - valid JSON has its escaped backslashes, then escaped quotes, turned into
 *   two bytes that are no digit (`__`), then every string is emptied but a
 *   key of up to 20 digits and minus signs (the shape), and it is
 *   accepted under the quick bound (`quick`) unless an object begins as a
 *   list (a first key from 0 to 7: `listed`, left to the walk), refused over
 *   the least its filled containers spend (`least`), or priced container by
 *   container (`walk` — a kept key followed through `Buffers\Table`).
 *
 * `none` where the tiers do not exist.
 *
 * @return array{tier:string,price:int,refused:bool,listed:bool,checked:null|bool}
 */
$Bound = static function (string $raw, int $share, string $type = 'application/json'): array {
   // !
   try {
      $Price = new ReflectionMethod(Request::class, 'price');
      $Walk = new ReflectionMethod(Request::class, 'walk');
   }
   catch (Throwable) {
      return ['tier' => 'none', 'price' => -1, 'refused' => false, 'listed' => false, 'checked' => null];
   }
   $bytes = strlen($raw);

   // @ The verdict of `check()` itself, at a budget of twice the share
   $checked = null;
   if (method_exists(Request::class, 'check')) {
      $Request = new Request;
      $Request->reset();
      $Request->Header->adopt(['content-type' => $type]);
      $Request->Body->raw = $raw;
      $Request->Body->length = $bytes;
      $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
      try {
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $share;
         $checked = $Request->check();
      }
      finally {
         TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
      }
   }

   // ? Invalid JSON passes
   if (json_validate($raw) === false) {
      $price = (int) $Price->invoke(null, $raw, $bytes);

      return ['tier' => 'invalid', 'price' => $price, 'refused' => false, 'listed' => false, 'checked' => $checked];
   }

   // ! The shape: escaped backslashes and quotes turned into `__` (a key
   //   that held them is never kept), every string emptied but a key of up
   //   to 20 digits and minus signs (escaped or not)
   $shape = str_contains($raw, '\\')
      ? str_replace(['\\\\', '\\"'], '__', $raw)
      : $raw;
   $shape = preg_replace('/"((?:[0-9-]|\\\\u00(?:3[0-9]|2[dD])){1,20}+)"(?=\\s*+:)|"[^"]*+"/', '"$1"', $shape);
   if ($shape === null) {
      return ['tier' => 'pcre', 'price' => -1, 'refused' => true, 'listed' => false, 'checked' => $checked];
   }
   $content = $bytes - strlen($shape);
   $listed = preg_match('/\\{\\s*+"(?:[0-7]|\\\\u003[0-7])"/', $shape) === 1;

   // ? Under the quick bound, unless an object begins as a list
   $quick = (int) $Price->invoke(null, $shape, $content);
   if ($listed === false && $quick <= $share) {
      return ['tier' => 'quick', 'price' => $quick, 'refused' => false, 'listed' => false, 'checked' => $checked];
   }
   // ? Over what its filled containers spend at least
   $containers = substr_count($shape, '[') + substr_count($shape, '{');
   $empty = strcspn($shape, " \t\r\n") === strlen($shape)
      ? substr_count($shape, '[]') + substr_count($shape, '{}')
      : (int) preg_match_all('/\\[\\s*+\\]|\\{\\s*+\\}/', $shape);
   $filled = $containers - $empty;
   $least = max(216 * $filled, 72 * $filled + 16 * substr_count($shape, ','));
   if ($least > $share) {
      return ['tier' => 'least', 'price' => $least, 'refused' => true, 'listed' => $listed, 'checked' => $checked];
   }

   // :
   $walk = (int) $Walk->invoke(null, $shape, $content);

   return ['tier' => 'walk', 'price' => $walk, 'refused' => $walk > $share, 'listed' => $listed, 'checked' => $checked];
};

/**
 * Open one loopback peer with a 4 KiB receive buffer (ext-sockets, set before
 * the connect), as a nonblocking stream: a peer that does not read soon
 * leaves the server owing it output. The socket is kept in `$Holds`.
 *
 * @param array<int,Socket> $Holds
 *
 * @return resource
 */
$Slow = static function (int $port, array &$Holds): mixed {
   // ?
   if (function_exists('socket_create') === false) {
      throw new RuntimeException('H-HSC-5 slow peers need ext-sockets.');
   }

   // !
   $Socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
   if ($Socket === false) {
      throw new RuntimeException('H-HSC-5 could not create a slow peer socket.');
   }
   socket_set_option($Socket, SOL_SOCKET, SO_RCVBUF, 4_096);

   // @
   if (@socket_connect($Socket, '127.0.0.1', $port) === false) {
      throw new RuntimeException('H-HSC-5 slow peer could not connect.');
   }
   $Stream = socket_export_stream($Socket);
   if ($Stream === false) {
      throw new RuntimeException('H-HSC-5 could not export a slow peer stream.');
   }
   stream_set_blocking($Stream, false);
   $Holds[] = $Socket;

   // :
   return $Stream;
};

/**
 * Read every peer until the server closes it, for up to `$timeout` seconds.
 *
 * @param array<int,resource> $Streams
 *
 * @return array{0:array<int,string>,1:array<int,bool>} The wires and which closed.
 */
$Drain = static function (array $Streams, float $timeout): array {
   // !
   $wires = array_fill_keys(array_keys($Streams), '');
   $closed = [];
   $deadline = microtime(true) + $timeout;

   // @@
   while (count($closed) < count($Streams) && microtime(true) < $deadline) {
      $Reads = array_diff_key($Streams, $closed);
      $Writes = null;
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 50_000) < 1) {
         continue;
      }
      foreach ($Reads as $index => $Stream) {
         $chunk = @fread($Stream, 1 << 20);
         if ($chunk === false || ($chunk === '' && feof($Stream))) {
            $closed[$index] = true;
            continue;
         }
         $wires[$index] .= $chunk;
      }
   }

   // :
   $flags = [];
   foreach (array_keys($Streams) as $index) {
      $flags[$index] = isSet($closed[$index]);
   }

   return [$wires, $flags];
};

/**
 * Split one peer's wire into its answers, each labeled `<status> <body
 * bytes>`; a head without Content-Length takes the rest of the wire as its
 * body, a cut answer is `partial`.
 *
 * @return array<int,string>
 */
$Split = static function (string $wire): array {
   // !
   $labels = [];
   $offset = 0;
   $total = strlen($wire);

   // @@
   while ($offset < $total) {
      $separator = strpos($wire, "\r\n\r\n", $offset);
      if ($separator === false) {
         $labels[] = 'partial';
         break;
      }
      $head = substr($wire, $offset, $separator + 2 - $offset);
      $end = preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) === 1
         ? $separator + 4 + (int) $matches[1]
         : $total;
      $status = substr($wire, $offset + 9, 3);
      $bytes = $end - $separator - 4;
      $labels[] = $end > $total ? 'partial' : "{$status} {$bytes}";
      $offset = $end;
   }

   // :
   return $labels;
};

/**
 * Build one POST of `$body` as `$type` to `$target`, its headers in `$extra`.
 */
$Post = static function (string $target, string $type, string $body, string $extra = ''): string {
   $length = strlen($body);

   return "POST {$target} HTTP/1.1\r\nHost: localhost\r\n{$extra}Content-Type: {$type}\r\nContent-Length: {$length}\r\n\r\n{$body}";
};

return new Test(
   description: 'H-HSC-5: deferred request heads are charged while parked and shed from the live connection',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => $Run('D1', static function (array $server) use ($Fetch, $Measure, $Settle, $Send, $Gather, $Label, $Count, $Jar): array {
         // !
         $port = (int) $server['port'];
         $peers = 60;
         // ! One Cookie line of short pairs, just under the head byte limit
         [$head, $jars] = $Jar('/d');
         $base = $Measure($port) ?? [];

         $Sockets = [];
         $wires = [];
         $parked = [];
         $immediate = [];
         $wake = ['status' => '', 'body' => ''];
         try {
            // @@ Every peer sends its head; none reads yet
            for ($index = 0; $index < $peers; $index++) {
               $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
               if ($Socket === false) {
                  throw new RuntimeException("H-HSC-5 D1 peer {$index} could not connect: {$message}");
               }
               $Sockets[$index] = $Socket;
               $wires[$index] = '';
               $Send($Socket, $head);
            }

            // @@ Until every peer is parked or answered
            $deadline = microtime(true) + 8.0;
            do {
               $answered = $Gather($Sockets, $wires, $peers, 0.2);
               $parked = $Measure($port) ?? [];
            }
            while ($answered + (int) ($parked['parked'] ?? 0) < $peers && microtime(true) < $deadline);
            $immediate = array_count_values(array_map($Label, array_filter($wires, static fn (string $wire): bool => $wire !== '')));

            // @ Wake every parked deferral, then read every answer
            $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $Gather($Sockets, $wires, $peers, 8.0);
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle($port, ['inbound', 'parked'], [(int) ($base['inbound'] ?? -1), 0]) ?? [];

         // :
         return [
            'shape' => [$Count($head), strlen($head), $jars],
            'peers' => $peers,
            'budget' => $base['budget'] ?? null,
            'base' => $base['inbound'] ?? null,
            'immediate' => $immediate,
            'parked' => $parked['parked'] ?? null,
            'held' => $parked['inbound'] ?? null,
            'captured' => $parked['captured'] ?? null,
            'prices' => $parked['prices'] ?? null,
            'charges' => $parked['charges'] ?? null,
            'holds' => $parked['holds'] ?? null,
            'tallies' => $parked['tallies'] ?? null,
            'wake' => "{$wake['status']} {$wake['body']}",
            'answers' => array_count_values(array_map($Label, $wires)),
            'released' => $released['inbound'] ?? null,
            'remaining' => $released['parked'] ?? null,
            // ! Informational: worker memory at rest, parked and released
            'usage' => [$base['usage'] ?? null, $parked['usage'] ?? null, $released['usage'] ?? null],
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($parked['status'] ?? 'none'), (int) ($parked['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D2', static function (array $server) use ($Measure, $Settle, $Cycle, $Pad, $Count, $limit, $cap): array {
         // !
         $port = (int) $server['port'];
         $peers = 200;
         $bytes = $limit - 4;
         $warmups = [];
         $requests = [];
         for ($index = 0; $index < $peers; $index++) {
            $warmups[] = $Pad('/p', $cap, $bytes, "warm{$index}");
            $requests[] = $Pad('/p', $cap, $bytes, "peer{$index}");
         }
         // ! A pooled Fiber keeps its last deferral's snapshot until its next
         //   job: 200 tiny deferrals on peers that leave at once replace them,
         //   so only the live connections differ between the two readings
         $Flush = static function (string $tag) use ($port, $peers, $Cycle): array {
            $Tiny = [];
            $tiny = [];
            for ($index = 0; $index < $peers; $index++) {
               $tiny[] = "GET /p?{$tag}={$index} HTTP/1.1\r\nHost: localhost\r\n\r\n";
            }
            try {
               $flush = $Cycle($port, $tiny, $Tiny);
            }
            finally {
               foreach ($Tiny as $Socket) {
                  fclose($Socket);
               }
            }

            return $flush;
         };

         // @ Warm-up: the same cycle fills the Fiber pool, then a flush
         $Warm = [];
         try {
            $warm = $Cycle($port, $warmups, $Warm);
         }
         finally {
            foreach ($Warm as $Socket) {
               fclose($Socket);
            }
         }
         $warmed = $Settle($port, ['connections', 'parked'], [1, 0]) ?? [];
         $first = $Flush('first');
         $before = $Settle($port, ['connections', 'parked'], [1, 0]) ?? [];

         // @ The measured cycle: every peer stays connected, idle, once
         //   answered; a flush on other peers, then the reading
         $Sockets = [];
         $after = [];
         try {
            $cycle = $Cycle($port, $requests, $Sockets);
            $second = $Flush('second');
            $after = $Settle($port, ['connections', 'parked'], [$peers + 1, 0]) ?? [];
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle($port, ['connections'], [1]) ?? [];

         // :
         $grown = (int) ($after['usage'] ?? 0) - (int) ($before['usage'] ?? 0);
         $kept = (int) ($warmed['usage'] ?? 0) - (int) ($before['usage'] ?? 0);

         return [
            'shape' => [$Count($requests[0]), strlen($requests[0])],
            'peers' => $peers,
            'cycles' => array_map(
               static fn (array $cycle): array => [$cycle['parked'], $cycle['early'], $cycle['wake'], $cycle['answers']],
               ['warm' => $warm, 'first' => $first, 'measured' => $cycle, 'second' => $second],
            ),
            'held' => $cycle['held'],
            'pooled' => intdiv($kept, $peers),
            'live' => $after['live'] ?? null,
            'idle' => (int) ($after['connections'] ?? -1) - (int) ($before['connections'] ?? 0),
            'usage' => [$warmed['usage'] ?? null, $before['usage'] ?? null, $after['usage'] ?? null],
            'growth' => intdiv($grown, $peers),
            'released' => $released['connections'] ?? null,
            'samples' => [
               $warm['sample'],
               [(string) ($warmed['status'] ?? 'none'), (int) ($warmed['pid'] ?? 0)],
               $first['sample'],
               [(string) ($before['status'] ?? 'none'), (int) ($before['pid'] ?? 0)],
               $cycle['sample'],
               $second['sample'],
               [(string) ($after['status'] ?? 'none'), (int) ($after['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D3', static function (array $server) use ($Measure, $Settle, $Cycle, $Jar): array {
         // !
         $port = (int) $server['port'];
         $rounds = 4;
         $heavies = 50;
         $peers = 2 * $heavies;
         [$heavy, $jars] = $Jar('/d');
         // ! One round: heavy (parsed jar) and cheap deferrals interleaved,
         //   every peer leaving once answered
         $Round = static function (array $requests) use ($port, $Cycle): array {
            $Sockets = [];
            try {
               $cycle = $Cycle($port, $requests, $Sockets);
            }
            finally {
               foreach ($Sockets as $Socket) {
                  fclose($Socket);
               }
            }

            return [$cycle['parked'], $cycle['early'], $cycle['wake'], $cycle['answers'], $cycle['held'], $cycle['sample']];
         };
         $Cheap = static fn (string $tag, int $index): string => "GET /p?{$tag}={$index} HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // @ Warm-up: as many cheap deferrals at once fill the Fiber pool
         $fresh = $Measure($port) ?? [];
         $warmups = [];
         for ($index = 0; $index < $peers; $index++) {
            $warmups[] = $Cheap('warm', $index);
         }
         $warm = $Round($warmups);
         $base = $Settle($port, ['connections', 'parked'], [1, 0]) ?? [];
         $inbound = (int) ($base['inbound'] ?? -1);

         // @@ The rounds, each read once the ledger is back at its base
         $cycles = [];
         $readings = [];
         $samples = [[(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)]];
         for ($round = 1; $round <= $rounds; $round++) {
            $requests = [];
            for ($index = 0; $index < $heavies; $index++) {
               $requests[] = $heavy;
               $requests[] = $Cheap("r{$round}", $index);
            }
            $cycle = $Round($requests);
            $rest = $Settle($port, ['connections', 'parked', 'inbound'], [1, 0, $inbound]) ?? [];

            $cycles[] = array_slice($cycle, 0, 4);
            $readings[] = [$rest['usage'] ?? null, $rest['inbound'] ?? null, $cycle[4]];
            $samples[] = $cycle[5];
            $samples[] = [(string) ($rest['status'] ?? 'none'), (int) ($rest['pid'] ?? 0)];
         }

         // :
         $final = (int) ($readings[$rounds - 1][0] ?? 0);

         return [
            'jars' => $jars,
            'rounds' => $rounds,
            'heavies' => $heavies,
            'warm' => array_slice($warm, 0, 4),
            'cycles' => $cycles,
            'fresh' => $fresh['usage'] ?? null,
            'base' => [$base['usage'] ?? null, $inbound],
            'readings' => $readings,
            'growth' => $final - (int) ($base['usage'] ?? 0),
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('D4', static function (array $server) use ($Fetch, $Measure, $Settle, $Send, $Gather, $Label): array {
         // !
         $port = (int) $server['port'];
         $peers = 16;
         $count = 9;
         $size = 1_000_000;
         // ! 9 text fields of 1,000,000 bytes (under `maxMultipartFieldSize`)
         $value = str_repeat('x', $size);
         $parts = '';
         for ($index = 0; $index < $count; $index++) {
            $parts .= "--hhsc5\r\nContent-Disposition: form-data; name=\"f{$index}\"\r\n\r\n{$value}\r\n";
         }
         $body = "{$parts}--hhsc5--\r\n";
         $length = strlen($body);
         $Upload = static fn (string $method): string => "{$method} /u HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=hhsc5\r\nContent-Length: {$length}\r\n\r\n{$body}";
         $base = $Measure($port) ?? [];

         $Sockets = [];
         $wires = [];
         $outcomes = [];
         $rest = [];
         $wake = ['status' => '', 'body' => ''];
         try {
            // @@ One peer at a time — the first a GET — each parked or
            //   answered before the next one sends
            $parked = (int) ($base['parked'] ?? 0);
            for ($index = 0; $index < $peers; $index++) {
               $method = $index === 0 ? 'GET' : 'POST';
               $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
               if ($Socket === false) {
                  $outcomes[] = "{$method} unconnected";
                  continue;
               }
               $Sockets[$index] = $Socket;
               $wires[$index] = '';
               $Send($Socket, $Upload($method), 10.0);

               $outcome = 'silent';
               $deadline = microtime(true) + 8.0;
               do {
                  if ($Gather([$index => $Socket], $wires, 1, 0.1) === 1) {
                     $outcome = $Label($wires[$index]);
                     break;
                  }
                  if (feof($Socket)) {
                     $outcome = 'closed';
                     break;
                  }
                  $metrics = $Measure($port, 2.0);
                  if ($metrics !== null && (int) ($metrics['parked'] ?? 0) !== $parked) {
                     $parked = (int) $metrics['parked'];
                     $outcome = 'parked';
                     break;
                  }
               }
               while (microtime(true) < $deadline);
               $outcomes[] = "{$method} {$outcome}";
            }
            $rest = $Measure($port) ?? [];

            // @ Wake every parked deferral, then read every answer
            $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $Gather($Sockets, $wires, count($Sockets), 8.0);
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle($port, ['inbound', 'parked'], [(int) ($base['inbound'] ?? -1), 0]) ?? [];

         // :
         return [
            'shape' => [$count, $size, $length, $peers],
            'budget' => $base['budget'] ?? null,
            'base' => $base['inbound'] ?? null,
            'outcomes' => $outcomes,
            'parked' => $rest['parked'] ?? null,
            'held' => $rest['inbound'] ?? null,
            'captured' => $rest['captured'] ?? null,
            'prices' => $rest['prices'] ?? null,
            'charges' => $rest['charges'] ?? null,
            'holds' => $rest['holds'] ?? null,
            'tallies' => $rest['tallies'] ?? null,
            'wake' => "{$wake['status']} {$wake['body']}",
            'answers' => array_count_values(array_map($Label, $wires)),
            'released' => $released['inbound'] ?? null,
            'remaining' => $released['parked'] ?? null,
            // ! Informational: worker memory at rest, parked and released
            'usage' => [$base['usage'] ?? null, $rest['usage'] ?? null, $released['usage'] ?? null],
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($rest['status'] ?? 'none'), (int) ($rest['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D5', static function (array $server) use ($Fetch, $Measure, $Settle, $Send, $Gather, $Label, $Tally, $Post): array {
         // !
         $port = (int) $server['port'];
         $peers = 12;
         $vars = 990;
         $depth = 40;
         // ! 990 vars nested 40 deep (under `max_input_vars` and
         //   `max_input_nesting_level`), as a form and as JSON
         $nest = str_repeat('[a]', $depth);
         $open = str_repeat('{"a":', $depth);
         $close = str_repeat('}', $depth);
         $pairs = [];
         $members = [];
         for ($index = 0; $index < $vars; $index++) {
            $pairs[] = "k{$index}{$nest}=v";
            $members[] = "\"k{$index}\":{$open}\"v\"{$close}";
         }
         $form = implode('&', $pairs);
         $object = implode(',', $members);
         $bodies = [
            'form' => ['application/x-www-form-urlencoded', $form],
            'json' => ['application/json', "{{$object}}"],
         ];
         // ! What each parse keeps, at the fix's measure — the same PHP parses
         //   it here
         parse_str($form, $parsed);
         $predicted = [
            'form' => $Tally($parsed),
            'json' => $Tally((array) json_decode($bodies['json'][1], true)),
         ];
         unset($parsed);
         $base = $Measure($port) ?? [];

         $Sockets = [];
         $wires = [];
         $kinds = [];
         $outcomes = [];
         $usages = [];
         $rest = [];
         $wake = ['status' => '', 'body' => ''];
         try {
            // @@ One peer at a time, a form then a JSON body, each parked or
            //   answered before the next one sends
            $parked = (int) ($base['parked'] ?? 0);
            for ($index = 0; $index < $peers; $index++) {
               $kind = $index % 2 === 0 ? 'form' : 'json';
               [$type, $body] = $bodies[$kind];
               $kinds[$index] = $kind;
               $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
               if ($Socket === false) {
                  $outcomes[$index] = 'unconnected';
                  continue;
               }
               $Sockets[$index] = $Socket;
               $wires[$index] = '';
               $Send($Socket, $Post('/f', $type, $body, "X-Peer: {$index}\r\n"), 10.0);

               $outcome = 'silent';
               $deadline = microtime(true) + 8.0;
               do {
                  if ($Gather([$index => $Socket], $wires, 1, 0.1) === 1) {
                     $outcome = $Label($wires[$index]);
                     break;
                  }
                  if (feof($Socket)) {
                     $outcome = 'closed';
                     break;
                  }
                  $metrics = $Measure($port, 2.0);
                  if ($metrics !== null && (int) ($metrics['parked'] ?? 0) !== $parked) {
                     $parked = (int) $metrics['parked'];
                     $outcome = 'parked';
                     break;
                  }
               }
               while (microtime(true) < $deadline);
               $outcomes[$index] = $outcome;
               // ! Informational: worker memory once each peer settled
               $usages[] = $Measure($port, 2.0)['usage'] ?? null;
            }
            $rest = $Measure($port) ?? [];

            // @ Wake every parked deferral, then read every answer
            $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $Gather($Sockets, $wires, count($Sockets), 8.0);
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle($port, ['inbound', 'parked'], [(int) ($base['inbound'] ?? -1), 0]) ?? [];

         // :
         return [
            'shape' => [$vars, $depth, strlen($form), strlen($bodies['json'][1]), $peers],
            'budget' => $base['budget'] ?? null,
            'base' => $base['inbound'] ?? null,
            'predicted' => $predicted,
            'kinds' => $kinds,
            'outcomes' => $outcomes,
            'attempts' => $rest['attempts'] ?? null,
            'parses' => $rest['parses'] ?? null,
            'parked' => $rest['parked'] ?? null,
            'held' => $rest['inbound'] ?? null,
            'wake' => "{$wake['status']} {$wake['body']}",
            'answers' => array_count_values(array_map($Label, $wires)),
            'released' => $released['inbound'] ?? null,
            'remaining' => $released['parked'] ?? null,
            // ! Informational: worker memory at rest, after each peer, parked
            //   and released
            'usage' => [$base['usage'] ?? null, $usages, $rest['usage'] ?? null, $released['usage'] ?? null],
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($rest['status'] ?? 'none'), (int) ($rest['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D6', static function (array $server) use ($Fetch, $Measure, $Bound, $Post, $default): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($default, 2);
         // ! 290,000 one-element arrays — `[[0],[0],…]`, ~1.16 MB
         $cells = implode(',', array_fill(0, 290_000, '[0]'));
         $heavy = "[{$cells}]";
         // ! An ordinary ~200 KB JSON body: 2,000 records
         $records = [];
         for ($index = 0; $index < 2_000; $index++) {
            $name = str_pad("item-{$index}-", 72, 'x');
            $records[] = "{\"id\":{$index},\"name\":\"{$name}\"}";
         }
         $items = implode(',', $records);
         $normal = "{\"items\":[{$items}]}";
         $digest = md5((string) json_encode(json_decode($normal, true)));
         $base = $Measure($port) ?? [];

         // @ The [[0],…] body to a route that reads its fields, then to one
         //   that does not — each on its own keep-alive connection
         $reading = $Fetch($port, $Post('/j', 'application/json', $heavy), 8.0);
         $ignoring = $Fetch($port, $Post('/n', 'application/json', $heavy), 8.0);
         $refused = $Measure($port) ?? [];

         // @ The ordinary body to the reading route
         $answered = $Fetch($port, $Post('/j', 'application/json', $normal), 8.0);
         $after = $Measure($port) ?? [];

         // :
         return [
            'shape' => [strlen($heavy), $Bound($heavy, $share), strlen($normal), $Bound($normal, $share)],
            'budget' => $base['budget'] ?? null,
            'reading' => [$reading['status'], $reading['closed']],
            'ignoring' => [$ignoring['status'], $ignoring['closed']],
            'normal' => [$answered['status'], $answered['body']],
            'digest' => $digest,
            'handled' => [$base['handled'] ?? null, $refused['handled'] ?? null, $after['handled'] ?? null],
            // ! Informational: worker memory at rest, after the refusals and
            //   after the ordinary body; its peak
            'usage' => [$base['usage'] ?? null, $refused['usage'] ?? null, $after['usage'] ?? null],
            'peak' => $after['peak'] ?? null,
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($refused['status'] ?? 'none'), (int) ($refused['pid'] ?? 0)],
               [(string) ($after['status'] ?? 'none'), (int) ($after['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D7', static function (array $server) use ($Measure, $Settle, $Send, $Slow, $Drain, $Split, $Bound, $Post, $owed, $default): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($default, 2);
         $peers = 14;
         // ! 4,999,999 zeros — `[0,0,…]`, 9,999,999 bytes (under `maxBodySize`)
         $body = '[' . implode(',', array_fill(0, 4_999_999, '0')) . ']';
         $gets = str_repeat("GET /o HTTP/1.1\r\nHost: localhost\r\n\r\n", $owed);
         $request = $gets . $Post('/n', 'application/json', $body);
         $base = $Measure($port) ?? [];
         $pid = (int) ($base['pid'] ?? 0);

         $Holds = [];
         $Streams = [];
         $steps = [];
         $samples = [[(string) ($base['status'] ?? 'none'), $pid]];
         $answers = [];
         $held = [];
         try {
            // @@ One peer at a time: its GETs and its body, then the reading
            //   once its body is whole
            for ($index = 0; $index < $peers; $index++) {
               $Streams[$index] = $Slow($port, $Holds);
               $sent = $Send($Streams[$index], $request, 20.0);
               $step = $Settle($port, ['bodies'], [$index + 1], 6.0) ?? [];
               $steps[] = [
                  'sent' => $sent,
                  'bodies' => $step['bodies'] ?? null,
                  'raws' => $step['raws'] ?? null,
                  'transport' => $step['transport'] ?? null,
                  'connections' => $step['connections'] ?? null,
                  'usage' => $step['usage'] ?? null,
               ];
               $samples[] = [(string) ($step['status'] ?? 'none'), (int) ($step['pid'] ?? 0)];
               // ? A dead worker answers no more of this row's peers
               if ((int) ($step['pid'] ?? 0) !== $pid) {
                  break;
               }
            }
            $held = $Measure($port) ?? [];
            $samples[] = [(string) ($held['status'] ?? 'none'), (int) ($held['pid'] ?? 0)];

            // @@ Every peer reads now, one at a time: its answers, then the
            //   refusal and the close
            foreach ($Streams as $index => $Stream) {
               [$wires, $closed] = $Drain([$index => $Stream], 10.0);
               $labels = $Split($wires[$index]);
               $last = strrpos($wires[$index], 'HTTP/1.1 ');
               $answers[$index] = [
                  'answered' => count(array_keys($labels, '200 65536', true)),
                  'others' => array_values(array_filter($labels, static fn (string $label): bool => $label !== '200 65536')),
                  'tail' => $last === false ? '' : substr($wires[$index], $last, 64),
                  'closed' => $closed[$index],
               ];
               $wires = [];
            }
         }
         finally {
            foreach ($Streams as $Stream) {
               @fclose($Stream);
            }
            $Holds = [];
         }
         $released = $Settle($port, ['connections'], [1]) ?? [];
         $samples[] = [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)];

         // :

         return [
            'shape' => [$peers, $owed, strlen($body), $Bound($body, $share)],
            'budget' => $base['budget'] ?? null,
            'base' => [$base['connections'] ?? null, $base['transport'] ?? null, $base['raws'] ?? null],
            'steps' => $steps,
            'held' => [$held['connections'] ?? null, $held['bodies'] ?? null, $held['raws'] ?? null, $held['transport'] ?? null],
            'answers' => $answers,
            'handled' => [$base['handled'] ?? null, $held['handled'] ?? null, $released['handled'] ?? null],
            'released' => [$released['connections'] ?? null, $released['raws'] ?? null],
            // ! Informational: worker memory at rest, with every peer held and
            //   released; its peak
            'usage' => [$base['usage'] ?? null, $held['usage'] ?? null, $released['usage'] ?? null],
            'peak' => $released['peak'] ?? null,
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('D8', static function (array $server) use ($Measure, $Settle, $Send, $Slow, $Drain, $Split, $Bound, $Post, $owed, $default): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($default, 2);
         // ! D6's `[[0],[0],…]` body, then a pipelined GET to the counting route
         $cells = implode(',', array_fill(0, 290_000, '[0]'));
         $heavy = "[{$cells}]";
         $gets = str_repeat("GET /o HTTP/1.1\r\nHost: localhost\r\n\r\n", $owed);
         $pipelined = "GET /c HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $base = $Measure($port) ?? [];

         $Holds = [];
         $Streams = [];
         $refused = [];
         $wires = [''];
         $closed = [false];
         $sent = false;
         try {
            $Streams[0] = $Slow($port, $Holds);
            // @ The unread GETs, then — in one write — the body and the GET
            $sent = $Send($Streams[0], $gets, 10.0)
               && $Send($Streams[0], $Post('/n', 'application/json', $heavy) . $pipelined, 10.0);
            $refused = $Settle($port, ['bodies'], [1], 6.0) ?? [];

            // @ The peer reads now: its answers, then the refusal and close
            [$wires, $closed] = $Drain($Streams, 20.0);
         }
         finally {
            foreach ($Streams as $Stream) {
               @fclose($Stream);
            }
            $Holds = [];
         }
         $released = $Settle($port, ['connections'], [1]) ?? [];

         // :
         $labels = $Split($wires[0]);
         $last = strrpos($wires[0], 'HTTP/1.1 ');

         return [
            'shape' => [$owed, strlen($heavy), $Bound($heavy, $share)],
            'budget' => $base['budget'] ?? null,
            'sent' => $sent,
            'owed' => [$base['transport'] ?? null, $refused['transport'] ?? null],
            'refused' => [$refused['connections'] ?? null, $refused['bodies'] ?? null, $refused['raws'] ?? null],
            'counted' => [$base['counted'] ?? null, $refused['counted'] ?? null, $released['counted'] ?? null],
            'handled' => [$base['handled'] ?? null, $refused['handled'] ?? null, $released['handled'] ?? null],
            'answered' => count(array_keys($labels, '200 65536', true)),
            'others' => array_values(array_filter($labels, static fn (string $label): bool => $label !== '200 65536')),
            'tail' => $last === false ? '' : substr($wires[0], $last, 64),
            'closed' => $closed[0] ?? false,
            'released' => $released['connections'] ?? null,
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($refused['status'] ?? 'none'), (int) ($refused['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D9', static function (array $server) use ($Measure, $Settle, $Send, $Slow, $Drain, $Split, $Bound, $Post, $owed, $lowered): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($lowered, 2);
         // ! 12,000 one-element arrays — `[[0],[0],…]`, ~48 KB: over the 2 MiB
         //   bodies share
         $cells = implode(',', array_fill(0, 12_000, '[0]'));
         $small = "[{$cells}]";
         // ! In one write — one read for the worker: GETs whose 64 KiB answers
         //   the peer does not read, the small body behind them, then a GET
         //   to the counting route
         $gets = str_repeat("GET /o HTTP/1.1\r\nHost: localhost\r\n\r\n", $owed);
         $post = $Post('/n', 'application/json', $small);
         $request = "{$gets}{$post}GET /c HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $base = $Measure($port) ?? [];

         $Holds = [];
         $Streams = [];
         $refused = [];
         $wires = [''];
         $closed = [false];
         $sent = false;
         try {
            $Streams[0] = $Slow($port, $Holds);
            $sent = $Send($Streams[0], $request, 10.0);
            $refused = $Settle($port, ['bodies'], [1], 6.0) ?? [];

            // @ The peer reads now: its answers, then the refusal and close
            [$wires, $closed] = $Drain($Streams, 20.0);
         }
         finally {
            foreach ($Streams as $Stream) {
               @fclose($Stream);
            }
            $Holds = [];
         }
         $released = $Settle($port, ['connections'], [1]) ?? [];

         // :
         $last = strrpos($wires[0], 'HTTP/1.1 ');

         return [
            'shape' => [$owed, strlen($small), strlen($request), $Bound($small, $share)],
            'budget' => $base['budget'] ?? null,
            'sent' => $sent,
            'owed' => [$base['transport'] ?? null, $refused['transport'] ?? null],
            'refused' => [$refused['connections'] ?? null, $refused['bodies'] ?? null, $refused['raws'] ?? null],
            'counted' => [$base['counted'] ?? null, $refused['counted'] ?? null, $released['counted'] ?? null],
            'handled' => [$base['handled'] ?? null, $refused['handled'] ?? null, $released['handled'] ?? null],
            'labels' => $Split($wires[0]),
            'tail' => $last === false ? '' : substr($wires[0], $last, 64),
            'closed' => $closed[0] ?? false,
            'released' => $released['connections'] ?? null,
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($refused['status'] ?? 'none'), (int) ($refused['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D10', static function (array $server) use ($Fetch, $Measure, $Settle, $Send, $Gather, $Label, $Bound, $Post, $budget): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($budget, 2);
         // ! An unterminated string of 500,000 escaped quotes, ~1 MB: invalid
         //   JSON, and the worst case of a string-emptying pattern
         $escapes = str_repeat('\\"', 500_000);
         $body = "\"{$escapes}";
         $base = $Measure($port) ?? [];

         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-5 D10 peer could not connect: {$message}");
         }
         $wires = [''];
         $plain = ['status' => '', 'body' => ''];
         $elapsed = [-1.0, -1.0];
         $sent = false;
         try {
            // @ The body, whole, to the route that reads its fields
            $sent = $Send($Socket, $Post('/j', 'application/json', $body), 5.0);
            $start = microtime(true);
            // @ A plain GET on another connection while the worker decides it
            usleep(100_000);
            $plain = $Fetch($port, "GET /c HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n", 3.0);
            $elapsed[1] = round(microtime(true) - $start, 3);
            // @ The body's answer
            $Gather([$Socket], $wires, 1, 6.0);
            $elapsed[0] = round(microtime(true) - $start, 3);
         }
         finally {
            @fclose($Socket);
         }
         $released = $Settle($port, ['connections'], [1]) ?? [];

         // :
         return [
            'shape' => [strlen($body), $Bound($body, $share)],
            'budget' => $base['budget'] ?? null,
            'sent' => $sent,
            'answer' => $Label($wires[0]),
            'plain' => [$plain['status'], $plain['body']],
            'elapsed' => $elapsed,
            'handled' => [$base['handled'] ?? null, $released['handled'] ?? null],
            'released' => $released['connections'] ?? null,
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D11', static function (array $server) use ($Fetch, $Measure, $Bound, $Post, $default): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($default, 2);
         // ! An Elasticsearch `_bulk` body: 15,500 action and document line
         //   pairs, ~1.4 MB of NDJSON — invalid as one JSON text
         $lines = [];
         for ($index = 0; $index < 15_500; $index++) {
            $lines[] = "{\"index\":{\"_index\":\"logs\",\"_id\":\"{$index}\"}}\n{\"message\":\"event {$index}\",\"level\":\"info\",\"ts\":{$index}}\n";
         }
         $bulk = implode('', $lines);
         unset($lines);
         // ! D6's `[[0],…]` without its closing bracket: invalid JSON whose
         //   valid prefix a decode builds (~71 MB) before it fails
         $cells = implode(',', array_fill(0, 290_000, '[0]'));
         $cut = "[{$cells}";
         $base = $Measure($port) ?? [];

         // @ The bulk body to the route that reads its fields, then the cut
         //   one, each on its own keep-alive connection
         $bulked = $Fetch($port, $Post('/j', 'application/x-ndjson', $bulk), 8.0);
         $first = $Measure($port) ?? [];
         $truncated = $Fetch($port, $Post('/j', 'application/json', $cut), 8.0);
         $after = $Measure($port) ?? [];

         // :
         return [
            'shape' => [
               strlen($bulk),
               $Bound($bulk, $share, 'application/x-ndjson'),
               strlen($cut),
               $Bound($cut, $share),
            ],
            'budget' => $base['budget'] ?? null,
            'bulk' => [$bulked['status'], $bulked['body'], $bulked['closed']],
            'cut' => [$truncated['status'], $truncated['body'], $truncated['closed']],
            'handled' => [$base['handled'] ?? null, $first['handled'] ?? null, $after['handled'] ?? null],
            // ! Worker memory at rest, after each body; the worker's peaks
            'usage' => [$base['usage'] ?? null, $first['usage'] ?? null, $after['usage'] ?? null],
            'peak' => [$base['peak'] ?? null, $first['peak'] ?? null, $after['peak'] ?? null],
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($first['status'] ?? 'none'), (int) ($first['pid'] ?? 0)],
               [(string) ($after['status'] ?? 'none'), (int) ($after['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D12', static function (array $server) use ($Fetch, $Measure, $Bound, $Post, $default): array {
         // !
         $port = (int) $server['port'];
         $share = intdiv($default, 2);
         // ! 13,783 objects keyed 0 to 16, 63 and 64, ~1.72 MB: PHP builds
         //   each one as a list, doubles it twice, then turns it into a map
         //   of twice its slots
         $keys = [...range(0, 16), 63, 64];
         $members = implode(',', array_map(static fn (int $key): string => "\"{$key}\":0", $keys));
         $objects = implode(',', array_fill(0, 13_783, "{{$members}}"));
         $sparse = "[{$objects}]";
         unset($objects);
         // ! What PHP's own `json_encode()` writes for 10,000 arrays of 10
         //   strings keyed from 1, ~0.94 MB
         $row = [];
         for ($index = 1; $index <= 10; $index++) {
            $row[$index] = "v{$index}";
         }
         $rows = (string) json_encode(array_fill(0, 10_000, $row));
         $digest = md5((string) json_encode(json_decode($rows, true)));
         $base = $Measure($port) ?? [];

         // @ The sparse body to the route that reads its fields, then the
         //   encoded rows, each on its own keep-alive connection
         $refused = $Fetch($port, $Post('/j', 'application/json', $sparse), 8.0);
         $middle = $Measure($port) ?? [];
         $answered = $Fetch($port, $Post('/j', 'application/json', $rows), 8.0);
         $after = $Measure($port) ?? [];

         // :
         return [
            'shape' => [strlen($sparse), $Bound($sparse, $share), strlen($rows), $Bound($rows, $share)],
            'budget' => $base['budget'] ?? null,
            'sparse' => [$refused['status'], $refused['closed']],
            'rows' => [$answered['status'], $answered['body']],
            'digest' => $digest,
            'handled' => [$base['handled'] ?? null, $middle['handled'] ?? null, $after['handled'] ?? null],
            // ! Informational: worker memory at rest, after each body; its peak
            'usage' => [$base['usage'] ?? null, $middle['usage'] ?? null, $after['usage'] ?? null],
            'peak' => $after['peak'] ?? null,
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($middle['status'] ?? 'none'), (int) ($middle['pid'] ?? 0)],
               [(string) ($after['status'] ?? 'none'), (int) ($after['pid'] ?? 0)],
            ],
         ];
      }),
      static fn (): string => $Run('D13', static function (array $server) use ($Fetch, $Measure, $Settle, $Send, $Gather, $Label, $Tally, $Post): array {
         // !
         $port = (int) $server['port'];
         $peers = 24;
         // ! 1,000 of D12's objects, ~125 KB
         $keys = [...range(0, 16), 63, 64];
         $members = implode(',', array_map(static fn (int $key): string => "\"{$key}\":0", $keys));
         $objects = implode(',', array_fill(0, 1_000, "{{$members}}"));
         $body = "[{$objects}]";
         // ! What the parse keeps at the fix's measure, and what it really
         //   spends — the same PHP decodes it here
         gc_collect_cycles();
         $before = memory_get_usage();
         $decoded = json_decode($body, true);
         $real = memory_get_usage() - $before;
         $predicted = $Tally((array) $decoded);
         unset($decoded);
         $base = $Measure($port) ?? [];

         $Sockets = [];
         $wires = [];
         $outcomes = [];
         $rest = [];
         $wake = ['status' => '', 'body' => ''];
         try {
            // @@ One peer at a time, each parked or answered before the next
            //   one sends
            $parked = (int) ($base['parked'] ?? 0);
            for ($index = 0; $index < $peers; $index++) {
               $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
               if ($Socket === false) {
                  $outcomes[$index] = 'unconnected';
                  continue;
               }
               $Sockets[$index] = $Socket;
               $wires[$index] = '';
               $Send($Socket, $Post('/f', 'application/json', $body, "X-Peer: {$index}\r\n"), 10.0);

               $outcome = 'silent';
               $deadline = microtime(true) + 8.0;
               do {
                  if ($Gather([$index => $Socket], $wires, 1, 0.1) === 1) {
                     $outcome = $Label($wires[$index]);
                     break;
                  }
                  if (feof($Socket)) {
                     $outcome = 'closed';
                     break;
                  }
                  $metrics = $Measure($port, 2.0);
                  if ($metrics !== null && (int) ($metrics['parked'] ?? 0) !== $parked) {
                     $parked = (int) $metrics['parked'];
                     $outcome = 'parked';
                     break;
                  }
               }
               while (microtime(true) < $deadline);
               $outcomes[$index] = $outcome;
            }
            $rest = $Measure($port) ?? [];

            // @ Wake every parked deferral, then read every answer
            $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $Gather($Sockets, $wires, count($Sockets), 8.0);
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle($port, ['inbound', 'parked'], [(int) ($base['inbound'] ?? -1), 0]) ?? [];

         // :
         return [
            'shape' => [strlen($body), $real, $predicted, $peers],
            'budget' => $base['budget'] ?? null,
            'base' => $base['inbound'] ?? null,
            'outcomes' => $outcomes,
            'attempts' => $rest['attempts'] ?? null,
            'parses' => $rest['parses'] ?? null,
            'parked' => $rest['parked'] ?? null,
            'held' => $rest['inbound'] ?? null,
            'wake' => "{$wake['status']} {$wake['body']}",
            'answers' => array_count_values(array_map($Label, $wires)),
            'released' => $released['inbound'] ?? null,
            'remaining' => $released['parked'] ?? null,
            // ! Informational: worker memory at rest, parked and released
            'usage' => [$base['usage'] ?? null, $rest['usage'] ?? null, $released['usage'] ?? null],
            'samples' => [
               [(string) ($base['status'] ?? 'none'), (int) ($base['pid'] ?? 0)],
               [(string) ($rest['status'] ?? 'none'), (int) ($rest['pid'] ?? 0)],
               [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
            ],
         ];
      }),
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/hhsc5-deferred-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'HHSC5-DEFERRED-HARNESS');
      }, GET);
   },

   test: static function (array $responses) use ($Probe, $Clean, $limit, $cap, $budget, $lowered, $default, $threshold, $drift, $owed, $nothing, $bounded): Generator {
      // ! Idempotent: the last row already removed the temp area
      $Clean($Probe->base);

      // # Checks shared by every row
      $Baseline = static fn (array $evidence): array => [
         'fixture ran' => ($evidence['error'] ?? 'row did not run') === '',
         'same worker PID' => ($evidence['before'] ?? 0) > 0 && ($evidence['after'] ?? -1) === $evidence['before'],
         "no 'Allowed memory size' fatal" => ($evidence['fatal'] ?? -1) === 0,
         'server ran in its own launch group' => ($evidence['confined'] ?? false) === true,
         'stop left no server process or listener' => ($evidence['survivors'] ?? null) === []
            && ($evidence['listening'] ?? null) === false,
      ];
      $Liveness = static fn (array $evidence): bool => ($evidence['samples'] ?? []) !== []
         && array_filter(
            $evidence['samples'],
            static fn (array $sample): bool => $sample !== ['200', $evidence['before']],
         ) === [];

      // # Row checks
      $Checks = [
         'D1' => static function (array $evidence) use ($Liveness, $budget, $limit): array {
            // ! The capture price, the parsed jar and the charge one parked
            //   snapshot holds — what the 8 MiB Inbound share parks at it
            $prices = $evidence['prices'];
            $price = (int) (array_key_first($prices) ?? 0);
            $tallies = $evidence['tallies'];
            $jar = (int) (array_key_first($tallies) ?? 0);
            $charge = $price + $jar;
            $base = (int) $evidence['base'];
            $parks = $price > 0 && $jar > 0 ? intdiv(intdiv($budget, 2) - $base, $charge) : -1;
            $refused = $evidence['peers'] - $parks;
            $answer = "200 woke {$evidence['shape'][2]}";

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is 16 MiB' => $evidence['budget'] === $budget,
               'shape: one Cookie line of 1,500+ pairs just under the limit' => $evidence['shape'][0] === 2
                  && $evidence['shape'][1] > $limit - 16
                  && $evidence['shape'][1] <= $limit - 4
                  && $evidence['shape'][2] > 1_500,
               'one capture price, read through Request::weigh()' => count($prices) === 1 && $price > 0,
               'every capture moved the Inbound ledger by exactly weigh()' => $evidence['charges'] === [$price => $evidence['captured']]
                  && $evidence['captured'] >= $parks,
               'a parked snapshot holds weigh() plus its parsed cookies (Buffers::tally())' => $jar > 0
                  && $evidence['tallies'] === [$jar => $parks]
                  && $evidence['holds'] === [$charge => $parks],
               'parked exactly what fits the 8 MiB Inbound share at that charge' => $parks >= 1
                  && $evidence['parked'] === $parks,
               'every other peer refused with 500 at once' => $refused >= 1
                  && $evidence['immediate'] === ['500' => $refused],
               'the Inbound ledger held exactly the parked snapshots' => $evidence['held'] === $base + $parks * $charge
                  && $evidence['held'] > $base,
               'every parked deferral woke and answered with its jar' => $evidence['wake'] === "200 woken {$parks}"
                  && ($evidence['answers'][$answer] ?? 0) === $parks
                  && ($evidence['answers']['500'] ?? 0) === $refused
                  && count($evidence['answers']) === 2,
               'the Inbound ledger returned to its base' => $evidence['released'] === $base
                  && $evidence['remaining'] === 0,
            ];
         },
         'D2' => static function (array $evidence) use ($Liveness, $limit, $cap, $threshold): array {
            $peers = $evidence['peers'];
            $cycle = [$peers, 0, "200 woken {$peers}", ['200 woke' => $peers]];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'shape: 100 field lines just under the head byte limit' => $evidence['shape'] === [$cap, $limit - 4],
               'every cycle: 200 deferrals parked, woke and answered' => $evidence['cycles'] === [
                  'warm' => $cycle,
                  'first' => $cycle,
                  'measured' => $cycle,
                  'second' => $cycle,
               ],
               'every peer was idle and connected when measured' => $evidence['idle'] === $peers,
               'no idle peer\'s live Request holds a head' => $evidence['live'] === 0,
               "growth per idle peer under {$threshold} B" => $evidence['growth'] >= 0 && $evidence['growth'] < $threshold,
               'every peer released once it left' => $evidence['released'] === 1,
            ];
         },
         'D3' => static function (array $evidence) use ($Liveness, $drift): array {
            // ! One round's expected answers: every heavy one with its jar
            $heavies = $evidence['heavies'];
            $peers = 2 * $heavies;
            $jar = "200 woke {$evidence['jars']}";
            $Answered = static fn (array $cycle): bool => $cycle[0] === $peers
               && $cycle[1] === 0
               && $cycle[2] === "200 woken {$peers}"
               && ($cycle[3][$jar] ?? 0) === $heavies
               && ($cycle[3]['200 woke'] ?? 0) === $heavies
               && count($cycle[3]) === 2;
            $inbound = $evidence['base'][1];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'warm-up: 100 cheap deferrals parked, woke and answered' => $evidence['warm'] === [$peers, 0, "200 woken {$peers}", ['200 woke' => $peers]],
               'every round: 50 heavy and 50 cheap deferrals parked, woke and answered' => count($evidence['cycles']) === $evidence['rounds']
                  && array_filter($evidence['cycles'], static fn (array $cycle): bool => $Answered($cycle) === false) === [],
               'the Inbound ledger held the parked snapshots and returned to its base after every round' => count($evidence['readings']) === $evidence['rounds']
                  && array_filter(
                     $evidence['readings'],
                     static fn (array $reading): bool => is_int($reading[0]) === false || $reading[1] !== $inbound || $reading[2] <= $inbound,
                  ) === [],
               "worker memory back within {$drift} B of the pre-round base" => $evidence['growth'] < $drift,
            ];
         },
         'D4' => static function (array $evidence) use ($Liveness): array {
            // ! The capture price — its text fields included — and what the
            //   Inbound share parks at it
            $prices = $evidence['prices'];
            $price = (int) (array_key_first($prices) ?? 0);
            $tallies = $evidence['tallies'];
            $fields = (int) (array_key_first($tallies) ?? 0);
            $base = (int) $evidence['base'];
            $parks = $price > $fields && $fields > 0
               ? intdiv(intdiv((int) $evidence['budget'], 2) - $base, $price)
               : -1;
            $peers = $evidence['shape'][3];
            $refusals = array_filter(
               array_slice($evidence['outcomes'], max(0, $parks)),
               static fn (string $outcome): bool => $outcome !== 'POST 503',
            );

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'one capture price, its parsed fields included (Buffers::tally())' => count($prices) === 1
                  && count($tallies) === 1
                  && $fields > 0
                  && $price > $fields,
               'every parked snapshot moved the Inbound ledger by exactly weigh()' => $evidence['captured'] === $parks
                  && $evidence['charges'] === [$price => $parks]
                  && $evidence['holds'] === [$price => $parks]
                  && $evidence['tallies'] === [$fields => $parks],
               'parked exactly what fits the Inbound share at that price, the GET first' => $parks >= 1
                  && $evidence['parked'] === $parks
                  && array_slice($evidence['outcomes'], 0, $parks) === ['GET parked', ...array_fill(0, $parks - 1, 'POST parked')],
               'every other peer refused with 503' => $peers - $parks >= 1
                  && count($evidence['outcomes']) === $peers
                  && $refusals === [],
               'the Inbound ledger held exactly the parked snapshots' => $evidence['held'] === $base + $parks * $price,
               'every parked deferral woke and answered with its 9 fields' => $evidence['wake'] === "200 woken {$parks}"
                  && ($evidence['answers']['200 woke 9'] ?? 0) === $parks,
               'the Inbound ledger returned to its base' => $evidence['released'] === $base
                  && $evidence['remaining'] === 0,
            ];
         },
         'D5' => static function (array $evidence) use ($Liveness, $default): array {
            // ! Where each parse fits: the Inbound share and the whole budget,
            //   from the ledgers the work read right before it parsed
            $budget = (int) $evidence['budget'];
            $share = intdiv($budget, 2);
            $predicted = $evidence['predicted'];
            $attempts = (array) $evidence['attempts'];
            $parses = (array) $evidence['parses'];
            $expected = [];
            $last = -1;
            foreach ($evidence['kinds'] as $peer => $kind) {
               $attempt = $attempts[$peer] ?? null;
               $tally = (int) $predicted[$kind];
               $fits = is_array($attempt)
                  && $tally <= $share - (int) $attempt[0]
                  && $tally <= $budget - (int) $attempt[1];
               $expected[$peer] = $fits ? 'parked' : '500';
               if ($fits) {
                  $last = $peer;
               }
            }
            $parked = array_keys($expected, 'parked', true);
            $parsing = array_filter(
               array_keys($expected, '500', true),
               static fn (int $peer): bool => isSet($attempts[$peer]),
            );
            $charged = array_filter(
               $parked,
               static fn (int $peer): bool => ($parses[$peer] ?? null) !== [
                  $predicted[$evidence['kinds'][$peer]],
                  $predicted[$evidence['kinds'][$peer]],
               ],
            );
            $peers = $evidence['shape'][4];
            $woken = count($parked);
            $length = $evidence['shape'][2];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $budget === $default,
               'shape: 990 vars nested 40 deep, a ~126 KB form and its JSON twin, each parse 50x its bytes' => $evidence['shape'][0] === 990
                  && $length > 120_000
                  && $length < 130_000
                  && $predicted['form'] > 50 * $length
                  && $predicted['json'] > 50 * $length,
               'every peer parked while its parse fit the Inbound share, every other one answered 500' => count($evidence['outcomes']) === $peers
                  && $evidence['outcomes'] === $expected
                  && $woken >= 1
                  && $parsing !== [],
               'each parked snapshot was charged exactly what its parse keeps (Buffers::tally())' => $charged === []
                  && count($parses) === $woken,
               'the Inbound ledger held exactly the parked snapshots' => $last >= 0
                  && $evidence['parked'] === $woken
                  && $evidence['held'] === (int) $attempts[$last][0] + (int) $predicted[$evidence['kinds'][$last]],
               'every parked deferral woke and answered with its 990 fields' => $evidence['wake'] === "200 woken {$woken}"
                  && ($evidence['answers']['200 woke 990'] ?? 0) === $woken
                  && ($evidence['answers']['500'] ?? 0) === $peers - $woken
                  && count($evidence['answers']) === 2,
               'the Inbound ledger returned to its base' => $evidence['released'] === $evidence['base']
                  && $evidence['remaining'] === 0,
            ];
         },
         'D6' => static function (array $evidence) use ($Liveness, $default): array {
            $share = intdiv($default, 2);
            [$heavy, $bound, $normal, $limit] = $evidence['shape'];
            $handled = $evidence['handled'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $evidence['budget'] === $default,
               'shape: a ~1.16 MB [[0],…] body refused by the least its containers spend, a ~200 KB JSON body under the quick bound' => $heavy > 1_100_000
                  && $heavy < 1_200_000
                  && $bound['tier'] === 'least'
                  && $bound['price'] > $share
                  && $bound['refused'] === true
                  && $bound['checked'] === false
                  && $normal > 180_000
                  && $normal < 220_000
                  && $limit['tier'] === 'quick'
                  && $limit['price'] <= $share
                  && $limit['refused'] === false
                  && $limit['checked'] === true,
               'the [[0],…] body to a route that reads its fields: 413, closed' => $evidence['reading'] === ['413', true],
               'the [[0],…] body to a route that does not read them: 413, closed' => $evidence['ignoring'] === ['413', true],
               'no handler ran for either refused body' => $handled[0] === 0
                  && $handled[1] === 0,
               'the ~200 KB JSON body to the reading route: 200 with its 2,000 records' => $evidence['normal'] === ['200', "fields 2000 {$evidence['digest']}"]
                  && $handled[2] === 1,
            ];
         },
         'D7' => static function (array $evidence) use ($Liveness, $default, $owed): array {
            $share = intdiv($default, 2);
            [$peers, $gets, $bytes, $bound] = $evidence['shape'];
            [$connections, $transport] = $evidence['base'];
            $steps = $evidence['steps'];
            // ! Each peer's wire once it read: its answers, the refusal, the close
            $answer = [
               'answered' => $owed,
               'others' => ['413 0'],
               'tail' => "HTTP/1.1 413 Request Entity Too Large\r\n\r\n",
               'closed' => true,
            ];
            $owing = array_filter(
               $steps,
               static fn (array $step): bool => $step['transport'] <= $transport,
            );

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $evidence['budget'] === $default,
               'shape: 14 peers, a ~10 MB [0,0,…] body over the bodies share, under maxBodySize' => $peers === 14
                  && $gets === $owed
                  && $bytes > 9_900_000
                  && $bytes <= 10 * 1024 * 1024
                  && $bound['tier'] === 'least'
                  && $bound['price'] > $share
                  && $bound['refused'] === true
                  && $bound['checked'] === false,
               'every peer sent its GETs and its whole body' => count($steps) === $peers
                  && array_filter($steps, static fn (array $step): bool => $step['sent'] !== true) === [],
               'every body was whole while the worker still owed its peer output' => array_column($steps, 'bodies') === range(1, $peers)
                  && array_column($steps, 'connections') === range($connections + 1, $connections + $peers)
                  && $owing === [],
               'no live Request held a refused body' => array_filter(
                     array_column($steps, 'raws'),
                     static fn (mixed $raws): bool => $raws !== 0,
                  ) === []
                  && $evidence['held'] === [$connections + $peers, $peers, 0, $evidence['held'][3]],
               'every peer read its 48 answers, then 413 Request Entity Too Large, then the close' => count($evidence['answers']) === $peers
                  && array_filter($evidence['answers'], static fn (array $answers): bool => $answers !== $answer) === [],
               'no handler ran for a refused body' => $evidence['handled'] === [0, 0, 0],
               'every peer released once it read' => $evidence['released'] === [1, 0],
            ];
         },
         'D8' => static function (array $evidence) use ($Liveness, $default, $owed): array {
            $share = intdiv($default, 2);
            [$gets, $bytes, $bound] = $evidence['shape'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $evidence['budget'] === $default,
               'shape: the D6 [[0],…] body over the bodies share' => $gets === $owed
                  && $bytes > 1_100_000
                  && $bound['tier'] === 'least'
                  && $bound['price'] > $share
                  && $bound['refused'] === true
                  && $bound['checked'] === false,
               'the peer sent its GETs, the body and the pipelined GET' => $evidence['sent'] === true,
               'the body was whole and refused while the worker owed its peer output' => $evidence['refused'] === [2, 1, 0]
                  && is_int($evidence['owed'][1])
                  && $evidence['owed'][1] > (int) $evidence['owed'][0],
               'the GET pipelined behind the refused body never ran' => $evidence['counted'] === [0, 0, 0]
                  && $evidence['handled'] === [0, 0, 0],
               'the peer read its 48 answers, then 413 Request Entity Too Large, then the close — nothing after it' => $evidence['answered'] === $owed
                  && $evidence['others'] === ['413 0']
                  && $evidence['tail'] === "HTTP/1.1 413 Request Entity Too Large\r\n\r\n"
                  && $evidence['closed'] === true,
               'the peer released once it read' => $evidence['released'] === 1,
            ];
         },
         'D9' => static function (array $evidence) use ($Liveness, $lowered, $owed): array {
            $share = intdiv($lowered, 2);
            [$gets, $bytes, $write, $bound] = $evidence['shape'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is 4 MiB' => $evidence['budget'] === $lowered,
               'shape: a ~48 KB JSON body over the 2 MiB bodies share, the whole write under one read' => $gets === $owed
                  && $bytes < 50_000
                  && $write < 65_536
                  && $bound['price'] > $share
                  && $bound['refused'] === true
                  && $bound['checked'] === false,
               'the peer sent the GETs, the body and the pipelined GET in one write' => $evidence['sent'] === true,
               'the body was whole and refused while the worker owed its peer output' => $evidence['refused'] === [2, 1, 0]
                  && is_int($evidence['owed'][1])
                  && $evidence['owed'][1] > (int) $evidence['owed'][0],
               'the GET pipelined behind the refused body never ran' => $evidence['counted'] === [0, 0, 0]
                  && $evidence['handled'] === [0, 0, 0],
               'the peer read its 48 answers, then 413 Request Entity Too Large, then the close — nothing after it' => $evidence['labels'] === [...array_fill(0, $owed, '200 65536'), '413 0']
                  && $evidence['tail'] === "HTTP/1.1 413 Request Entity Too Large\r\n\r\n"
                  && $evidence['closed'] === true,
               'the peer released once it read' => $evidence['released'] === 1,
            ];
         },
         'D10' => static function (array $evidence) use ($Liveness, $budget, $nothing): array {
            $share = intdiv($budget, 2);
            [$bytes, $bound] = $evidence['shape'];
            [$answer, $plain] = $evidence['elapsed'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is 16 MiB' => $evidence['budget'] === $budget,
               'shape: a ~1 MB unterminated string of escaped quotes — invalid JSON, which check() passes though its byte-by-byte price is over the 8 MiB bodies share' => $bytes === 1_000_001
                  && $bound['tier'] === 'invalid'
                  && $bound['price'] > $share
                  && $bound['refused'] === false
                  && $bound['checked'] === true,
               'the peer sent the whole body' => $evidence['sent'] === true,
               'the body was answered 200 with no fields within 2 s' => $evidence['answer'] === "200 {$nothing}"
                  && $answer >= 0
                  && $answer < 2.0,
               'a plain GET on another connection was answered meanwhile, within 2 s' => $evidence['plain'] === ['200', 'counted']
                  && $plain >= 0
                  && $plain < 2.0,
               'the handler ran once' => $evidence['handled'] === [0, 1],
               'the peer released once it left' => $evidence['released'] === 1,
            ];
         },
         'D11' => static function (array $evidence) use ($Liveness, $default, $nothing, $bounded): array {
            $share = intdiv($default, 2);
            [$bulk, $streamed, $cut, $truncated] = $evidence['shape'];
            [$base, $first, $after] = $evidence['peak'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $evidence['budget'] === $default,
               'shape: a ~1.4 MB NDJSON bulk body and a ~1.16 MB cut [[0],…] body — invalid JSON check() passes, each over the bodies share at its byte-by-byte price' => $bulk > 1_350_000
                  && $bulk < 1_500_000
                  && $streamed['tier'] === 'invalid'
                  && $streamed['price'] > $share
                  && $streamed['checked'] === true
                  && $cut > 1_100_000
                  && $truncated['tier'] === 'invalid'
                  && $truncated['price'] > $share
                  && $truncated['checked'] === true,
               'the bulk body (application/x-ndjson) was answered 200 with no fields, kept alive' => $evidence['bulk'] === ['200', $nothing, false],
               'the cut body (application/json) was answered 200 with no fields, kept alive' => $evidence['cut'] === ['200', $nothing, false],
               'each handler ran once' => $evidence['handled'] === [0, 1, 2],
               "the worker peak grew under {$bounded} B: nothing was decoded" => is_int($base)
                  && is_int($first)
                  && is_int($after)
                  && $after - $base < $bounded,
            ];
         },
         'D12' => static function (array $evidence) use ($Liveness, $default): array {
            $share = intdiv($default, 2);
            [$sparse, $bound, $rows, $limit] = $evidence['shape'];
            $handled = $evidence['handled'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $evidence['budget'] === $default,
               'shape: a ~1.72 MB body of objects keyed 0-16, 63, 64 priced by the walk over the bodies share; ~0.94 MB of one-based rows under it' => $sparse === 1_722_876
                  && $bound['tier'] === 'walk'
                  && $bound['listed'] === true
                  && $bound['price'] > $share
                  && $bound['refused'] === true
                  && $bound['checked'] === false
                  && $rows === 940_001
                  && $limit['tier'] === 'walk'
                  && $limit['listed'] === true
                  && $limit['price'] <= $share
                  && $limit['refused'] === false
                  && $limit['checked'] === true,
               'the sparse body to the route that reads its fields: 413, closed' => $evidence['sparse'] === ['413', true],
               'no handler ran for the sparse body' => $handled[0] === 0
                  && $handled[1] === 0,
               'the one-based rows to the same route: 200 with its 10,000 rows' => $evidence['rows'] === ['200', "fields 10000 {$evidence['digest']}"]
                  && $handled[2] === 1,
            ];
         },
         'D13' => static function (array $evidence) use ($Liveness, $default): array {
            // ! Where each parse fits: the Inbound share and the whole budget,
            //   from the ledgers the work read right before it parsed
            $budget = (int) $evidence['budget'];
            $share = intdiv($budget, 2);
            [$bytes, $real, $predicted, $peers] = $evidence['shape'];
            $attempts = (array) $evidence['attempts'];
            $parses = (array) $evidence['parses'];
            $expected = [];
            $last = -1;
            for ($peer = 0; $peer < $peers; $peer++) {
               $attempt = $attempts[$peer] ?? null;
               $fits = is_array($attempt)
                  && $predicted <= $share - (int) $attempt[0]
                  && $predicted <= $budget - (int) $attempt[1];
               $expected[$peer] = $fits ? 'parked' : '500';
               if ($fits) {
                  $last = $peer;
               }
            }
            $parked = array_keys($expected, 'parked', true);
            $parsing = array_filter(
               array_keys($expected, '500', true),
               static fn (int $peer): bool => isSet($attempts[$peer]),
            );
            $charged = array_filter(
               $parked,
               static fn (int $peer): bool => ($parses[$peer] ?? null) !== [$predicted, $predicted],
            );
            $woken = count($parked);

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is the default' => $budget === $default,
               'shape: ~125 KB of D12\'s objects decode into 60x their bytes, and Buffers::tally() covers what the decode really spends' => $bytes === 125_001
                  && $real > 60 * $bytes
                  && $predicted >= $real,
               'every peer parked while its parse fit the Inbound share, every other one answered 500' => count($evidence['outcomes']) === $peers
                  && $evidence['outcomes'] === $expected
                  && $woken >= 1
                  && $parsing !== [],
               'each parked snapshot was charged exactly what its parse keeps (Buffers::tally())' => $charged === []
                  && count($parses) === $woken,
               'the Inbound ledger held exactly the parked snapshots' => $last >= 0
                  && $evidence['parked'] === $woken
                  && $evidence['held'] === (int) $attempts[$last][0] + $predicted,
               'every parked deferral woke and answered with its 1,000 objects' => $evidence['wake'] === "200 woken {$woken}"
                  && ($evidence['answers']['200 woke 1000'] ?? 0) === $woken
                  && ($evidence['answers']['500'] ?? 0) === $peers - $woken
                  && count($evidence['answers']) === 2,
               'the Inbound ledger returned to its base' => $evidence['released'] === $evidence['base']
                  && $evidence['remaining'] === 0,
            ];
         },
      ];
      $descriptions = [
         'D1' => 'D1: a snapshot is charged weigh() at capture and its cookies when parsed — what fits the 8 MiB Inbound share parks, the rest 500, the ledger returns to its base',
         'D2' => "D2: 200 idle keep-alive peers answered by a deferral hold under {$threshold} B each, no refork",
         'D3' => "D3: once rounds of heavy and cheap deferrals answered, no pooled Fiber keeps a snapshot — worker memory within {$drift} B of its base",
         'D4' => 'D4: a parked snapshot is charged its multipart text fields — what fits the Inbound share parks, the rest are refused, no refork',
         'D5' => 'D5: a parked snapshot is charged what its work parses from a form or JSON body — what fits the Inbound share parks, the rest 500, no refork',
         'D6' => 'D6: a JSON body that cannot decode within the bodies share is answered 413 before any handler, no refork; an ordinary one 200',
         'D7' => 'D7: 14 refused ~10 MB JSON bodies behind owed output — none held while their peers drain, each 413 then closed, no refork',
         'D8' => 'D8: a GET pipelined behind a refused JSON body never runs nor answers',
         'D9' => 'D9: a GET pipelined behind a small JSON body refused in a pipelined write never runs nor answers',
         'D10' => 'D10: a ~1 MB unterminated escape string — invalid JSON — is answered 200 with no fields within 2 s, without freezing the worker',
         'D11' => "D11: a ~1.4 MB NDJSON bulk body and a cut [[0],…] body are answered 200 with no fields, the worker peak under {$bounded} B more, no refork",
         'D12' => 'D12: a ~1.72 MB body of objects keyed 0-16, 63, 64 (~114 MB decoded) is answered 413 before any handler, no refork; one-based rows 200',
         'D13' => 'D13: a parked snapshot is charged what its int-keyed JSON objects really decode into — what fits the Inbound share parks, the rest 500, no refork',
      ];

      // @@ Judge every row before asserting any, so the first failure
      //   reports them all
      $verdicts = [];
      foreach ($Checks as $row => $Check) {
         $evidence = $Probe->rows[$row] ?? ['error' => 'row did not run'];
         try {
            $checks = $Baseline($evidence) + $Check($evidence);
         }
         catch (Throwable $Throwable) {
            $checks = $Baseline($evidence) + ['row evidence judged' => false];
            $origin = $Throwable::class;
            $evidence['judgement'] = "{$origin}: {$Throwable->getMessage()}";
         }

         $verdicts[$row] = [
            'failed' => array_keys(array_filter($checks, static fn (bool $passed): bool => $passed === false)),
            'evidence' => $evidence,
         ];
      }
      $summary = (string) json_encode(array_map(
         static fn (array $verdict): string|array => $verdict['failed'] === [] ? 'pass' : $verdict['failed'],
         $verdicts,
      ), JSON_UNESCAPED_SLASHES);
      // ! The measured figures, reported by every row
      $figures = (string) json_encode([
         'D1' => [
            'parked' => $Probe->rows['D1']['parked'] ?? null,
            'prices' => $Probe->rows['D1']['prices'] ?? null,
            'tallies' => $Probe->rows['D1']['tallies'] ?? null,
            'held' => $Probe->rows['D1']['held'] ?? null,
         ],
         'D4' => [
            'parked' => $Probe->rows['D4']['parked'] ?? null,
            'prices' => $Probe->rows['D4']['prices'] ?? null,
            'tallies' => $Probe->rows['D4']['tallies'] ?? null,
            'held' => $Probe->rows['D4']['held'] ?? null,
            'outcomes' => array_count_values($Probe->rows['D4']['outcomes'] ?? []),
            'usage' => $Probe->rows['D4']['usage'] ?? null,
         ],
         'D5' => [
            'outcomes' => array_count_values($Probe->rows['D5']['outcomes'] ?? []),
            'predicted' => $Probe->rows['D5']['predicted'] ?? null,
            'parses' => $Probe->rows['D5']['parses'] ?? null,
            'held' => $Probe->rows['D5']['held'] ?? null,
            'usage' => $Probe->rows['D5']['usage'] ?? null,
         ],
         'D6' => [
            'reading' => $Probe->rows['D6']['reading'] ?? null,
            'ignoring' => $Probe->rows['D6']['ignoring'] ?? null,
            'normal' => $Probe->rows['D6']['normal'][0] ?? null,
            'handled' => $Probe->rows['D6']['handled'] ?? null,
            'usage' => $Probe->rows['D6']['usage'] ?? null,
            'peak' => $Probe->rows['D6']['peak'] ?? null,
         ],
         'D7' => [
            'steps' => array_map(
               static fn (array $step): array => [$step['bodies'], $step['raws'], $step['transport'], $step['usage']],
               $Probe->rows['D7']['steps'] ?? [],
            ),
            'held' => $Probe->rows['D7']['held'] ?? null,
            'handled' => $Probe->rows['D7']['handled'] ?? null,
            'usage' => $Probe->rows['D7']['usage'] ?? null,
            'peak' => $Probe->rows['D7']['peak'] ?? null,
            'fatality' => $Probe->rows['D7']['fatality'] ?? null,
         ],
         'D8' => [
            'owed' => $Probe->rows['D8']['owed'] ?? null,
            'refused' => $Probe->rows['D8']['refused'] ?? null,
            'counted' => $Probe->rows['D8']['counted'] ?? null,
            'others' => $Probe->rows['D8']['others'] ?? null,
            'tail' => $Probe->rows['D8']['tail'] ?? null,
         ],
         'D9' => [
            'shape' => $Probe->rows['D9']['shape'] ?? null,
            'owed' => $Probe->rows['D9']['owed'] ?? null,
            'counted' => $Probe->rows['D9']['counted'] ?? null,
            'labels' => array_count_values($Probe->rows['D9']['labels'] ?? []),
         ],
         'D10' => [
            'shape' => $Probe->rows['D10']['shape'] ?? null,
            'answer' => $Probe->rows['D10']['answer'] ?? null,
            'elapsed' => $Probe->rows['D10']['elapsed'] ?? null,
            'plain' => $Probe->rows['D10']['plain'] ?? null,
         ],
         'D11' => [
            'shape' => $Probe->rows['D11']['shape'] ?? null,
            'bulk' => $Probe->rows['D11']['bulk'] ?? null,
            'cut' => $Probe->rows['D11']['cut'] ?? null,
            'usage' => $Probe->rows['D11']['usage'] ?? null,
            'peak' => $Probe->rows['D11']['peak'] ?? null,
         ],
         'D12' => [
            'shape' => $Probe->rows['D12']['shape'] ?? null,
            'sparse' => $Probe->rows['D12']['sparse'] ?? null,
            'rows' => $Probe->rows['D12']['rows'][0] ?? null,
            'handled' => $Probe->rows['D12']['handled'] ?? null,
            'usage' => $Probe->rows['D12']['usage'] ?? null,
            'peak' => $Probe->rows['D12']['peak'] ?? null,
            'fatality' => $Probe->rows['D12']['fatality'] ?? null,
         ],
         'D13' => [
            'shape' => $Probe->rows['D13']['shape'] ?? null,
            'outcomes' => array_count_values($Probe->rows['D13']['outcomes'] ?? []),
            'parses' => $Probe->rows['D13']['parses'] ?? null,
            'held' => $Probe->rows['D13']['held'] ?? null,
            'usage' => $Probe->rows['D13']['usage'] ?? null,
            'fatality' => $Probe->rows['D13']['fatality'] ?? null,
         ],
         'bounds' => [
            'D6' => [$Probe->rows['D6']['shape'][1] ?? null, $Probe->rows['D6']['shape'][3] ?? null],
            'D7' => $Probe->rows['D7']['shape'][3] ?? null,
         ],
         'growth' => $Probe->rows['D2']['growth'] ?? null,
         'live' => $Probe->rows['D2']['live'] ?? null,
         'pooled' => $Probe->rows['D2']['pooled'] ?? null,
         'drift' => $Probe->rows['D3']['growth'] ?? null,
         'readings' => array_column($Probe->rows['D3']['readings'] ?? [], 0),
      ]);

      // @ The native harness reached the suite worker once per row
      yield new Assertion(
         description: 'Harness: every row answered on the suite worker',
         fallback: "Harness responses: {$summary} | figures: {$figures}",
      )
         ->expect(
            array_map(static fn (string $response): bool => str_contains($response, 'HHSC5-DEFERRED-HARNESS'), $responses),
            Op::Identical,
            array_fill(0, 13, true),
         )
         ->assert();

      // @@ One assertion per row
      foreach ($verdicts as $row => $verdict) {
         $evidence = (string) json_encode($verdict['evidence'], JSON_UNESCAPED_SLASHES);

         yield new Assertion(
            description: $descriptions[$row],
            fallback: "{$row} evidence: {$evidence} | all rows: {$summary} | figures: {$figures}",
         )
            ->expect($verdict['failed'], Op::Identical, [])
            ->assert();
      }
   },
);
