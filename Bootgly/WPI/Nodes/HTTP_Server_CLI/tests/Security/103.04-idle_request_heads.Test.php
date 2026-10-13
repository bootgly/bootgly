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
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC H-HSC-5 (live) — an idle HTTP/1 keep-alive connection keeps
 * no parsed head of its last request.
 *
 * Before the fix, the connection-owned Request kept its last head — the raw
 * header block, the field map, the parsed cookies, the URI and its URL/query
 * memos — outside every memory ledger until the peer's next request, and a
 * head could carry any number of field lines. Now `Request::shed()` drops the
 * head once the response no longer reads it (after the route cache stored or
 * skipped it), more than `Request::$maxHeaderFields` (100) field lines answer
 * `431`, and heads past `Decoder_::MEMOIZED` lines are memoized nowhere.
 *
 * Each row boots a REAL HTTP_Server_CLI (one worker, production `Encoder_`,
 * default configs) in an isolated child process (`setsid`, hard `timeout`, a
 * scrubbed environment, its own loopback port in 19600-19699,
 * `-d memory_limit=128M -d opcache.jit=0`) and talks to it with plain sockets:
 *
 * - I4 (legit): a `cache: ['TTL' => 600]` route counting its handler runs —
 *   a plain GET twice runs once (the second is a cache hit); the same GET with
 *   `Authorization: Bearer x` or `Cookie: s=1` twice runs twice, and a plain
 *   GET of that URI afterwards runs again and echoes no credential (never
 *   stored). On fresh connections and on one keep-alive connection. This row
 *   pins why `shed()` never runs inside `scrub()`: the cache store reads the
 *   head after `scrub()`.
 * - I5 (legit): one keep-alive connection sends two requests whose X-Echo,
 *   Cookie and query differ — each answer echoes its own; then one small
 *   memoizable head (decode-template path, `shed()` gated off) three times, an
 *   own head, and the small head again — every echo correct.
 * - I1: 2,336 field lines (Host plus 2,335 `ab:xy`, a 16,381-byte head) and
 *   101 lines answer `431 Request Header Fields Too Large` and close; a
 *   100-line head padded to 16,380 bytes answers 200.
 * - I2: 300 keep-alive peers each send one GET of exactly 100 field lines and
 *   16,380 bytes (`Request\Frame` answers `413` past 16,384), read their 200
 *   and stay idle. `/m` reports `memory_get_usage()` (after
 *   `gc_collect_cycles()`) before the first peer and once all 300 are idle.
 *   Measured per idle peer (PHP 8.5, 2026-10-12): HEAD b98692a0 56,018 B,
 *   the fix 4,258 B (the connection itself); threshold 16,384 B.
 * - I3: the same for 100 peers whose 16,376-byte head is Host plus one
 *   Cookie line of 1,938 short pairs, to a route that reads
 *   `$Request->cookies` (HEAD 181,944 B, the fix 4,344 B; threshold
 *   32,768 B), then 100 peers whose target carries 1,000 query vars to a
 *   route that reads `$Request->queries` (HEAD 93,275 B, the fix 3,877 B;
 *   threshold 16,384 B).
 * - I6: the replay exit. The production `Encoder_` sheds on two synchronous
 *   exits — the generic one (`return $buffer`, which I2 covers) and the
 *   route-cache admission one (`return self::$wire`). A `cache: ['TTL' =>
 *   600]` route is filled by one plain GET and replayed by a second; then 200
 *   idle peers each send one 100-line, 16,380-byte head of non-credential
 *   `X-Pad-*` fields that replays the same entry (the handler-run counter
 *   stays at 1). Measured per idle peer: HEAD 55,962 B, the fix 4,234 B;
 *   threshold 16,384 B.
 * - I7: a HEAD of an event stream. Mounting SSE pins the live Request
 *   (`Request::pin()`: the stream's callbacks read it while it runs), but a
 *   HEAD answer ends at its head and never streams, so nothing reads that
 *   head again. One HEAD of a route that opens an event stream is answered
 *   `200` at its head (`text/event-stream`, no body, kept alive); then 200
 *   idle keep-alive peers each send a bare HEAD (Host only) — the control —
 *   and 200 more each one HEAD of 100 field lines and 16,380 bytes. `/m`
 *   also reports the head bytes the idle peers' live Requests hold (must be
 *   0). A padded peer must cost no more than a control peer plus 4,096 B.
 *   Measured per idle peer (PHP 8.5, 2026-10-12): control 4,397 B, padded
 *   3,999 B (0 head bytes held); with `Response::attach()` pinning HEAD too
 *   (the R4 tree) control 4,517 B, padded 55,759 B (3,272,000 head bytes
 *   held by the padded peers).
 *
 * Every row records the worker PID before/after, the `/m` metrics route and
 * the server log; a reforked worker or an `Allowed memory size` fatal fails
 * it. The child gets only PATH and HOME — the runner's variables (its
 * agent-output flag makes Bootgly's bootstrap call `posix_setsid()`) would
 * move the server out of the group the `timeout` bound and the cleanup kill.
 * The process groups the child tree really runs in are read from `/proc` once
 * it answers and killed on stop; a row whose server left its group, or left a
 * process or a listener behind, fails. Rows are judged I4, I5 first, so a
 * failing attack row still reports every row's verdict in its message, and
 * every message carries the six measured growth figures.
 *
 * Local-only: the Security suite never runs in CI.
 */


// # Server child
// ! The servers never run in the Security suite's shared worker: the spec
//   re-executes this file as an isolated, session-led PHP process that boots
//   one real HTTP_Server_CLI (one worker) on its own loopback port.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--hhsc5-idle-server'
) {
   // !
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $base = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][4] ?? 0);

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

   // @ One worker, default configs
   $Server = new HTTP_Server_CLI(Modes::Foreground);
   $Server->configure(
      new HTTP_Server_CLI\Configs(
         host: '127.0.0.1',
         port: $port,
         workers: 1,
      ),
   );

   $Server->on(Events::RequestReceived, static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      // # Metrics: the worker PID, its live connections and its memory
      yield $Router->route('/m', static function (
         Request $Request,
         Response $Response,
      ): Response {
         // ! Garbage cycles of departed peers never count as held memory
         gc_collect_cycles();
         // ! Head bytes the other connections' live Requests still hold
         $live = 0;
         foreach (Connections::$Connections as $Connection) {
            $Decoded = $Connection->decoded;
            if ($Decoded instanceof Request && $Decoded !== $Request) {
               $live += strlen($Decoded->Header->raw ?? '');
            }
         }

         return $Response(body: (string) json_encode([
            'pid' => getmypid(),
            'group' => posix_getpgid(0),
            'connections' => count(Connections::$Connections),
            'usage' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'live' => $live,
            'runs' => $GLOBALS['hhsc5_runs'] ?? 0,
            'fills' => $GLOBALS['hhsc5_fills'] ?? 0,
            'entries' => count(Cache::$entries),
         ]));
      }, GET);

      // # Idle heads: reads nothing — the parsed head is the whole cost
      yield $Router->route('/i', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'idle');
      }, GET);

      // # Parsed cookies
      yield $Router->route('/k', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $count = count($Request->cookies[0] ?? []);

         return $Response(body: "cookies {$count}");
      }, GET);

      // # Parsed queries
      yield $Router->route('/q', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $count = count($Request->queries);

         return $Response(body: "queries {$count}");
      }, GET);

      // # Route cache: every handler run is counted and named in the body
      yield $Router->route('/r', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $GLOBALS['hhsc5_runs'] = ($GLOBALS['hhsc5_runs'] ?? 0) + 1;
         $run = $GLOBALS['hhsc5_runs'];
         $authorization = $Request->Header->get('Authorization') ?? '-';
         $cookie = $Request->Header->get('Cookie') ?? '-';

         return $Response(body: "run={$run};authorization={$authorization};cookie={$cookie}");
      }, GET, cache: ['TTL' => 600]);

      // # Route cache replay: the handler fills the entry, replays answer
      yield $Router->route('/c', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $GLOBALS['hhsc5_fills'] = ($GLOBALS['hhsc5_fills'] ?? 0) + 1;
         $fills = $GLOBALS['hhsc5_fills'];

         return $Response(body: "cached {$fills}");
      }, GET, cache: ['TTL' => 600]);

      // # Echo: the head this request carried
      yield $Router->route('/e', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: (string) json_encode([
            'echo' => $Request->Header->get('X-Echo'),
            'agent' => $Request->Header->get('User-Agent'),
            'who' => $Request->Cookies->get('who'),
            'URI' => $Request->URI,
            'q' => $Request->queries['q'] ?? null,
         ], JSON_UNESCAPED_SLASHES));
      }, GET);

      // # Event stream: mounting SSE pins the live Request; a HEAD answer
      //   ends at its head and never streams
      yield $Router->route('/s', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Response->SSE->open();

         return $Response;
      }, [GET, HEAD]);
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
// ! Per idle peer growth (bytes) each memory row must stay under — between
//   the HEAD and the fixed measurements written in the docblock, with at
//   least 3x margin on both sides. An idle peer must cost less than the
//   ~16 KiB head it sent: dropping only part of the head (raw block, field
//   map, cookie jar, URI or query memos) still crosses these.
$thresholds = [
   'heads' => 16_384,
   'cookies' => 32_768,
   'queries' => 16_384,
   'replays' => 16_384,
   'events' => 16_384,
];
// ! What a padded HEAD of the event stream may cost an idle peer over the
//   bare control (bytes): a quarter of the ~16 KiB head it sent — between the
//   shed and the pinned measurements written in the docblock
$margin = 4_096;

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
 * to the server's close (a rejection carries no Content-Length). A `$bare`
 * answer (to a HEAD) ends at its head.
 *
 * @return array{status:string,line:string,head:string,body:string,closed:bool}
 */
$Exchange = static function (mixed $Socket, string $request, float $timeout = 3.0, bool $bare = false): array {
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
         if ($separator !== false && $bare) {
            $expected = $separator + 4;
         }
         else if (
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
$Fetch = static function (int $port, string $request, float $timeout = 3.0, bool $bare = false) use ($Exchange): array {
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, $timeout);
   if ($Socket === false) {
      return ['status' => '', 'line' => '', 'head' => '', 'body' => '', 'closed' => true];
   }

   try {
      return $Exchange($Socket, $request, $timeout, $bare);
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
         '--hhsc5-idle-server',
         BOOTGLY_ROOT_BASE,
         $base,
         (string) $port,
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
      $Probe->base = "{$temporary}/bootgly-hhsc5-idle-{$token}";
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
      if ($row === 'I7') {
         $Clean($Probe->base);
      }
   }

   // :
   return "GET /hhsc5-idle-harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
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
 * Hold one keep-alive peer per request in `$requests`: each sends its
 * request, reads its answer (`$bare`: a HEAD answer, its head only) and
 * stays connected, idle. `/m` is read once the `$warmups` (same shapes, on
 * connections that leave at once) settled, and again once every peer is
 * idle; then every peer leaves.
 *
 * @param array<int,string> $warmups
 * @param array<int,string> $requests
 *
 * @return array<string,mixed>
 */
$Idle = static function (int $port, array $warmups, array $requests, string $body, bool $bare = false) use ($Fetch, $Exchange, $Measure, $Settle): array {
   // ! First-use allocations land before the baseline
   foreach ($warmups as $request) {
      $Fetch($port, $request, 3.0, $bare);
   }
   $before = $Settle($port, ['connections'], [1]) ?? [];

   $Sockets = [];
   $statuses = [];
   $mismatches = 0;
   $after = [];
   try {
      // @@ Every peer: connect, one exchange, stay
      foreach ($requests as $index => $request) {
         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-5 idle peer {$index} could not connect: {$message}");
         }
         $Sockets[] = $Socket;

         $exchange = $Exchange($Socket, $request, 3.0, $bare);
         $statuses[] = $exchange['status'];
         if ($exchange['body'] !== $body || $exchange['closed']) {
            $mismatches++;
         }
      }

      // @ Every peer idle
      $after = $Measure($port) ?? [];
   }
   finally {
      foreach ($Sockets as $Socket) {
         fclose($Socket);
      }
   }
   $released = $Settle($port, ['connections'], [1]) ?? [];

   // :
   $peers = count($requests);
   $grown = (int) ($after['usage'] ?? 0) - (int) ($before['usage'] ?? 0);

   return [
      'peers' => $peers,
      'statuses' => array_count_values($statuses),
      'mismatches' => $mismatches,
      'idle' => (int) ($after['connections'] ?? -1) - (int) ($before['connections'] ?? 0),
      'live' => $after['live'] ?? null,
      'usage' => [$before['usage'] ?? null, $after['usage'] ?? null],
      'growth' => $peers > 0 ? intdiv($grown, $peers) : -1,
      'released' => $released['connections'] ?? null,
      'samples' => [
         [(string) ($before['status'] ?? 'none'), (int) ($before['pid'] ?? 0)],
         [(string) ($after['status'] ?? 'none'), (int) ($after['pid'] ?? 0)],
         [(string) ($released['status'] ?? 'none'), (int) ($released['pid'] ?? 0)],
      ],
   ];
};


return new Test(
   description: 'H-HSC-5: idle keep-alive connections keep no parsed request head',
   Separator: new Separator(line: true),

   requests: [
      // # Legit (must pass before and after the fix)
      static fn (): string => $Run('I4', static function (array $server) use ($Fetch, $Exchange, $Measure): array {
         // !
         $port = (int) $server['port'];
         $Plain = static fn (string $leg): string => "GET /r?leg={$leg} HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $Bearer = static fn (string $leg): string => "GET /r?leg={$leg} HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer x\r\n\r\n";
         $Cookie = static fn (string $leg): string => "GET /r?leg={$leg} HTTP/1.1\r\nHost: localhost\r\nCookie: s=1\r\n\r\n";
         // ! Plain twice, a credential twice then plain on its URI, per credential
         $Legs = static fn (string $mode): array => [
            $Plain("{$mode}-plain"),
            $Plain("{$mode}-plain"),
            $Bearer("{$mode}-bearer"),
            $Bearer("{$mode}-bearer"),
            $Plain("{$mode}-bearer"),
            $Cookie("{$mode}-cookie"),
            $Cookie("{$mode}-cookie"),
            $Plain("{$mode}-cookie"),
         ];

         // @@ Fresh connections
         $fresh = [];
         foreach ($Legs('fresh') as $request) {
            $exchange = $Fetch($port, $request);
            $fresh[] = "{$exchange['status']} {$exchange['body']}";
         }

         // @@ One keep-alive connection
         $kept = [];
         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-5 I4 could not connect: {$message}");
         }
         try {
            foreach ($Legs('kept') as $request) {
               $exchange = $Exchange($Socket, $request);
               $kept[] = "{$exchange['status']} {$exchange['body']}";
            }
         }
         finally {
            fclose($Socket);
         }

         $metrics = $Measure($port) ?? [];

         // :
         return [
            'fresh' => $fresh,
            'kept' => $kept,
            'runs' => $metrics['runs'] ?? null,
            'entries' => $metrics['entries'] ?? null,
         ];
      }),
      static fn (): string => $Run('I5', static function (array $server) use ($Fetch, $Exchange): array {
         // !
         $port = (int) $server['port'];
         $Own = static fn (string $value): string => "GET /e?q={$value} HTTP/1.1\r\nHost: localhost\r\nX-Echo: {$value}\r\nCookie: who={$value}\r\n\r\n";
         // ! Memoizable: 3 standard field lines, no query, no cookie
         $small = "GET /e HTTP/1.1\r\nHost: localhost\r\nUser-Agent: hhsc5-template\r\nAccept: */*\r\n\r\n";

         // @@ One keep-alive connection: own heads, then the template path
         $echoes = [];
         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-5 I5 could not connect: {$message}");
         }
         try {
            foreach ([$Own('one'), $Own('two'), $small, $small, $small, $Own('four'), $small] as $request) {
               $exchange = $Exchange($Socket, $request);
               $echoes[] = "{$exchange['status']} {$exchange['body']}";
            }
         }
         finally {
            fclose($Socket);
         }

         // @ The shared template from another connection
         $shared = $Fetch($port, $small);

         // :
         return [
            'echoes' => $echoes,
            'shared' => "{$shared['status']} {$shared['body']}",
         ];
      }),
      // # Attacks
      static fn (): string => $Run('I1', static function (array $server) use ($Fetch, $Pad, $Count, $limit, $cap): array {
         // !
         $port = (int) $server['port'];
         // ! Host plus 2,335 `ab:xy`: the most field lines a head fits
         $lines = str_repeat("ab:xy\r\n", 2_335);
         $flood = "GET /i HTTP/1.1\r\nHost: localhost\r\n{$lines}\r\n";
         $above = $Pad('/i', $cap + 1, 4_096, 'above');
         $at = $Pad('/i', $cap, $limit - 4, 'at');

         // @
         $Answer = static fn (array $exchange): array => [
            'line' => $exchange['line'],
            'body' => $exchange['body'],
            'closed' => $exchange['closed'],
         ];

         // :
         return [
            'shapes' => [
               'flood' => [$Count($flood), strlen($flood)],
               'above' => [$Count($above), strlen($above)],
               'at' => [$Count($at), strlen($at)],
            ],
            'flood' => $Answer($Fetch($port, $flood, 5.0)),
            'above' => $Answer($Fetch($port, $above)),
            'at' => $Answer($Fetch($port, $at)),
         ];
      }),
      static fn (): string => $Run('I2', static function (array $server) use ($Idle, $Pad, $Count, $limit, $cap): array {
         // ! 300 distinct heads of exactly 100 field lines, just under the limit
         $bytes = $limit - 4;
         $requests = [];
         for ($index = 0; $index < 300; $index++) {
            $requests[] = $Pad('/i', $cap, $bytes, "peer{$index}");
         }
         $warmups = [$Pad('/i', $cap, $bytes, 'warm0'), $Pad('/i', $cap, $bytes, 'warm1')];

         // :
         return [
            'shape' => [$Count($requests[0]), strlen($requests[0])],
         ] + $Idle((int) $server['port'], $warmups, $requests, 'idle');
      }),
      static fn (): string => $Run('I3', static function (array $server) use ($Idle, $Count, $limit): array {
         // !
         $port = (int) $server['port'];

         // # Cookies: one Cookie line of short pairs, just under the limit
         $prefix = "GET /k HTTP/1.1\r\nHost: localhost\r\nCookie: ";
         $length = strlen($prefix) + 4;
         $pairs = [];
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
         $cookie = "{$prefix}{$jar}\r\n\r\n";
         $jars = count($pairs);
         $cookies = $Idle($port, [$cookie, $cookie], array_fill(0, 100, $cookie), "cookies {$jars}");

         // # Queries: 1,000 vars (the default max_input_vars)
         $vars = array_map(static fn (int $index): string => "v{$index}=1", range(0, 999));
         $string = implode('&', $vars);
         $query = "GET /q?{$string} HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $queries = $Idle($port, [$query, $query], array_fill(0, 100, $query), 'queries 1000');

         // :
         return [
            'shapes' => [
               'cookie' => [$Count($cookie), strlen($cookie), $jars],
               'query' => [$Count($query), count($vars)],
            ],
            'cookies' => $cookies,
            'queries' => $queries,
            'samples' => [...$cookies['samples'], ...$queries['samples']],
         ];
      }),
      static fn (): string => $Run('I6', static function (array $server) use ($Fetch, $Measure, $Idle, $Pad, $Count, $limit, $cap): array {
         // !
         $port = (int) $server['port'];
         $plain = "GET /c HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // @ Fill the entry, then prove a plain repeat replays it
         $fill = $Fetch($port, $plain);
         $hit = $Fetch($port, $plain);
         $warm = $Measure($port) ?? [];

         // ! 200 distinct heads of 100 non-credential `X-Pad-*` lines, just
         //   under the limit: the same cache key, so each one replays
         $bytes = $limit - 4;
         $requests = [];
         for ($index = 0; $index < 200; $index++) {
            $requests[] = $Pad('/c', $cap, $bytes, "peer{$index}");
         }
         $warmups = [$Pad('/c', $cap, $bytes, 'warm0'), $Pad('/c', $cap, $bytes, 'warm1')];
         $idle = $Idle($port, $warmups, $requests, 'cached 1');
         $replayed = $Measure($port) ?? [];

         // :
         return [
            'shape' => [$Count($requests[0]), strlen($requests[0])],
            'warm' => [
               "{$fill['status']} {$fill['body']}",
               "{$hit['status']} {$hit['body']}",
               $warm['fills'] ?? null,
            ],
            'fills' => $replayed['fills'] ?? null,
         ] + $idle;
      }),
      static fn (): string => $Run('I7', static function (array $server) use ($Fetch, $Idle, $Pad, $Count, $limit, $cap): array {
         // !
         $port = (int) $server['port'];
         $bytes = $limit - 4;
         // ! A HEAD of the event stream: a padded head one byte short, its
         //   GET turned HEAD — and the bare control, Host only
         $Head = static fn (string $tag): string => substr_replace($Pad('/s', $cap, $bytes - 1, $tag), 'HEAD', 0, 3);
         $Bare = static fn (string $tag): string => "HEAD /s?{$tag} HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // @ One HEAD: the stream's head alone
         $probe = $Fetch($port, $Bare('probe'), 3.0, true);

         // @ 200 bare peers, then 200 padded peers, each idle once answered
         $controls = [];
         $heads = [];
         for ($index = 0; $index < 200; $index++) {
            $controls[] = $Bare("peer{$index}");
            $heads[] = $Head("peer{$index}");
         }
         $control = $Idle($port, [$Bare('warm0'), $Bare('warm1')], $controls, '', true);
         $padded = $Idle($port, [$Head('warm0'), $Head('warm1')], $heads, '', true);

         // :
         return [
            'shape' => [$Count($heads[0]), strlen($heads[0]), strlen($controls[0])],
            'answer' => [
               $probe['line'],
               stripos($probe['head'], "\r\nContent-Type: text/event-stream\r\n") !== false,
               stripos($probe['head'], 'Transfer-Encoding') === false && stripos($probe['head'], 'Content-Length') === false,
               $probe['body'],
               $probe['closed'],
            ],
            'control' => $control,
            'padded' => $padded,
            'samples' => [...$control['samples'], ...$padded['samples']],
         ];
      }),
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/hhsc5-idle-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'HHSC5-HARNESS');
      }, GET);
   },

   test: static function (array $responses) use ($Probe, $Clean, $limit, $cap, $thresholds, $margin): Generator {
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
      $Liveness = static fn (array $evidence, array $samples): bool => $samples !== []
         && array_filter(
            $samples,
            static fn (array $sample): bool => $sample !== ['200', $evidence['before']],
         ) === [];
      // # I2/I3: every peer answered and stayed idle, under the threshold
      $Hold = static fn (array $evidence, array $idle, int $peers, int $threshold): array => [
         '/m answered 200 throughout' => $Liveness($evidence, $idle['samples']),
         "{$peers} peers answered 200 with the route body" => $idle['statuses'] === ['200' => $peers]
            && $idle['mismatches'] === 0,
         'every peer was idle and connected when measured' => $idle['idle'] === $peers,
         "growth per idle peer under {$threshold} B" => $idle['growth'] >= 0 && $idle['growth'] < $threshold,
         'every peer released once it left' => $idle['released'] === 1,
      ];
      // # I2/I3: one check list per measured group
      $Prefix = static function (string $prefix, array $checks): array {
         $prefixed = [];
         foreach ($checks as $check => $passed) {
            $prefixed["{$prefix}: {$check}"] = $passed;
         }

         return $prefixed;
      };
      // # I4: the answers one leg sequence must produce from run `$run` on —
      //   a plain repeat replays run 1, a credentialed one runs again, and a
      //   plain GET of a credentialed URI runs and echoes no credential
      $Legs = static function (int $run): array {
         [$plain, $bearer, $again, $follow, $cookie, $repeat, $last] = range($run + 1, $run + 7);

         return [
            "200 run={$plain};authorization=-;cookie=-",
            "200 run={$plain};authorization=-;cookie=-",
            "200 run={$bearer};authorization=Bearer x;cookie=-",
            "200 run={$again};authorization=Bearer x;cookie=-",
            "200 run={$follow};authorization=-;cookie=-",
            "200 run={$cookie};authorization=-;cookie=s=1",
            "200 run={$repeat};authorization=-;cookie=s=1",
            "200 run={$last};authorization=-;cookie=-",
         ];
      };
      // # I5: one echo answer
      $Echo = static function (null|string $echo, null|string $agent, string $who, string $URI, null|string $q): string {
         $JSON = json_encode(
            ['echo' => $echo, 'agent' => $agent, 'who' => $who, 'URI' => $URI, 'q' => $q],
            JSON_UNESCAPED_SLASHES,
         );

         return "200 {$JSON}";
      };

      // # Row checks
      $Checks = [
         'I4' => static fn (array $evidence): array => [
            'fresh: plain hit once, credentialed never stored' => $evidence['fresh'] === $Legs(0),
            'keep-alive: plain hit once, credentialed never stored' => $evidence['kept'] === $Legs(7),
            'the handler ran exactly 14 times' => $evidence['runs'] === 14,
            // ? One entry per plain URI: each plain leg and each plain GET
            //   after a credentialed pair — never one keyed on a dropped head
            'the route cache holds exactly the 6 plain entries' => $evidence['entries'] === 6,
         ],
         'I5' => static function (array $evidence) use ($Echo): array {
            $template = $Echo(null, 'hhsc5-template', '', '/e', null);

            return [
               'each own head echoes itself' => [
                  $evidence['echoes'][0] ?? null,
                  $evidence['echoes'][1] ?? null,
                  $evidence['echoes'][5] ?? null,
               ] === [
                  $Echo('one', null, 'one', '/e?q=one', 'one'),
                  $Echo('two', null, 'two', '/e?q=two', 'two'),
                  $Echo('four', null, 'four', '/e?q=four', 'four'),
               ],
               'every template repeat echoes the template' => [
                  $evidence['echoes'][2] ?? null,
                  $evidence['echoes'][3] ?? null,
                  $evidence['echoes'][4] ?? null,
                  $evidence['echoes'][6] ?? null,
                  $evidence['shared'],
               ] === array_fill(0, 5, $template),
               'seven answers on one connection' => count($evidence['echoes']) === 7,
            ];
         },
         'I1' => static fn (array $evidence): array => [
            'shapes: 2,336 / 101 / 100 field lines within the head byte limit' => $evidence['shapes'] === [
               'flood' => [2_336, 16_381],
               'above' => [$cap + 1, 4_096],
               'at' => [$cap, $limit - 4],
            ],
            '2,336 field lines answer 431 and close' => $evidence['flood'] === [
               'line' => 'HTTP/1.1 431 Request Header Fields Too Large',
               'body' => '',
               'closed' => true,
            ],
            '101 field lines answer 431 and close' => $evidence['above'] === [
               'line' => 'HTTP/1.1 431 Request Header Fields Too Large',
               'body' => '',
               'closed' => true,
            ],
            '100 field lines answer 200' => $evidence['at'] === [
               'line' => 'HTTP/1.1 200 OK',
               'body' => 'idle',
               'closed' => false,
            ],
         ],
         'I2' => static fn (array $evidence): array => [
            'shape: 100 field lines just under the head byte limit' => $evidence['shape'] === [$cap, $limit - 4],
         ] + $Hold($evidence, $evidence, 300, $thresholds['heads']),
         'I3' => static fn (array $evidence): array => [
            'shapes: one Cookie line of 1,500+ pairs just under the limit, 1,000 query vars' => $evidence['shapes']['cookie'][0] === 2
               && $evidence['shapes']['cookie'][1] > $limit - 16
               && $evidence['shapes']['cookie'][1] <= $limit - 4
               && $evidence['shapes']['cookie'][2] > 1_500
               && $evidence['shapes']['query'] === [1, 1_000],
         ]
            + $Prefix('cookies', $Hold($evidence, $evidence['cookies'], 100, $thresholds['cookies']))
            + $Prefix('queries', $Hold($evidence, $evidence['queries'], 100, $thresholds['queries'])),
         'I6' => static fn (array $evidence): array => [
            'shape: 100 field lines just under the head byte limit' => $evidence['shape'] === [$cap, $limit - 4],
            'the entry filled once and a plain repeat replayed it' => $evidence['warm'] === ['200 cached 1', '200 cached 1', 1],
            'every padded peer was a replay: the handler never ran again' => $evidence['fills'] === 1,
         ] + $Hold($evidence, $evidence, 200, $thresholds['replays']),
         'I7' => static fn (array $evidence): array => [
            'shapes: a HEAD of 100 field lines just under the head byte limit, a bare HEAD control' => $evidence['shape'][0] === $cap
               && $evidence['shape'][1] === $limit - 4
               && $evidence['shape'][2] < 64,
            'a HEAD of the event stream answers 200 at its head: text/event-stream, unframed, no body, kept alive' => $evidence['answer'] === ['HTTP/1.1 200 OK', true, true, '', false],
            'no idle peer\'s live Request keeps its head' => $evidence['control']['live'] === 0
               && $evidence['padded']['live'] === 0,
            "a padded peer costs no more than a control peer plus {$margin} B" => $evidence['padded']['growth'] - $evidence['control']['growth'] < $margin,
         ]
            + $Prefix('control', $Hold($evidence, $evidence['control'], 200, $thresholds['events']))
            + $Prefix('padded', $Hold($evidence, $evidence['padded'], 200, $thresholds['events'])),
      ];
      $descriptions = [
         'I4' => 'I4 legit: the route cache stores a plain GET once and never a credentialed one (fresh and keep-alive)',
         'I5' => 'I5 legit: every keep-alive request echoes its own head, template repeats included',
         'I1' => 'I1: more than 100 field lines answer 431 and close; 100 lines answer 200',
         'I2' => "I2: 300 idle keep-alive peers after a 100-line ~16 KiB head hold under {$thresholds['heads']} B each, no refork",
         'I3' => "I3: idle peers keep no parsed cookies (under {$thresholds['cookies']} B) or queries (under {$thresholds['queries']} B), no refork",
         'I6' => "I6: 200 idle keep-alive peers answered by route-cache replay hold under {$thresholds['replays']} B each, no refork",
         'I7' => "I7: 200 idle keep-alive peers after a HEAD of an event stream keep no head — within {$margin} B of a bare HEAD each, no refork",
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
      // ! The measured growth per idle peer, reported by every row
      $figures = (string) json_encode([
         'heads' => $Probe->rows['I2']['growth'] ?? null,
         'cookies' => $Probe->rows['I3']['cookies']['growth'] ?? null,
         'queries' => $Probe->rows['I3']['queries']['growth'] ?? null,
         'replays' => $Probe->rows['I6']['growth'] ?? null,
         'events' => [
            $Probe->rows['I7']['control']['growth'] ?? null,
            $Probe->rows['I7']['padded']['growth'] ?? null,
         ],
         'live' => [
            $Probe->rows['I7']['control']['live'] ?? null,
            $Probe->rows['I7']['padded']['live'] ?? null,
         ],
      ]);

      // @ The native harness reached the suite worker once per row
      yield new Assertion(
         description: 'Harness: every row answered on the suite worker',
         fallback: "Harness responses: {$summary} | growth per idle peer (B): {$figures}",
      )
         ->expect(
            array_map(static fn (string $response): bool => str_contains($response, 'HHSC5-HARNESS'), $responses),
            Op::Identical,
            array_fill(0, 7, true),
         )
         ->assert();

      // @@ One assertion per row, legit rows first
      foreach ($verdicts as $row => $verdict) {
         $evidence = (string) json_encode($verdict['evidence'], JSON_UNESCAPED_SLASHES);

         yield new Assertion(
            description: $descriptions[$row],
            fallback: "{$row} evidence: {$evidence} | all rows: {$summary} | growth per idle peer (B): {$figures}",
         )
            ->expect($verdict['failed'], Op::Identical, [])
            ->assert();
      }
   },
);
