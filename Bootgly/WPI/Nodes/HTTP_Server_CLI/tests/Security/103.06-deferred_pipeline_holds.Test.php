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
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Holding;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC H-HSC-6 (live) — an HTTP/1.1 connection parks one deferral
 * at a time and answers in request order, and every parked deferral is
 * charged the generation it keeps, over HTTP/1 and h2c alike.
 *
 * Before the fix every request a connection pipelined behind a parked
 * deferral was decoded and dispatched at once: each one parked its own
 * deferral (a Response and Route clone, a Fiber and its VM stack, the
 * cancellation, observers and timers — ~33 KB) while the worker budget
 * charged only its request snapshot (~2.7 KB, H-HSC-5), so one connection
 * pipelining tiny long-poll GETs killed the worker, and a synchronous request
 * pipelined behind a parked one was answered first (RFC 9112 §9.3.2, ENC-3).
 * Now `Response::defer()` installs a `Decoder_Holding` on the connection
 * while its deferral parks: the requests behind it wait undecoded in the
 * transport carry (charged to the Transport share) until the parked answer
 * is written, more than one transport read held (`Packages::READ`) closes the
 * connection, and `Request::weigh()` charges every parked snapshot its
 * generation (`Request::GENERATION`, read here through reflection) to the
 * Inbound share, so a full share answers further deferrals `503` (ENC-8)
 * instead of letting HTTP/2 streams or many connections exhaust the worker.
 *
 * Each row boots a REAL HTTP_Server_CLI (one worker, production `Encoder_`)
 * in an isolated child process (`setsid`, hard `timeout`, a scrubbed
 * environment, its own loopback port in 19700-19749, `-d opcache.jit=0`,
 * `-d memory_limit=128M` unless a row says otherwise — the worker budget is
 * the default, fitted to half of it) and talks to it with plain sockets. A
 * long-poll deferral (`/d`) parks on one shared socket pair until `/w` writes
 * to it; `/t` parks 0.3 s; `/e` answers at once:
 *
 * - R1 one connection: 50 pipelined `/d` in one write, then 50 wakes, then
 *   6,000 more pipelined `/d` in writes of 50. Exactly one deferral is parked
 *   before every wake, each wake answers exactly the next request, in order,
 *   and the flood never parks a second deferral, is never refused (no 500 or
 *   503) and leaves the worker alive: the hold closes the connection once
 *   more than one transport read waits behind the park. Measured (PHP 8.5,
 *   2026-10-13): 50 answers one per wake; 1,300 flood requests written before
 *   the close, at most 1 parked. HEAD 658e986b parked all 50 at once (one wake
 *   answered them all) and the flood killed the worker after 3,850 writes
 *   (`Allowed memory size`, refork). Without the hold the one-park backstop
 *   closes the connection at the second request (nothing answered); without
 *   both, 852 deferrals parked at once and 5,147 requests were answered 503.
 * - R2 the hold cap: one `/d` parks, then exactly 65,536 bytes of pipelined
 *   `/e` wait behind it — nothing is answered, the connection stays open and
 *   its carry holds them (Transport 0 → 72,316 B); one more byte closes the
 *   connection with nothing written (the parked deferral is cancelled) and
 *   the Transport and Inbound ledgers return to their base. HEAD answered
 *   every `/e` at once (184,629 bytes), before the parked `/d`; without the
 *   cap the connection stays open.
 * - R3 wire order (production `Encoder_`): `/t` then `/e` in the same write,
 *   and `/t` then, 50 ms later, `/e` in a later write — `/t`'s answer must
 *   arrive first, after its 0.3 s park, then `/e`'s. Measured: 0.32 s / 0.30 s,
 *   then `/e`. HEAD and the fix without the hold answered `/e` first (0.02 s /
 *   0.08 s).
 * - R4 h2c: 40 prior-knowledge connections × 128 `/d` streams (a 2^31-1
 *   window). Streams park while the Inbound share fits their charge, every
 *   other one is answered `:status 503` (never 500, no RST_STREAM, no GOAWAY),
 *   the worker survives, `/w` answers every parked stream `200` and closing
 *   the connections returns the Inbound ledger to its base. Measured: 861
 *   parked (Inbound 33,530,784 of 33,554,432 B), 4,259 × 503, worker peak
 *   50.1 MB. HEAD and GENERATION 0 died after 3,560 streams.
 * - R5 960 connections × one `/d` at `memory_limit=32M` (a 16 MiB budget, an
 *   8 MiB Inbound share): parked while the share fits, `503` otherwise, no
 *   refork; `/w` answers every parked one. Measured: 213 parked (Inbound
 *   8,384,584 B), 747 × 503, worker peak 17.0 MB. HEAD and GENERATION 0 died
 *   at 780 connections.
 * - R6 what a parked deferral keeps: 600 idle keep-alive peers (`/e`), then
 *   600 parked `/d` peers; `/m` reports worker memory after
 *   `gc_collect_cycles()` at 50 and at 600 of each. Kept per park is the park
 *   slope minus the idle slope; charged per park is the Inbound slope, which
 *   must equal every capture's `Request::weigh()` and carry the snapshot and
 *   the generation (`SNAPSHOT`, `GENERATION`, read through reflection); kept
 *   ≤ charged ≤ 1.25 × kept. Measured with GENERATION 36,864: idle peer
 *   4,333 B, parked peer 40,803 B, so 36,470 B kept against 39,376 B charged
 *   (1.08 ×). HEAD and GENERATION 0 charged 2,512 B (0.07 ×).
 * - R7 connections × (one `/d` + exactly 65,536 bytes of pipelined `/d` held
 *   behind it) at `memory_limit=64M` (a 32 MiB budget), as many as their
 *   charge says pass the budget: 100 measured peers first (idle keep-alive,
 *   then the attack), whose per-peer growth of worker memory (kept) must not
 *   pass the per-peer growth of the Inbound + Transport ledgers (charged);
 *   then the peers the rest of the budget fits at that charge, one more and
 *   32 past it. It asserts invariants, never where the budget ends: the
 *   worker survives, every parked deferral holds its pipeline (each carry
 *   exactly one transport read), the Transport ledger charges every held
 *   carry `Buffers::weigh(READ)` beside its base (nothing else stays in
 *   Transport once the peers settled: its base is 0 B), Inbound ≤ half the
 *   budget, Inbound + Transport ≤ the budget and within one charge of it,
 *   the one more peer and the 32 past it are not held — each is closed
 *   unanswered or answered 503 (a refused capture installs no hold, so its
 *   pipeline is refused too), never 500 — and closing every peer returns the
 *   ledgers to their base. The peers past the budget are sized from the
 *   measured charge rounded down, and the later peers' longer tags never
 *   lower their charge, so the budget never fits more than predicted.
 *   Measured (3 runs, 2026-10-14): 107,173 B kept against 111,676 B charged
 *   per peer; 333 peers, 300 parked holding 65,536 B each (Inbound 11,809,600
 *   B + Transport 21,694,800 B = 300 × 72,316 B over a 0 B base), the other
 *   33 closed unanswered, worker peak 36.7 MB. Charged 48 B more per park
 *   (GENERATION 36,912), the 33 are refused instead (19,020 × 503) and the
 *   row still passes, its Transport still exactly 300 × 72,316 B. A carry
 *   kept when the budget refuses its reservation parks a 301st peer whose
 *   64 KiB no ledger charges (Transport stays 300 × 72,316 B; 32 refused):
 *   red. HEAD parked every pipelined `/d` and died in the measured peers;
 *   GENERATION 0 charged 74,812 B per peer against 107,173 B kept; without
 *   the hold the backstop closed every peer at its second request (nothing
 *   held), and without both 426 deferrals parked on the first peers and
 *   every other request was refused (279,264 × 503).
 * - R8 a read of half a transport read (`READ / 2` = 32,768 bytes) held
 *   behind a park: PHP keeps such a read's whole 64 KiB allocation and the
 *   carry adopts it, so the Transport ledger must charge `weigh(READ)`, not
 *   `weigh(32,768)`. At `memory_limit=32M` beside a 6 MiB application heap:
 *   first 300 connections × (one `/d` + exactly 32,768 bytes of pipelined
 *   `/e` in ONE write) — the R1 attack — then the 32,767-byte control, then
 *   100 measured peers per size (idle, parked, holding: `/m` at each step).
 *   Measured: a 32,768-byte carry keeps 69,639 B, charged 72,316 B; the
 *   control keeps 36,871 B, charged 38,131 B; per peer (park + carry) 104,794
 *   B kept against 111,692 B charged. The attack: 213 parked, 150 held
 *   (Transport 10,847,400 B), 63 closed, worker peak 27.8 MB. Weighing the
 *   carry by its length (the R1 code) charged 38,131 B for 69,639 B kept, and
 *   the attack killed the worker (`Allowed memory size`, refork) beside 4 to
 *   7 MiB heaps (a 3 MiB one survived). The fix survives beside 6 and 7 MiB;
 *   beside 8 MiB it dies too (usage 28.7 MB when the 32 MiB limit was hit:
 *   allocator fragmentation the ledger does not see), hence 6 MiB.
 *
 * Every row records the worker PID before/after, the `/m` metrics route and
 * the server log; a reforked worker or an `Allowed memory size` fatal fails
 * it. The child gets only PATH and HOME (the runner's agent-output flag makes
 * Bootgly's bootstrap call `posix_setsid()`, which would move the server out
 * of the group the `timeout` bounds and the cleanup kills). The process
 * groups the child tree really runs in are read from `/proc` once it answers
 * and killed on stop; a row whose server left its group, or left a process or
 * a listener behind, fails. Every row is judged before any is asserted, so the
 * first failure reports every verdict and the measured figures.
 *
 * Local-only: the Security suite never runs in CI.
 */


// # Server child
// ! The servers never run in the Security suite's shared worker: the spec
//   re-executes this file as an isolated, session-led PHP process that boots
//   one real HTTP_Server_CLI (one worker) on its own loopback port.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--hhsc6-pipeline-server'
) {
   // !
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $base = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][4] ?? 0);
   $ballast = (int) ($_SERVER['argv'][5] ?? 0);

   // ?
   if (
      $root === ''
      || is_file("{$root}/autoboot.php") === false
      || $base === ''
      || is_dir($base) === false
      || $port < 1024
      || $ballast < 0
   ) {
      fwrite(STDERR, "H-HSC-6 server child received an invalid root, base, port or ballast.\n");
      exit(2);
   }

   define('BOOTGLY_STORAGE_BASE', "{$base}/storage");
   define('BOOTGLY_STORAGE_DIR', "{$base}/storage/");
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // ! The application heap: `$ballast` MiB the application keeps for its
   //   whole life, inherited by the worker (0 unless a row says otherwise)
   $GLOBALS['hhsc6_ballast'] = str_repeat('b', $ballast * 1_048_576);

   // @ One worker, the default worker budget (fitted to the memory_limit)
   $Server = new HTTP_Server_CLI(Modes::Foreground);
   $Server->configure(
      new HTTP_Server_CLI\Configs(
         host: '127.0.0.1',
         port: $port,
         workers: 1,
      ),
   );

   // ! Every parked deferral waits on one shared socket pair until `/w`
   //   writes to it; a fresh pair then serves the deferrals parked later
   $GLOBALS['hhsc6_pair'] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   // ! One share of the worker budget; -1 where it does not exist
   $Inspect = static function (Shares $Share): int {
      try {
         $held = new ReflectionProperty(TCP_Server_CLI\Buffers::class, 'held')->getValue();
      }
      catch (Throwable) {
         return -1;
      }

      return is_array($held) && is_int($held[$Share->value] ?? null)
         ? $held[$Share->value]
         : -1;
   };
   // ! The snapshot's price (`Request::weigh()`); -1 where it does not exist
   $Price = static function (Request $Request): int {
      try {
         $price = new ReflectionMethod(Request::class, 'weigh')->invoke($Request);
      }
      catch (Throwable) {
         return -1;
      }

      return is_int($price) ? $price : -1;
   };
   // ! Park the running deferral on the shared pair — until `/w`, or until
   //   `$deadline` (wall clock) when one is given — then answer its request
   $Park = static function (Response $Response, float $deadline): void {
      $GLOBALS['hhsc6_parked'] = ($GLOBALS['hhsc6_parked'] ?? 0) + 1;
      $GLOBALS['hhsc6_most'] = max($GLOBALS['hhsc6_most'] ?? 0, $GLOBALS['hhsc6_parked']);
      try {
         $Response->wait(Readiness::read($GLOBALS['hhsc6_pair'][0], $deadline));
      }
      finally {
         $GLOBALS['hhsc6_parked']--;
      }

      $tag = $Response->Request?->Header->get('X-Req') ?? '-';
      $Response(body: "deferred:{$tag}");
   };

   $Server->on(Events::RequestReceived, static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Inspect, $Price, $Park): Generator {
      // # Metrics: the worker PID, its connections, memory, ledgers and holds
      yield $Router->route('/m', static function (
         Request $Request,
         Response $Response,
      ) use ($Inspect): Response {
         // ! Garbage cycles of departed peers never count as kept memory
         gc_collect_cycles();
         $usage = memory_get_usage();
         // ! Bytes the connections' receive carries hold, how many
         //   connections hold their pipeline behind a parked deferral, and
         //   the widest carry one of them holds
         $held = 0;
         $holds = 0;
         $widest = 0;
         foreach (Connections::$Connections as $Connection) {
            if ($Connection instanceof Packages) {
               $held += strlen($Connection->carry);
               if ($Connection->Decoder instanceof Decoder_Holding) {
                  $holds++;
                  $widest = max($widest, strlen($Connection->carry));
               }
            }
         }

         return $Response(body: (string) json_encode([
            'pid' => getmypid(),
            'group' => posix_getpgid(0),
            'connections' => count(Connections::$Connections),
            'usage' => $usage,
            'peak' => memory_get_peak_usage(),
            'limit' => ini_get('memory_limit'),
            'budget' => TCP_Server_CLI::$maxWorkerPendingBytes,
            'inbound' => $Inspect(Shares::Inbound),
            'transport' => $Inspect(Shares::Transport),
            'parked' => $GLOBALS['hhsc6_parked'] ?? 0,
            'most' => $GLOBALS['hhsc6_most'] ?? 0,
            'holds' => $holds,
            'held' => $held,
            'widest' => $widest,
            'charges' => $GLOBALS['hhsc6_charges'] ?? [],
         ]));
      }, GET);

      // # A long-poll: parks until `/w`
      yield $Router->route('/d', static function (
         Request $Request,
         Response $Response,
      ) use ($Inspect, $Price, $Park): Response {
         // ! The snapshot's price, read on the decoded request, and the
         //   Inbound ledger before the snapshot is taken
         $price = $Price($Request);
         $before = $Inspect(Shares::Inbound);

         // @ A snapshot the budget refuses throws out of defer() (503)
         $Response->defer(static function (Response $Response) use ($Inspect, $Park, $price, $before): void {
            // ! What the capture moved the Inbound ledger by, by price
            $charge = $Inspect(Shares::Inbound) - $before;
            $key = "{$price}:{$charge}";
            $GLOBALS['hhsc6_charges'][$key] = ($GLOBALS['hhsc6_charges'][$key] ?? 0) + 1;

            $Park($Response, 0.0);
         });

         return $Response;
      }, GET);

      // # A short park: 0.3 s
      yield $Router->route('/t', static function (
         Request $Request,
         Response $Response,
      ) use ($Park): Response {
         $Response->defer(static function (Response $Response) use ($Park): void {
            $Park($Response, microtime(true) + 0.3);
         });

         return $Response;
      }, GET);

      // # A synchronous answer
      yield $Router->route('/e', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $tag = $Request->Header->get('X-Req') ?? '-';

         return $Response(body: "echo:{$tag}");
      }, GET);

      // # Wake every parked deferral
      yield $Router->route('/w', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $woken = $GLOBALS['hhsc6_parked'] ?? 0;
         $Pair = $GLOBALS['hhsc6_pair'];
         $GLOBALS['hhsc6_pair'] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         @fwrite($Pair[1], 'w');
         @fclose($Pair[1]);

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
   /** Next port offset inside the 19700-19749 range. */
   public int $cursor = -1;
   /** @var array<string,array<string,mixed>> Evidence by row. */
   public array $rows = [];
};

// ! The bytes one transport read takes — the most a hold keeps
$read = Packages::READ;
// ! What a parked deferral may be charged at most, against what it keeps
$margin = 1.25;
// ! Each row's `memory_limit`: R5 and R8 at 32M, R7 at 64M, every other at 128M
$limits = ['R5' => '32M', 'R7' => '64M', 'R8' => '32M'];
// ! Each row's application heap, in MiB: R8 keeps 6, every other none
$ballasts = ['R8' => 6];

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
 * Exchange one request on a fresh loopback connection, then leave: write it
 * whole, read one answer — its head and exactly Content-Length body bytes.
 *
 * @return array{status:string,body:string}
 */
$Fetch = static function (int $port, string $request, float $timeout = 3.0): array {
   // !
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, $timeout);
   if ($Socket === false) {
      return ['status' => '', 'body' => ''];
   }
   stream_set_blocking($Socket, false);
   $deadline = microtime(true) + $timeout;
   $wire = '';

   try {
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
   }
   finally {
      fclose($Socket);
   }

   // :
   $separator = strpos($wire, "\r\n\r\n");

   return [
      'status' => (string) substr($wire, 9, 3),
      'body' => $separator === false ? '' : substr($wire, $separator + 4),
   ];
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
      usleep(50_000);
   }
   while (microtime(true) < $deadline);

   // :
   return $metrics;
};

/**
 * One `/m` sample: its status and the PID that answered it.
 *
 * @param null|array<string,mixed> $metrics
 *
 * @return array{0:string,1:int}
 */
$Sample = static fn (null|array $metrics): array => [
   (string) ($metrics['status'] ?? 'none'),
   (int) ($metrics['pid'] ?? 0),
];

$Locate = static function () use ($Probe): int {
   // @@ The 19700-19749 range, starting at a random offset
   if ($Probe->cursor < 0) {
      $Probe->cursor = random_int(0, 49);
   }
   for ($attempt = 0; $attempt < 50; $attempt++) {
      $port = 19700 + ($Probe->cursor % 50);
      $Probe->cursor++;

      $Listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
      if ($Listener !== false) {
         fclose($Listener);

         return $port;
      }
   }

   throw new RuntimeException('H-HSC-6 found no free loopback port in 19700-19749.');
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
$Launch = static function (string $row) use ($Probe, $Locate, $Measure, $Stop, $Survey, $limits, $ballasts): array {
   // !
   $port = $Locate();
   $base = "{$Probe->base}/{$row}";
   if (mkdir("{$base}/storage", 0700, true) === false) {
      throw new RuntimeException("H-HSC-6 could not create the {$row} server storage.");
   }
   $log = "{$base}/server.log";
   $limit = $limits[$row] ?? '128M';
   $ballast = $ballasts[$row] ?? 0;
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
         '180',
         PHP_BINARY,
         '-d',
         "memory_limit={$limit}",
         '-d',
         'opcache.jit=0',
         __FILE__,
         '--hhsc6-pipeline-server',
         BOOTGLY_ROOT_BASE,
         $base,
         (string) $port,
         (string) $ballast,
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
      throw new RuntimeException("H-HSC-6 could not start the {$row} server child.");
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

   throw new RuntimeException("H-HSC-6 {$row} server never answered /m (survivors: {$survivors}): {$output}");
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
      $Probe->base = "{$temporary}/bootgly-hhsc6-pipeline-{$token}";
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
      if ($row === 'R8') {
         $Clean($Probe->base);
      }
   }

   // :
   return "GET /hhsc6-pipeline-harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
};

/**
 * Open one nonblocking loopback peer.
 *
 * @return resource
 */
$Connect = static function (int $port): mixed {
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
   if ($Socket === false) {
      throw new RuntimeException("H-HSC-6 peer could not connect: {$message}");
   }
   stream_set_blocking($Socket, false);

   return $Socket;
};

/**
 * Write bytes whole on an open connection, reading nothing; false when the
 * peer is gone before they are.
 */
$Send = static function (mixed $Socket, string $bytes, float $timeout = 3.0): bool {
   // !
   $deadline = microtime(true) + $timeout;
   $offset = 0;
   $length = strlen($bytes);

   // @@
   while ($offset < $length && microtime(true) < $deadline) {
      $Reads = null;
      $Writes = [$Socket];
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 50_000) < 1) {
         continue;
      }
      $written = @fwrite($Socket, substr($bytes, $offset, 1_048_576));
      if ($written === false || ($written === 0 && feof($Socket))) {
         return false;
      }
      $offset += $written;
   }

   // :
   return $offset === $length;
};

/**
 * Read what the peers have for up to `$timeout` seconds — into `$wires`, by
 * peer — marking in `$closed` the ones the server closed; returns early once
 * `$Until` (given the wires) says so.
 *
 * @param array<int|string,resource> $Sockets
 * @param array<int|string,string> $wires
 * @param array<int|string,bool> $closed
 */
$Pump = static function (array $Sockets, array &$wires, array &$closed, float $timeout, null|Closure $Until = null): void {
   // !
   $deadline = microtime(true) + $timeout;

   // @@
   do {
      $Reads = [];
      foreach ($Sockets as $index => $Socket) {
         if (($closed[$index] ?? false) === false) {
            $Reads[$index] = $Socket;
         }
      }
      if ($Reads === [] || ($Until !== null && $Until($wires))) {
         return;
      }

      $Writes = null;
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 20_000) < 1) {
         continue;
      }
      foreach ($Reads as $index => $Socket) {
         $chunk = @fread($Socket, 1 << 20);
         if ($chunk === false || ($chunk === '' && feof($Socket))) {
            $closed[$index] = true;
            continue;
         }
         $wires[$index] ??= '';
         $wires[$index] .= $chunk;
      }
   }
   while (microtime(true) < $deadline);
};

/**
 * Split one peer's wire into its whole answers, each `<status> <body>`.
 *
 * @return array<int,string>
 */
$Split = static function (string $wire): array {
   // !
   $answers = [];
   $offset = 0;
   $total = strlen($wire);

   // @@
   while ($offset < $total) {
      $separator = strpos($wire, "\r\n\r\n", $offset);
      if ($separator === false) {
         break;
      }
      $head = substr($wire, $offset, $separator + 2 - $offset);
      $length = preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $head, $matches) === 1
         ? (int) $matches[1]
         : 0;
      $end = $separator + 4 + $length;
      if ($end > $total) {
         break;
      }
      $status = substr($wire, $offset + 9, 3);
      $body = substr($wire, $separator + 4, $length);
      $answers[] = $status === '200' ? "200 {$body}" : $status;
      $offset = $end;
   }

   // :
   return $answers;
};

/**
 * One GET head to `$target` tagged `X-Req: <tag>`.
 */
$Head = static fn (string $target, string $tag): string => "GET {$target} HTTP/1.1\r\nHost: localhost\r\nX-Req: {$tag}\r\n\r\n";

/**
 * Exactly `$bytes` bytes of pipelined GETs to `$target`, tagged
 * `<prefix>-<n>`: the last head carries an `X-Pad` line to fill them.
 */
$Fill = static function (string $target, string $prefix, int $bytes) use ($Head): string {
   // !
   $heads = '';
   $index = 0;

   // @@ Whole heads while the room after them still fits a padded one
   while (true) {
      $index++;
      $head = $Head($target, "{$prefix}-{$index}");
      $padded = strlen($head) + 9;
      if (strlen($heads) + strlen($head) + $padded > $bytes) {
         break;
      }
      $heads .= $head;
   }

   // @ The last head, padded to the exact size
   $room = $bytes - strlen($heads) - strlen($head) - 9;
   $pad = str_repeat('p', max(0, $room));
   $last = "GET {$target} HTTP/1.1\r\nHost: localhost\r\nX-Req: {$prefix}-{$index}\r\nX-Pad: {$pad}\r\n\r\n";

   // :
   return "{$heads}{$last}";
};

/**
 * One HTTP/2 frame.
 */
$Frame = static function (int $type, int $flags, int $stream, string $payload): string {
   $length = substr(pack('N', strlen($payload)), 1);
   $kind = chr($type);
   $flag = chr($flags);
   $identifier = pack('N', $stream & 0x7fffffff);

   return "{$length}{$kind}{$flag}{$identifier}{$payload}";
};

/**
 * The `:status` of one response header block: a static-table index, or the
 * literal with the static name index 8; hex of what else it starts with.
 */
$Decode = static function (string $block): string {
   // ?
   if ($block === '') {
      return '';
   }

   // ! The static table's indexed `:status` entries
   $indexed = [0x88 => '200', 0x89 => '204', 0x8a => '206', 0x8b => '304', 0x8c => '400', 0x8d => '404', 0x8e => '500'];
   $first = ord($block[0]);

   // ?:
   if (isset($indexed[$first])) {
      return $indexed[$first];
   }
   if (in_array($first, [0x08, 0x18, 0x48], true) && strlen($block) >= 5 && ord($block[1]) === 3) {
      return substr($block, 2, 3);
   }

   // :
   return bin2hex(substr($block, 0, 6));
};

/**
 * Read every open h2c connection for up to `$timeout` seconds and parse its
 * frames: acknowledge SETTINGS and PING, record each stream's `:status`,
 * every RST_STREAM and GOAWAY; returns early once `$Until` says so.
 *
 * @param array<int,array<string,mixed>> $sessions
 */
$Read = static function (array &$sessions, float $timeout, null|Closure $Until = null) use ($Frame, $Send, $Decode): void {
   // !
   $deadline = microtime(true) + $timeout;

   // @@
   do {
      $Reads = [];
      foreach ($sessions as $index => $session) {
         if ($session['closed'] === false) {
            $Reads[$index] = $session['Socket'];
         }
      }
      if ($Reads === [] || ($Until !== null && $Until($sessions))) {
         return;
      }

      $Writes = null;
      $Except = null;
      if (@stream_select($Reads, $Writes, $Except, 0, 20_000) < 1) {
         continue;
      }
      foreach (array_keys($Reads) as $index) {
         $session = &$sessions[$index];
         $chunk = @fread($session['Socket'], 1 << 20);
         if ($chunk === false || ($chunk === '' && feof($session['Socket']))) {
            $session['closed'] = true;
            unset($session);
            continue;
         }
         $session['buffer'] .= $chunk;

         // @@ Every complete frame
         while (strlen($session['buffer']) >= 9) {
            $prefix = substr($session['buffer'], 0, 3);
            $length = unpack('N', "\0{$prefix}")[1];
            if (strlen($session['buffer']) < 9 + $length) {
               break;
            }
            $type = ord($session['buffer'][3]);
            $flags = ord($session['buffer'][4]);
            $stream = unpack('N', substr($session['buffer'], 5, 4))[1] & 0x7fffffff;
            $payload = substr($session['buffer'], 9, $length);
            $session['buffer'] = substr($session['buffer'], 9 + $length);

            if ($type === 4 && ($flags & 1) === 0) {
               $Send($session['Socket'], $Frame(4, 1, 0, ''));
            }
            else if ($type === 6 && ($flags & 1) === 0) {
               $Send($session['Socket'], $Frame(6, 1, 0, $payload));
            }
            else if ($type === 1) {
               // ! PADDED and PRIORITY lead the header block fragment
               $start = 0;
               if (($flags & 0x8) !== 0) {
                  $start = 1;
               }
               if (($flags & 0x20) !== 0) {
                  $start += 5;
               }
               $session['statuses'][$stream] = $Decode(substr($payload, $start));
            }
            else if ($type === 3) {
               $session['resets'][$stream] = unpack('N', $payload)[1];
            }
            else if ($type === 7) {
               $session['goaway'] = unpack('N', substr($payload, 4, 4))[1];
            }
         }
         unset($session);
      }
   }
   while (microtime(true) < $deadline);
};

return new Test(
   description: 'H-HSC-6: an HTTP/1.1 connection parks one deferral at a time, in request order, and every park is charged its generation',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => $Run('R1', static function (array $server) use ($Fetch, $Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Split, $Head): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $pid = (int) ($base['pid'] ?? 0);
         $ordered = 50;
         $flood = 6_000;
         $batch = 50;
         $samples = [$Sample($base)];

         $Socket = $Connect($port);
         $wires = [''];
         $closed = [false];
         $steps = [];
         $sent = 0;
         $sampled = [];
         try {
            // @ Order: requests pipelined in one write, then one wake at a time
            $heads = '';
            for ($index = 1; $index <= $ordered; $index++) {
               $heads .= $Head('/d', "r1-{$index}");
            }
            $Send($Socket, $heads);

            // @@ Before each wake: what is parked and held; after it: how many
            //   answers the connection has
            for ($index = 1; $index <= $ordered; $index++) {
               $Pump([$Socket], $wires, $closed, 0.0);
               $parked = [];
               $deadline = microtime(true) + 3.0;
               do {
                  $parked = $Measure($port, 2.0) ?? [];
                  if ((int) ($parked['parked'] ?? 0) >= 1) {
                     break;
                  }
                  usleep(10_000);
               }
               while (microtime(true) < $deadline);
               $samples[] = $Sample($parked);

               $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
               $Pump([$Socket], $wires, $closed, 3.0, static fn (array $wires): bool => count($Split($wires[0])) >= $index);
               // ! A wake that answers more than the next request shows here
               $Pump([$Socket], $wires, $closed, 0.02);
               $answered = count($Split($wires[0]));
               $steps[] = [
                  (int) ($parked['parked'] ?? -1),
                  (int) ($parked['holds'] ?? -1),
                  "{$wake['status']} {$wake['body']}",
                  $answered,
               ];

               // ? Every request answered already, or the connection is gone
               if ($answered >= $ordered || $closed[0]) {
                  break;
               }
            }

            // @@ Flood: writes of 50 pipelined parking GETs that wait for no
            //   answer; a sample every 500
            for ($offset = 0; $offset < $flood && $closed[0] === false; $offset += $batch) {
               $heads = '';
               for ($index = 1; $index <= $batch; $index++) {
                  $number = $ordered + $offset + $index;
                  $heads .= $Head('/d', "r1-{$number}");
               }
               if ($Send($Socket, $heads, 2.0) === false) {
                  $closed[0] = true;
                  break;
               }
               $sent += $batch;
               $Pump([$Socket], $wires, $closed, 0.0);

               if ($sent % 500 === 0) {
                  $metrics = $Measure($port, 2.0);
                  $samples[] = $Sample($metrics);
                  $sampled[] = (int) ($metrics['parked'] ?? -1);
                  // ? A dead worker answers no more of the flood
                  if ((int) ($metrics['pid'] ?? 0) !== $pid) {
                     break;
                  }
               }
            }
            $Pump([$Socket], $wires, $closed, 1.0);
         }
         finally {
            fclose($Socket);
         }
         $rest = $Settle($port, ['parked', 'holds', 'held'], [0, 0, 0]) ?? [];
         $samples[] = $Sample($rest);

         // :
         $answers = $Split($wires[0]);

         return [
            'ordered' => $ordered,
            'flood' => $flood,
            'steps' => $steps,
            'sent' => $sent,
            'closed' => $closed[0],
            'sampled' => $sampled,
            'answers' => $answers,
            'statuses' => array_count_values(array_map(static fn (string $answer): string => substr($answer, 0, 3), $answers)),
            'most' => $rest['most'] ?? null,
            'rest' => [$rest['parked'] ?? null, $rest['holds'] ?? null, $rest['held'] ?? null],
            // ! Informational: worker memory at rest and after the flood; its peak
            'usage' => [$base['usage'] ?? null, $rest['usage'] ?? null],
            'peak' => $rest['peak'] ?? null,
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('R2', static function (array $server) use ($Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Head, $Fill, $read): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $pipeline = $Fill('/e', 'r2', $read);

         $Socket = $Connect($port);
         $wires = [''];
         $closed = [false];
         $parked = [];
         $holding = [];
         $quiet = '';
         $cut = false;
         try {
            // @ One deferral parks
            $Send($Socket, $Head('/d', 'r2-0'));
            $parked = $Settle($port, ['parked', 'holds'], [1, 1], 3.0) ?? [];

            // @ One transport read of pipelined requests behind it
            $Send($Socket, $pipeline);
            $holding = $Settle($port, ['held'], [$read], 3.0) ?? [];
            $Pump([$Socket], $wires, $closed, 0.3);
            $quiet = $wires[0];

            // @ One more byte
            $Send($Socket, 'G');
            $Pump([$Socket], $wires, $closed, 3.0);
            $cut = $closed[0];
         }
         finally {
            fclose($Socket);
         }
         $released = $Settle(
            $port,
            ['connections', 'parked', 'holds', 'held', 'transport', 'inbound'],
            [
               (int) ($base['connections'] ?? -1),
               0,
               0,
               0,
               (int) ($base['transport'] ?? -1),
               (int) ($base['inbound'] ?? -1),
            ],
         ) ?? [];

         // :
         return [
            'pipeline' => strlen($pipeline),
            'base' => [$base['connections'] ?? null, $base['transport'] ?? null, $base['inbound'] ?? null],
            'parked' => [$parked['parked'] ?? null, $parked['holds'] ?? null],
            'holding' => [
               $holding['parked'] ?? null,
               $holding['holds'] ?? null,
               $holding['held'] ?? null,
               $holding['transport'] ?? null,
            ],
            'quiet' => strlen($quiet),
            'cut' => $cut,
            'wire' => strlen($wires[0]),
            'released' => [
               $released['connections'] ?? null,
               $released['parked'] ?? null,
               $released['holds'] ?? null,
               $released['held'] ?? null,
               $released['transport'] ?? null,
               $released['inbound'] ?? null,
            ],
            'samples' => [$Sample($base), $Sample($parked), $Sample($holding), $Sample($released)],
         ];
      }),
      static fn (): string => $Run('R3', static function (array $server) use ($Measure, $Sample, $Connect, $Send, $Pump, $Split, $Head): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         // ! One connection's two answers and the seconds each took to arrive
         $Order = static function (string $first, string $second, float $gap) use ($port, $Connect, $Send, $Pump, $Split): array {
            $Socket = $Connect($port);
            $wires = [''];
            $closed = [false];
            $arrivals = [];
            try {
               $start = microtime(true);
               $Send($Socket, $gap > 0 ? $first : "{$first}{$second}");
               if ($gap > 0) {
                  $Pump([$Socket], $wires, $closed, $gap);
                  $Send($Socket, $second);
               }
               // @@ Each answer's arrival
               $deadline = $start + 3.0;
               while (count($arrivals) < 2 && $closed[0] === false && microtime(true) < $deadline) {
                  $Pump([$Socket], $wires, $closed, 0.01);
                  $count = count($Split($wires[0]));
                  while (count($arrivals) < $count) {
                     $arrivals[] = round(microtime(true) - $start, 3);
                  }
               }
            }
            finally {
               fclose($Socket);
            }

            return [$Split($wires[0]), $arrivals, $closed[0]];
         };

         // @ The same write, then a later write
         $same = $Order($Head('/t', 'r3-a1'), $Head('/e', 'r3-a2'), 0.0);
         $later = $Order($Head('/t', 'r3-b1'), $Head('/e', 'r3-b2'), 0.05);
         $after = $Measure($port) ?? [];

         // :
         return [
            'same' => $same,
            'later' => $later,
            'samples' => [$Sample($base), $Sample($after)],
         ];
      }),
      static fn (): string => $Run('R4', static function (array $server) use ($Fetch, $Measure, $Settle, $Sample, $Frame, $Send, $Read): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $pid = (int) ($base['pid'] ?? 0);
         $peers = 40;
         $streams = 128;
         // ! Request blocks: GET, http, `:path` /d, `:authority` localhost —
         //   literals without indexing — and a unique `x-req`
         $Block = static function (string $tag): string {
            $size = chr(strlen($tag));

            return "\x82\x86\x04\x02/d\x01\x09localhost\x00\x05x-req{$size}{$tag}";
         };
         // ! The largest stream window and connection window
         $settings = $Frame(4, 0, 0, pack('nN', 4, 0x7fffffff));
         $window = $Frame(8, 0, 0, pack('N', 0x7fffffff - 65_535));
         $samples = [$Sample($base)];

         $sessions = [];
         $sent = 0;
         $rest = [];
         $refused = [];
         $wake = ['status' => '', 'body' => ''];
         $woken = -1;
         // ! Every status the streams were answered with, by status
         $Tally = static function (array $sessions): array {
            $statuses = [];
            foreach ($sessions as $session) {
               foreach ($session['statuses'] as $status) {
                  $statuses[$status] = ($statuses[$status] ?? 0) + 1;
               }
            }

            return $statuses;
         };
         // ! How many streams were answered or reset
         $Count = static function (array $sessions): int {
            $answered = 0;
            foreach ($sessions as $session) {
               $answered += count($session['statuses']) + count($session['resets']);
            }

            return $answered;
         };
         try {
            // @@ Every connection: the preface, its settings and window
            for ($index = 0; $index < $peers; $index++) {
               $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
               if ($Socket === false) {
                  throw new RuntimeException("H-HSC-6 R4 h2c connection {$index} failed: {$message}");
               }
               stream_set_blocking($Socket, false);
               $sessions[$index] = [
                  'Socket' => $Socket,
                  'buffer' => '',
                  'closed' => false,
                  'statuses' => [],
                  'resets' => [],
                  'goaway' => null,
               ];
               if ($Send($Socket, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n{$settings}{$window}") === false) {
                  $sessions[$index]['closed'] = true;
               }
            }
            $Read($sessions, 0.3);

            // @@ One stream on every connection per round
            for ($round = 0; $round < $streams; $round++) {
               $stream = 1 + 2 * $round;
               foreach ($sessions as $index => $session) {
                  if ($session['closed'] || $session['goaway'] !== null) {
                     continue;
                  }
                  if ($Send($session['Socket'], $Frame(1, 0x5, $stream, $Block("c{$index}-s{$stream}"))) === false) {
                     $sessions[$index]['closed'] = true;
                     continue;
                  }
                  $sent++;
               }
               $Read($sessions, 0.01);

               if (($round + 1) % 16 === 0) {
                  $metrics = $Measure($port, 2.0);
                  $samples[] = $Sample($metrics);
                  // ? A dead worker answers no more streams
                  if ((int) ($metrics['pid'] ?? 0) !== $pid) {
                     break;
                  }
               }
            }

            // @ Until every stream parked or was answered
            $deadline = microtime(true) + 8.0;
            do {
               $Read($sessions, 0.2);
               $rest = $Measure($port, 2.0) ?? [];
            }
            while (
               $Count($sessions) + (int) ($rest['parked'] ?? 0) < $sent
               && (int) ($rest['pid'] ?? 0) === $pid
               && microtime(true) < $deadline
            );
            $samples[] = $Sample($rest);
            $refused = $Tally($sessions);

            // @ Wake every parked stream, then read their answers: the 200s
            //   the wake added
            $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $Read($sessions, 8.0, static function (array $sessions) use ($Count, $sent): bool {
               return $Count($sessions) >= $sent;
            });
            $woken = ($Tally($sessions)['200'] ?? 0) - ($refused['200'] ?? 0);
         }
         finally {
            foreach ($sessions as $session) {
               @fclose($session['Socket']);
            }
         }
         $released = $Settle($port, ['parked', 'inbound'], [0, (int) ($base['inbound'] ?? -1)]) ?? [];
         $samples[] = $Sample($released);

         // :
         $resets = 0;
         $goaways = 0;
         foreach ($sessions as $session) {
            $resets += count($session['resets']);
            $goaways += $session['goaway'] === null ? 0 : 1;
         }

         return [
            'shape' => [$peers, $streams],
            'budget' => $base['budget'] ?? null,
            'base' => $base['inbound'] ?? null,
            'sent' => $sent,
            'parked' => $rest['parked'] ?? null,
            'inbound' => $rest['inbound'] ?? null,
            'transport' => $rest['transport'] ?? null,
            'refused' => $refused,
            'resets' => $resets,
            'goaways' => $goaways,
            'wake' => "{$wake['status']} {$wake['body']}",
            'woken' => $woken,
            'released' => [$released['parked'] ?? null, $released['inbound'] ?? null],
            // ! Informational: worker memory at rest, with every stream
            //   settled and released; its peak
            'usage' => [$base['usage'] ?? null, $rest['usage'] ?? null, $released['usage'] ?? null],
            'peak' => $released['peak'] ?? null,
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('R5', static function (array $server) use ($Fetch, $Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Split, $Head): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $pid = (int) ($base['pid'] ?? 0);
         $peers = 960;
         $step = 60;
         $samples = [$Sample($base)];

         $Sockets = [];
         $wires = [];
         $closed = [];
         $rest = [];
         $refused = [];
         $wake = ['status' => '', 'body' => ''];
         try {
            // @@ One parking GET per peer, a sample every 60 peers
            for ($index = 0; $index < $peers; $index++) {
               $Sockets[$index] = $Connect($port);
               $wires[$index] = '';
               $closed[$index] = false;
               $Send($Sockets[$index], $Head('/d', "r5-{$index}"));

               if (($index + 1) % $step === 0) {
                  $Pump($Sockets, $wires, $closed, 0.2);
                  $metrics = $Measure($port, 2.0);
                  $samples[] = $Sample($metrics);
                  // ? A dead worker answers no more peers
                  if ((int) ($metrics['pid'] ?? 0) !== $pid) {
                     break;
                  }
               }
            }
            $Pump($Sockets, $wires, $closed, 1.0);
            $rest = $Measure($port) ?? [];
            $samples[] = $Sample($rest);
            foreach ($wires as $index => $wire) {
               $refused[$index] = $Split($wire);
            }

            // @ Wake every parked deferral, then read every answer
            $wake = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $Pump($Sockets, $wires, $closed, 8.0, static function (array $wires) use ($Split): bool {
               foreach ($wires as $wire) {
                  if ($Split($wire) === []) {
                     return false;
                  }
               }

               return true;
            });
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle($port, ['connections', 'parked', 'inbound'], [1, 0, (int) ($base['inbound'] ?? -1)]) ?? [];
         $samples[] = $Sample($released);

         // :
         $outcomes = [];
         foreach ($wires as $index => $wire) {
            $answers = $Split($wire);
            $woke = $answers === ["200 deferred:r5-{$index}"] ? 'woke' : implode(',', $answers);
            $outcomes[] = $refused[$index] === []
               ? "parked {$woke}"
               : implode(',', $refused[$index]);
         }

         return [
            'peers' => $peers,
            'connected' => count($Sockets),
            'limit' => $base['limit'] ?? null,
            'budget' => $base['budget'] ?? null,
            'base' => $base['inbound'] ?? null,
            'parked' => $rest['parked'] ?? null,
            'inbound' => $rest['inbound'] ?? null,
            'outcomes' => array_count_values($outcomes),
            'wake' => "{$wake['status']} {$wake['body']}",
            'released' => [$released['connections'] ?? null, $released['parked'] ?? null, $released['inbound'] ?? null],
            // ! Informational: worker memory at rest, with every peer settled
            //   and released; its peak
            'usage' => [$base['usage'] ?? null, $rest['usage'] ?? null, $released['usage'] ?? null],
            'peak' => $released['peak'] ?? null,
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('R6', static function (array $server) use ($Fetch, $Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Split, $Head): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $first = 50;
         $peers = 600;
         $samples = [$Sample($base)];
         // ! Ramp `$peers` peers that each send one GET to `$target` and stay;
         //   `/m` at `$first` and at `$peers` once each settled
         $Ramp = static function (string $target, string $field) use ($port, $first, $peers, $Fetch, $Settle, $Connect, $Send, $Pump, $Split, $Head): array {
            $Sockets = [];
            $wires = [];
            $closed = [];
            $readings = [];
            $parks = $target === '/d';
            try {
               foreach ([$first, $peers] as $mark) {
                  for ($index = count($Sockets); $index < $mark; $index++) {
                     $Sockets[$index] = $Connect($port);
                     $wires[$index] = '';
                     $closed[$index] = false;
                     $Send($Sockets[$index], $Head($target, sprintf('r6-%s-%04d', $field, $index)));
                  }
                  // @ Settled: every park parked, or every peer answered
                  if ($parks) {
                     $readings[] = $Settle($port, ['parked'], [$mark], 8.0) ?? [];
                  }
                  else {
                     $Pump($Sockets, $wires, $closed, 8.0, static function (array $wires) use ($Split): bool {
                        foreach ($wires as $wire) {
                           if ($Split($wire) === []) {
                              return false;
                           }
                        }

                        return true;
                     });
                     $readings[] = $Settle($port, ['connections'], [$mark + 1], 8.0) ?? [];
                  }
               }

               // @ Parks: wake them all and read every answer
               $wake = '';
               if ($parks) {
                  $woken = $Fetch($port, "GET /w HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
                  $wake = "{$woken['status']} {$woken['body']}";
                  $Pump($Sockets, $wires, $closed, 8.0, static function (array $wires) use ($Split): bool {
                     foreach ($wires as $wire) {
                        if ($Split($wire) === []) {
                           return false;
                        }
                     }

                     return true;
                  });
               }
            }
            finally {
               foreach ($Sockets as $Socket) {
                  fclose($Socket);
               }
            }

            // ! Every peer's answer, by kind
            $answers = [];
            foreach ($wires as $index => $wire) {
               $tag = sprintf('r6-%s-%04d', $field, $index);
               $expected = $parks ? "200 deferred:{$tag}" : "200 echo:{$tag}";
               $answers[] = $Split($wire) === [$expected] ? 'answered' : implode(',', $Split($wire));
            }

            return [$readings, array_count_values($answers), $wake];
         };

         // @ Idle peers first (the Fiber pool stays empty for the parks), then
         //   parked ones
         [$idle, $idled, ] = $Ramp('/e', 'e');
         $between = $Settle($port, ['connections'], [1]) ?? [];
         [$park, $parked, $wake] = $Ramp('/d', 'd');
         $released = $Settle($port, ['connections', 'parked', 'inbound'], [1, 0, (int) ($base['inbound'] ?? -1)]) ?? [];
         $samples = [
            ...$samples,
            ...array_map($Sample, $idle),
            $Sample($between),
            ...array_map($Sample, $park),
            $Sample($released),
         ];

         // :
         $span = $peers - $first;

         return [
            'shape' => [$first, $peers],
            'idle' => [
               array_column($idle, 'connections'),
               array_column($idle, 'usage'),
               $idled,
            ],
            'park' => [
               array_column($park, 'parked'),
               array_column($park, 'usage'),
               array_column($park, 'inbound'),
               $parked,
            ],
            'charges' => $park[1]['charges'] ?? null,
            'idler' => intdiv((int) ($idle[1]['usage'] ?? 0) - (int) ($idle[0]['usage'] ?? 0), $span),
            'parker' => intdiv((int) ($park[1]['usage'] ?? 0) - (int) ($park[0]['usage'] ?? 0), $span),
            'grown' => (int) ($park[1]['inbound'] ?? 0) - (int) ($park[0]['inbound'] ?? 0),
            'wake' => $wake,
            'base' => $base['inbound'] ?? null,
            'released' => [$released['connections'] ?? null, $released['parked'] ?? null, $released['inbound'] ?? null],
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('R7', static function (array $server) use ($Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Split, $Head, $Fill, $read): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $pid = (int) ($base['pid'] ?? 0);
         $budget = (int) ($base['budget'] ?? 0);
         // ! The measured peers, then the peers past the budget once their
         //   measured charge says where it ends; a sample every `$step` peers
         $measured = 100;
         $beyond = 32;
         $step = 50;
         $samples = [$Sample($base)];
         // ! One peer's attack: one parking GET and exactly one transport
         //   read of parking GETs pipelined behind it, in one write
         $Attack = static function (int $index) use ($Head, $Fill, $read): string {
            $head = $Head('/d', "r7-{$index}");
            $pipeline = $Fill('/d', "r7-{$index}", $read);

            return "{$head}{$pipeline}";
         };
         // ! One `/m` reading, reduced to what the row judges
         $Pick = static fn (array $metrics): array => [
            'pid' => $metrics['pid'] ?? null,
            'connections' => $metrics['connections'] ?? null,
            'parked' => $metrics['parked'] ?? null,
            'holds' => $metrics['holds'] ?? null,
            'held' => $metrics['held'] ?? null,
            'widest' => $metrics['widest'] ?? null,
            'usage' => $metrics['usage'] ?? null,
            'inbound' => $metrics['inbound'] ?? null,
            'transport' => $metrics['transport'] ?? null,
         ];

         $Sockets = [];
         $wires = [];
         $closed = [];
         $readings = [];
         $peers = 0;
         $charge = 0;
         $rest = [];
         try {
            // @ Measured: `$measured` idle keep-alive peers (one answered
            //   `/e` each), then each parks one deferral holding its read
            for ($index = 0; $index < $measured; $index++) {
               $Sockets[$index] = $Connect($port);
               $wires[$index] = '';
               $closed[$index] = false;
               $Send($Sockets[$index], $Head('/e', "r7-{$index}"));
            }
            $Pump($Sockets, $wires, $closed, 8.0, static function (array $wires) use ($Split): bool {
               foreach ($wires as $wire) {
                  if ($Split($wire) === []) {
                     return false;
                  }
               }

               return true;
            });
            $readings['idle'] = $Settle($port, ['connections'], [$measured + 1], 8.0) ?? [];
            // ! Only what the attack is answered counts from here on
            foreach ($Sockets as $index => $Socket) {
               $wires[$index] = '';
               $Send($Socket, $Attack($index));
            }
            $readings['hold'] = $Settle($port, ['parked', 'holds', 'held'], [$measured, $measured, $measured * $read], 8.0) ?? [];
            $samples[] = $Sample($readings['idle']);
            $samples[] = $Sample($readings['hold']);

            // ! What one measured peer — its park and its held read — is
            //   charged (Inbound + Transport); then the peers that fill the
            //   rest of the budget, one that does not fit and `$beyond` more
            $charge = intdiv(
               (int) ($readings['hold']['inbound'] ?? 0) - (int) ($readings['idle']['inbound'] ?? 0)
                  + (int) ($readings['hold']['transport'] ?? 0) - (int) ($readings['idle']['transport'] ?? 0),
               $measured,
            );
            $room = $budget - (int) ($readings['hold']['inbound'] ?? 0) - (int) ($readings['hold']['transport'] ?? 0);
            $peers = $measured + ($charge > 0 ? intdiv(max(0, $room), $charge) + 1 : 0) + $beyond;

            // @@ The other peers, a sample every `$step` — a refusal flood
            //   can keep the worker busy for seconds, so a sample waits
            for ($index = $measured; $index < $peers; $index++) {
               $Sockets[$index] = $Connect($port);
               $wires[$index] = '';
               $closed[$index] = false;
               $Send($Sockets[$index], $Attack($index));

               if (($index + 1) % $step === 0) {
                  $Pump($Sockets, $wires, $closed, 0.2);
                  $metrics = $Measure($port, 15.0);
                  $samples[] = $Sample($metrics);
                  // ? A dead worker answers no more peers
                  if ((int) ($metrics['pid'] ?? 0) !== $pid) {
                     break;
                  }
               }
            }
            $Pump($Sockets, $wires, $closed, 1.0);
            $rest = $Measure($port, 15.0) ?? [];
            $samples[] = $Sample($rest);
            // @ Until every peer the worker does not hold is closed or
            //   answered: as many open, unanswered peers as parked deferrals
            $Pump($Sockets, $wires, $closed, 8.0, static function (array $wires) use (&$closed, $rest): bool {
               $waiting = 0;
               foreach ($wires as $index => $wire) {
                  if ($wire === '' && ($closed[$index] ?? false) === false) {
                     $waiting++;
                  }
               }

               return $waiting === ($rest['parked'] ?? -1);
            });
         }
         finally {
            foreach ($Sockets as $Socket) {
               fclose($Socket);
            }
         }
         $released = $Settle(
            $port,
            ['connections', 'parked', 'holds', 'held', 'inbound', 'transport'],
            [1, 0, 0, 0, (int) ($base['inbound'] ?? -1), (int) ($base['transport'] ?? -1)],
         ) ?? [];
         $samples[] = $Sample($released);

         // :
         $statuses = [];
         $outcomes = [];
         foreach ($wires as $index => $wire) {
            $answers = $Split($wire);
            foreach ($answers as $answer) {
               $status = substr($answer, 0, 3);
               $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            }
            $outcomes[] = match (true) {
               $answers === [] && $closed[$index] => 'closed',
               $answers === [] => 'parked',
               default => ($closed[$index] ? 'refused, closed' : 'refused'),
            };
         }
         $phases = array_map($Pick, [$readings['idle'] ?? [], $readings['hold'] ?? []]);

         return [
            'shape' => [$measured, $beyond],
            'peers' => $peers,
            'connected' => count($Sockets),
            'limit' => $base['limit'] ?? null,
            'budget' => $base['budget'] ?? null,
            'base' => [$base['inbound'] ?? null, $base['transport'] ?? null],
            // ! Per measured peer: what its park and held read keep, and what
            //   the Inbound and Transport ledgers charge them
            'peer' => [
               'kept' => intdiv((int) ($phases[1]['usage'] ?? 0) - (int) ($phases[0]['usage'] ?? 0), $measured),
               'charged' => $charge,
            ],
            'measured' => $phases,
            'rest' => [
               $rest['parked'] ?? null,
               $rest['holds'] ?? null,
               $rest['held'] ?? null,
               $rest['widest'] ?? null,
               $rest['inbound'] ?? null,
               $rest['transport'] ?? null,
            ],
            'statuses' => $statuses,
            'outcomes' => array_count_values($outcomes),
            'released' => [
               $released['connections'] ?? null,
               $released['parked'] ?? null,
               $released['holds'] ?? null,
               $released['held'] ?? null,
               $released['inbound'] ?? null,
               $released['transport'] ?? null,
            ],
            // ! Informational: worker memory at rest, with every peer settled
            //   and released; its peak
            'usage' => [$base['usage'] ?? null, $rest['usage'] ?? null, $released['usage'] ?? null],
            'peak' => $released['peak'] ?? null,
            'samples' => $samples,
         ];
      }),
      static fn (): string => $Run('R8', static function (array $server) use ($Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Split, $Head, $Fill, $read): array {
         // !
         $port = (int) $server['port'];
         $base = $Measure($port) ?? [];
         $half = intdiv($read, 2);
         $peers = 100;
         $flood = 300;
         $samples = [$Sample($base)];
         // ! The ledgers every phase returns to once its peers left
         $fields = ['connections', 'parked', 'holds', 'held', 'inbound', 'transport'];
         $targets = [1, 0, 0, 0, (int) ($base['inbound'] ?? -1), (int) ($base['transport'] ?? -1)];
         // ! One `/m` reading, reduced to what the row judges
         $Pick = static fn (array $metrics): array => [
            'pid' => $metrics['pid'] ?? null,
            'connections' => $metrics['connections'] ?? null,
            'parked' => $metrics['parked'] ?? null,
            'holds' => $metrics['holds'] ?? null,
            'held' => $metrics['held'] ?? null,
            'usage' => $metrics['usage'] ?? null,
            'inbound' => $metrics['inbound'] ?? null,
            'transport' => $metrics['transport'] ?? null,
         ];
         // ! Every peer answered at least once
         $Answered = static function (array $wires) use ($Split): bool {
            foreach ($wires as $wire) {
               if ($Split($wire) === []) {
                  return false;
               }
            }

            return true;
         };

         // ! Measured: `$peers` idle keep-alive peers (one answered `/e`
         //   each), then one parked `/d` each, then exactly `$bytes` of
         //   pipelined `/e` each in ONE write — `/m` once each step settled;
         //   then every peer leaves
         $Hold = static function (int $bytes) use ($port, $peers, $fields, $targets, $Pick, $Answered, $Settle, $Sample, $Connect, $Send, $Pump, $Split, $Head, $Fill): array {
            $Sockets = [];
            $wires = [];
            $closed = [];
            $readings = [];
            try {
               // @ Idle: one answered exchange per peer
               for ($index = 0; $index < $peers; $index++) {
                  $Sockets[$index] = $Connect($port);
                  $wires[$index] = '';
                  $closed[$index] = false;
                  $Send($Sockets[$index], $Head('/e', "r8-{$bytes}-{$index}"));
               }
               $Pump($Sockets, $wires, $closed, 8.0, $Answered);
               $readings['idle'] = $Settle($port, ['connections'], [$peers + 1], 8.0) ?? [];

               // @ Park: one long-poll per peer
               foreach ($Sockets as $index => $Socket) {
                  $Send($Socket, $Head('/d', "r8-{$bytes}-{$index}"));
               }
               $readings['park'] = $Settle($port, ['parked', 'holds'], [$peers, $peers], 8.0) ?? [];

               // @ Hold: exactly `$bytes` of pipelined requests per peer, one write each
               foreach ($Sockets as $index => $Socket) {
                  $Send($Socket, $Fill('/e', "r8-{$bytes}-{$index}", $bytes));
               }
               $readings['hold'] = $Settle($port, ['held'], [$peers * $bytes], 8.0) ?? [];
               $Pump($Sockets, $wires, $closed, 0.1);
            }
            finally {
               foreach ($Sockets as $Socket) {
                  fclose($Socket);
               }
            }
            $readings['released'] = $Settle($port, $fields, $targets) ?? [];

            // :
            $answers = 0;
            foreach ($wires as $wire) {
               $answers += count($Split($wire));
            }

            return [
               ...array_map($Pick, $readings),
               'answers' => $answers,
               'closed' => count(array_filter($closed)),
               'samples' => array_values(array_map($Sample, $readings)),
            ];
         };

         // ! Flood (the R1 attack): `$flood` peers that each park one `/d` —
         //   or are answered 503 once the Inbound share is full — then each
         //   write exactly `$bytes` of pipelined `/e` in ONE write
         $Flood = static function (int $bytes) use ($port, $flood, $fields, $targets, $Pick, $Measure, $Settle, $Sample, $Connect, $Send, $Pump, $Head, $Fill): array {
            $Sockets = [];
            $wires = [];
            $closed = [];
            $readings = [];
            try {
               // @ Park: one long-poll per peer
               for ($index = 0; $index < $flood; $index++) {
                  $Sockets[$index] = $Connect($port);
                  $wires[$index] = '';
                  $closed[$index] = false;
                  $Send($Sockets[$index], $Head('/d', "r8-f{$bytes}-{$index}"));
               }
               $Pump($Sockets, $wires, $closed, 1.0);
               $readings['park'] = $Measure($port) ?? [];

               // @ Hold: exactly `$bytes` of pipelined requests per peer, one write each
               foreach ($Sockets as $index => $Socket) {
                  $Send($Socket, $Fill('/e', "r8-f{$bytes}-{$index}", $bytes));
               }
               $Pump($Sockets, $wires, $closed, 1.5);
               $readings['hold'] = $Measure($port) ?? [];
            }
            finally {
               foreach ($Sockets as $Socket) {
                  fclose($Socket);
               }
            }
            $readings['released'] = $Settle($port, $fields, $targets) ?? [];

            // :
            return [
               ...array_map($Pick, $readings),
               'peak' => $readings['hold']['peak'] ?? null,
               'closed' => count(array_filter($closed)),
               'samples' => array_values(array_map($Sample, $readings)),
            ];
         };

         // @ The floods on the fresh worker — the attack first, then the
         //   control — then the measured holds, the control first
         $floods = [];
         $measured = [];
         foreach ([$half, $half - 1] as $bytes) {
            $floods[$bytes] = $Flood($bytes);
            $samples = [...$samples, ...$floods[$bytes]['samples']];
            unset($floods[$bytes]['samples']);
         }
         foreach ([$half - 1, $half] as $bytes) {
            $measured[$bytes] = $Hold($bytes);
            $samples = [...$samples, ...$measured[$bytes]['samples']];
            unset($measured[$bytes]['samples']);
         }

         // :
         return [
            'peers' => [$peers, $flood],
            'limit' => $base['limit'] ?? null,
            'budget' => $base['budget'] ?? null,
            'usage' => $base['usage'] ?? null,
            'base' => $targets,
            'measured' => $measured,
            'floods' => $floods,
            'samples' => $samples,
         ];
      }),
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/hhsc6-pipeline-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'HHSC6-PIPELINE-HARNESS');
      }, GET);
   },

   test: static function (array $responses) use ($Probe, $Clean, $read, $margin): Generator {
      // ! Idempotent: the last row already removed the temp area
      $Clean($Probe->base);

      // ! The generation each parked snapshot is charged, and the snapshot's
      //   own objects (`Request::weigh()`); -1 where they do not exist
      $Reflect = static function (string $name): int {
         try {
            $value = new ReflectionClassConstant(Request::class, $name)->getValue();
         }
         catch (Throwable) {
            return -1;
         }

         return is_int($value) ? $value : -1;
      };
      $generation = $Reflect('GENERATION');
      $snapshot = $Reflect('SNAPSHOT');

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
         'R1' => static function (array $evidence) use ($Liveness): array {
            // ! One parked deferral before every wake, one more answer after it
            $ordered = $evidence['ordered'];
            $steps = [];
            for ($index = 1; $index <= $ordered; $index++) {
               $steps[] = [1, 1, '200 woken 1', $index];
            }
            $tags = [];
            foreach ($evidence['answers'] as $index => $answer) {
               $number = $index + 1;
               $tags[] = "200 deferred:r1-{$number}";
            }
            $sampled = array_filter($evidence['sampled'], static fn (int $parked): bool => $parked < 0 || $parked > 1);

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'before every wake exactly one deferral parked, holding the rest; each wake answered exactly the next request' => $evidence['steps'] === $steps,
               'every answer on the connection is a 200, in request order' => count($evidence['answers']) >= $ordered
                  && $evidence['answers'] === $tags,
               'no request of the connection was refused (no 500, no 503)' => array_keys($evidence['statuses']) === [200],
               'the flood parked one deferral at a time' => $evidence['sent'] >= 500
                  && $evidence['sampled'] !== []
                  && $sampled === []
                  && $evidence['most'] === 1,
               'nothing stayed parked or held once the connection left' => $evidence['rest'] === [0, 0, 0],
            ];
         },
         'R2' => static function (array $evidence) use ($Liveness, $read): array {
            [$connections, $transport, $inbound] = $evidence['base'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'shape: one transport read of pipelined requests' => $evidence['pipeline'] === $read,
               'one deferral parked and its connection holds' => $evidence['parked'] === [1, 1],
               'the pipelined requests wait in the carry, charged, nothing answered' => $evidence['holding'][0] === 1
                  && $evidence['holding'][1] === 1
                  && $evidence['holding'][2] === $read
                  && $evidence['holding'][3] > $transport
                  && $evidence['quiet'] === 0,
               'one more byte closed the connection with nothing written' => $evidence['cut'] === true
                  && $evidence['wire'] === 0,
               'the Transport and Inbound ledgers returned to their base' => $evidence['released'] === [$connections, 0, 0, 0, $transport, $inbound],
            ];
         },
         'R3' => static function (array $evidence) use ($Liveness): array {
            [$same, $sameArrivals, $sameClosed] = $evidence['same'];
            [$later, $laterArrivals, $laterClosed] = $evidence['later'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'same write: the parked answer first, after its park, then the pipelined one' => $same === ['200 deferred:r3-a1', '200 echo:r3-a2']
                  && count($sameArrivals) === 2
                  && $sameArrivals[0] >= 0.25
                  && $sameClosed === false,
               'later write: the parked answer first, after its park, then the pipelined one' => $later === ['200 deferred:r3-b1', '200 echo:r3-b2']
                  && count($laterArrivals) === 2
                  && $laterArrivals[0] >= 0.25
                  && $laterClosed === false,
            ];
         },
         'R4' => static function (array $evidence) use ($Liveness): array {
            [$connections, $streams] = $evidence['shape'];
            $share = intdiv((int) $evidence['budget'], 2);
            $refused = $evidence['refused'];

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'every stream was sent' => $evidence['sent'] === $connections * $streams,
               'every stream parked or was answered 503 — none 500, no RST_STREAM, no GOAWAY' => array_keys($refused) === [503]
                  && $evidence['parked'] + $refused[503] === $evidence['sent']
                  && $evidence['resets'] === 0
                  && $evidence['goaways'] === 0,
               'the parked streams held the Inbound ledger within its share' => $evidence['parked'] >= 1
                  && $evidence['inbound'] > $evidence['base']
                  && $evidence['inbound'] <= $share,
               'every parked stream woke and answered 200' => $evidence['wake'] === "200 woken {$evidence['parked']}"
                  && $evidence['woken'] === $evidence['parked'],
               'the Inbound ledger returned to its base' => $evidence['released'] === [0, $evidence['base']],
            ];
         },
         'R5' => static function (array $evidence) use ($Liveness): array {
            $share = intdiv((int) $evidence['budget'], 2);
            $parked = (int) $evidence['parked'];
            // ! Outcomes by kind, in one order
            $outcomes = $evidence['outcomes'];
            ksort($outcomes);
            $expected = [503 => $evidence['peers'] - $parked, 'parked woke' => $parked];
            ksort($expected);

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is fitted to the 32M memory_limit' => $evidence['limit'] === '32M'
                  && $evidence['budget'] === 16 * 1024 * 1024,
               'every peer connected' => $evidence['connected'] === $evidence['peers'],
               'parked while the Inbound share fit, every other peer 503' => $parked >= 1
                  && $evidence['inbound'] > $evidence['base']
                  && $evidence['inbound'] <= $share
                  && $outcomes === $expected,
               'every parked deferral woke' => $evidence['wake'] === "200 woken {$parked}",
               'every peer released, the Inbound ledger at its base' => $evidence['released'] === [1, 0, $evidence['base']],
            ];
         },
         'R6' => static function (array $evidence) use ($Liveness, $generation, $snapshot, $margin): array {
            [$first, $peers] = $evidence['shape'];
            $charges = $evidence['charges'];
            $key = (string) (array_key_first($charges ?? []) ?? '');
            [$price, $charge] = array_map('intval', explode(':', "{$key}:"));
            // ! Per parked deferral: what it keeps, and what the Inbound
            //   ledger charged it
            $span = $peers - $first;
            $kept = $evidence['parker'] - $evidence['idler'];
            $charged = $evidence['grown'] / $span;

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the idle peers answered and stayed connected' => $evidence['idle'][0] === [$first + 1, $peers + 1]
                  && $evidence['idle'][2] === ['answered' => $peers],
               'every park parked, none refused' => $evidence['park'][0] === [$first, $peers],
               'every capture moved the Inbound ledger by exactly Request::weigh()' => is_array($charges)
                  && count($charges) === 1
                  && $price > 0
                  && $charge === $price
                  && $charges[$key] === $peers,
               'the price carries the snapshot and its parked generation' => $generation > 0
                  && $snapshot > 0
                  && $price > $snapshot + $generation,
               'the Inbound ledger grew by the price per park' => $evidence['grown'] === $span * $price,
               "kept <= charged <= {$margin} x kept per parked deferral" => $kept > 0
                  && $kept <= $charged
                  && $charged <= $margin * $kept,
               'every parked deferral woke and answered; the ledger returned to its base' => $evidence['wake'] === "200 woken {$peers}"
                  && $evidence['park'][3] === ['answered' => $peers]
                  && $evidence['released'] === [1, 0, $evidence['base']],
            ];
         },
         'R7' => static function (array $evidence) use ($Liveness, $read): array {
            $budget = (int) $evidence['budget'];
            [$measured, $beyond] = $evidence['shape'];
            ['kept' => $kept, 'charged' => $charged] = $evidence['peer'];
            [$parked, $holds, $held, $widest, $inbound, $transport] = $evidence['rest'];
            [$base, $carried] = $evidence['base'];
            $outcomes = $evidence['outcomes'];
            // ! Peers the worker does not hold: closed unanswered, or answered
            //   503 (a refused capture installs no hold, so each of its
            //   pipelined requests is refused too)
            $past = $evidence['connected'] - ($outcomes['parked'] ?? 0);
            $kinds = array_diff(array_keys($outcomes), ['parked', 'closed', 'refused', 'refused, closed']);
            // ! What the Transport ledger charges one held carry — every R7
            //   carry is exactly one transport read
            $carry = TCP_Server_CLI\Buffers::weigh($read);

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is fitted to the 64M memory_limit' => $evidence['limit'] === '64M'
                  && $budget === 32 * 1024 * 1024,
               'every measured peer parked and holds its read; kept <= charged per peer (its park + its held read: Inbound + Transport)' => $evidence['measured'][1]['parked'] === $measured
                  && $evidence['measured'][1]['holds'] === $measured
                  && $evidence['measured'][1]['held'] === $measured * $read
                  && $kept > 0
                  && $kept <= $charged,
               'every peer connected, sized by the measured charge to pass the budget' => $evidence['peers'] > $measured + $beyond
                  && $evidence['connected'] === $evidence['peers'],
               'every parked deferral holds its pipeline, exactly one transport read each' => $parked >= $measured
                  && $holds === $parked
                  && $widest === $read
                  && $held === $holds * $read,
               'every held carry is charged to the Transport ledger, weigh(READ) each beside its base' => $carry > $read
                  && $transport - $carried === $holds * $carry,
               'the parks and held reads filled the budget, inside their shares' => $inbound > $base
                  && $inbound <= intdiv($budget, 2)
                  && $transport > $carried
                  && $inbound + $transport <= $budget
                  && $budget - ($inbound + $transport) < $charged,
               "every peer past the budget — the one more and the {$beyond} past it — closed unanswered or answered 503, never 500, never 200" => ($outcomes['parked'] ?? 0) === $parked
                  && $past >= $beyond + 1
                  && $kinds === []
                  && array_diff(array_keys($evidence['statuses']), [503]) === [],
               'every peer released, the ledgers at their base' => $evidence['released'] === [1, 0, 0, 0, $base, $carried],
            ];
         },
         'R8' => static function (array $evidence) use ($Liveness, $read): array {
            [$peers, $flood] = $evidence['peers'];
            $budget = (int) $evidence['budget'];
            $half = intdiv($read, 2);
            $control = $evidence['measured'][$half - 1];
            $attack = $evidence['measured'][$half];
            // ! Per peer, the growth of `$field` from one step to another
            $Grow = static fn (array $phase, string $from, string $to, string $field): float => ((int) $phase[$to][$field] - (int) $phase[$from][$field]) / $peers;
            // ! Per held carry: what it keeps, against what the Transport
            //   ledger charged it
            $Carry = static fn (array $phase): bool => $Grow($phase, 'park', 'hold', 'usage') <= $Grow($phase, 'park', 'hold', 'transport');
            // ! Per peer, its park and its held carry: what they keep, against
            //   what the Inbound and Transport ledgers charged them
            $Peer = static fn (array $phase): bool => $Grow($phase, 'idle', 'hold', 'usage')
               <= $Grow($phase, 'idle', 'hold', 'inbound') + $Grow($phase, 'idle', 'hold', 'transport');
            // ! Every peer parked one deferral and holds its write, unanswered
            $Shape = static fn (array $phase, int $bytes): bool => $phase['park']['parked'] === $peers
               && $phase['park']['holds'] === $peers
               && $phase['hold']['parked'] === $peers
               && $phase['hold']['holds'] === $peers
               && $phase['hold']['held'] === $peers * $bytes
               && $phase['answers'] === $peers
               && $phase['closed'] === 0;
            // ! A flood stayed inside the budget; the worker stayed the same
            $Bound = static fn (array $phase): bool => $phase['park']['pid'] === $evidence['before']
               && $phase['hold']['pid'] === $evidence['before']
               && $phase['hold']['holds'] >= 1
               && $phase['hold']['inbound'] <= intdiv($budget, 2)
               && $phase['hold']['inbound'] + $phase['hold']['transport'] <= $budget;
            // ! Every phase returned the ledgers to their base
            $released = array_map(
               static fn (array $phase): array => [
                  $phase['released']['connections'],
                  $phase['released']['parked'],
                  $phase['released']['holds'],
                  $phase['released']['held'],
                  $phase['released']['inbound'],
                  $phase['released']['transport'],
               ],
               [...array_values($evidence['measured']), ...array_values($evidence['floods'])],
            );

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'the worker budget is fitted to the 32M memory_limit, beside the 6 MiB application heap' => $evidence['limit'] === '32M'
                  && $evidence['budget'] === 16 * 1024 * 1024
                  && $evidence['usage'] >= 6 * 1024 * 1024,
               'every measured peer parked one deferral and holds its write, nothing answered' => $Shape($control, $half - 1)
                  && $Shape($attack, $half),
               'premise: a read of half a transport read keeps the whole read, one byte less only its length' => $Grow($attack, 'park', 'hold', 'usage') >= $read
                  && $Grow($control, 'park', 'hold', 'usage') < $read,
               'a 32,767-byte carry is charged its length (the control)' => $Grow($control, 'park', 'hold', 'transport') === (float) TCP_Server_CLI\Buffers::weigh($half - 1),
               'a 32,768-byte carry is charged at least the read it adopted, weigh(READ)' => $Grow($attack, 'park', 'hold', 'transport') >= TCP_Server_CLI\Buffers::weigh($read),
               'kept <= charged per held carry (Transport), both sizes' => $Carry($control) && $Carry($attack),
               'kept <= charged per peer, its park and its held carry (Inbound + Transport), both sizes' => $Peer($control) && $Peer($attack),
               "{$flood} peers x (one park + one write held behind it) — 32,768 bytes, then the 32,767-byte control — stayed inside the budget, the worker alive" => $Bound($evidence['floods'][$half])
                  && $Bound($evidence['floods'][$half - 1]),
               'every phase released its peers, the ledgers at their base' => $released === array_fill(0, 4, $evidence['base']),
            ];
         },
      ];
      $descriptions = [
         'R1' => 'R1: one connection — 50 pipelined long-polls answered one per wake in order, then 6,000 more never park a second deferral nor get refused, no refork',
         'R2' => "R2: {$read} bytes pipelined behind a parked deferral wait in the carry; one more byte closes the connection and the ledgers return to their base",
         'R3' => 'R3: a request pipelined behind a parked deferral — same write or later — is answered after it (production Encoder_)',
         'R4' => 'R4: h2c 40 x 128 parked streams — what fits the Inbound share parks, the rest 503 (never 500), no refork',
         'R5' => 'R5: 960 connections x one parked deferral at memory_limit 32M — what fits parks, the rest 503, no refork',
         'R6' => "R6: a parked deferral is charged its generation — kept <= charged <= {$margin} x kept",
         'R7' => 'R7: connections x (one park + one transport read held) past the budget at memory_limit 64M — each held park charged what it keeps, every held carry charged weigh(READ), the one more and the 32 past it closed or 503, inside the budget, no refork',
         'R8' => 'R8: a 32,768-byte write held behind a park is charged the 64 KiB read it keeps (kept <= charged), and 300 such peers at memory_limit 32M beside a 6 MiB heap leave the worker alive (32,767-byte control)',
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
      // ! The measured figures, reported by every row; R8's per measured peer
      $per = max(1, (int) ($Probe->rows['R8']['peers'][0] ?? 1));
      $figures = (string) json_encode([
         'generation' => $generation,
         'R1' => [
            'sent' => $Probe->rows['R1']['sent'] ?? null,
            'closed' => $Probe->rows['R1']['closed'] ?? null,
            'sampled' => $Probe->rows['R1']['sampled'] ?? null,
            'statuses' => $Probe->rows['R1']['statuses'] ?? null,
            'usage' => $Probe->rows['R1']['usage'] ?? null,
            'peak' => $Probe->rows['R1']['peak'] ?? null,
            'fatality' => $Probe->rows['R1']['fatality'] ?? null,
         ],
         'R2' => [
            'holding' => $Probe->rows['R2']['holding'] ?? null,
            'released' => $Probe->rows['R2']['released'] ?? null,
         ],
         'R3' => [
            'same' => $Probe->rows['R3']['same'] ?? null,
            'later' => $Probe->rows['R3']['later'] ?? null,
         ],
         'R4' => [
            'parked' => $Probe->rows['R4']['parked'] ?? null,
            'refused' => $Probe->rows['R4']['refused'] ?? null,
            'inbound' => $Probe->rows['R4']['inbound'] ?? null,
            'transport' => $Probe->rows['R4']['transport'] ?? null,
            'usage' => $Probe->rows['R4']['usage'] ?? null,
            'peak' => $Probe->rows['R4']['peak'] ?? null,
            'fatality' => $Probe->rows['R4']['fatality'] ?? null,
         ],
         'R5' => [
            'parked' => $Probe->rows['R5']['parked'] ?? null,
            'outcomes' => $Probe->rows['R5']['outcomes'] ?? null,
            'inbound' => $Probe->rows['R5']['inbound'] ?? null,
            'usage' => $Probe->rows['R5']['usage'] ?? null,
            'peak' => $Probe->rows['R5']['peak'] ?? null,
            'fatality' => $Probe->rows['R5']['fatality'] ?? null,
         ],
         'R6' => [
            'charges' => $Probe->rows['R6']['charges'] ?? null,
            'idler' => $Probe->rows['R6']['idler'] ?? null,
            'parker' => $Probe->rows['R6']['parker'] ?? null,
            'grown' => $Probe->rows['R6']['grown'] ?? null,
         ],
         'R7' => [
            'peers' => $Probe->rows['R7']['peers'] ?? null,
            'base' => $Probe->rows['R7']['base'] ?? null,
            'peer' => $Probe->rows['R7']['peer'] ?? null,
            'rest' => $Probe->rows['R7']['rest'] ?? null,
            'statuses' => $Probe->rows['R7']['statuses'] ?? null,
            'outcomes' => $Probe->rows['R7']['outcomes'] ?? null,
            'usage' => $Probe->rows['R7']['usage'] ?? null,
            'peak' => $Probe->rows['R7']['peak'] ?? null,
            'fatality' => $Probe->rows['R7']['fatality'] ?? null,
         ],
         'R8' => [
            'usage' => $Probe->rows['R8']['usage'] ?? null,
            // ! Per peer: what its carry keeps and is charged, and what its
            //   park and carry keep and are charged together
            'measured' => array_map(
               static fn (array $phase): array => [
                  'carry kept' => intdiv(($phase['hold']['usage'] ?? 0) - ($phase['park']['usage'] ?? 0), $per),
                  'carry charged' => intdiv(($phase['hold']['transport'] ?? 0) - ($phase['park']['transport'] ?? 0), $per),
                  'peer kept' => intdiv(($phase['hold']['usage'] ?? 0) - ($phase['idle']['usage'] ?? 0), $per),
                  'peer charged' => intdiv(
                     ($phase['hold']['inbound'] ?? 0) - ($phase['idle']['inbound'] ?? 0)
                        + ($phase['hold']['transport'] ?? 0) - ($phase['idle']['transport'] ?? 0),
                     $per,
                  ),
               ],
               $Probe->rows['R8']['measured'] ?? [],
            ),
            'floods' => array_map(
               static fn (array $phase): array => [
                  'pid' => $phase['hold']['pid'] ?? null,
                  'parked' => $phase['park']['parked'] ?? null,
                  'holds' => $phase['hold']['holds'] ?? null,
                  'inbound' => $phase['hold']['inbound'] ?? null,
                  'transport' => $phase['hold']['transport'] ?? null,
                  'usage' => $phase['hold']['usage'] ?? null,
                  'peak' => $phase['peak'] ?? null,
                  'closed' => $phase['closed'] ?? null,
               ],
               $Probe->rows['R8']['floods'] ?? [],
            ),
            'fatality' => $Probe->rows['R8']['fatality'] ?? null,
         ],
      ]);

      // @ The native harness reached the suite worker once per row
      yield new Assertion(
         description: 'Harness: every row answered on the suite worker',
         fallback: "Harness responses: {$summary} | figures: {$figures}",
      )
         ->expect(
            array_map(static fn (string $response): bool => str_contains($response, 'HHSC6-PIPELINE-HARNESS'), $responses),
            Op::Identical,
            array_fill(0, 8, true),
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
