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
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Bodies as StreamBodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC H-HSC-3 (live) — no pool alone may exhaust a worker's
 * `memory_limit`: every crash shape stays inside one worker memory budget.
 *
 * Each row boots a REAL HTTP_Server_CLI (one worker, default configs except
 * where the row says otherwise) in an isolated child process (`setsid`, hard
 * `timeout`, a scrubbed environment, its own loopback port,
 * `-d memory_limit=128M`) and attacks it from this process with plain sockets:
 *
 * - S1: 64 HTTP/1 heads declaring 1,100,000 B, each sending 1,048,600 B and
 *   going silent. Each unfinished body is one string past a third of a 2 MiB
 *   chunk, so it is charged a whole chunk: before the budget, 64 MiB of raw
 *   bytes held ~128 MiB and the worker died with `Allowed memory size` and
 *   was reforked. Now each admitted body pays its chunk plus its parsed head
 *   (`Request\Frame::weigh()`), as many as fit L/2 are admitted, the rest
 *   get 503.
 * - S2: the same bodies as h2c streams (7 connections x 9 streams).
 * - S3: 61 distinct ~1 MiB route-cache wires, sent and closed unread.
 * - S4: 58 slow readers (SO_RCVBUF 4096) each leaving a ~1.09 MB unsent
 *   suffix — one 2 MiB chunk apiece, charged ~half of that before; one
 *   suffix fits the per-connection cap (`maxPendingBytes`), so only the
 *   worker budget refuses.
 * - S6: `memory_limit=64M` — the budget is lowered to fit, with a warning.
 * - B: a `maxBodySize` that cannot fit the Inbound share warns at boot.
 * - L1-L3: legitimate loads of the same pools — never refused.
 *
 * Each row records the worker PID before/after, the `/m` metrics route and the
 * server log; a reforked worker or an `Allowed memory size` fatal fails it.
 * The child gets only PATH and HOME — the runner's variables (its agent-output
 * flag makes Bootgly's bootstrap call `posix_setsid()`) would move the server
 * out of the group the `timeout` bound and the cleanup kill. The process
 * groups the child tree really runs in are read from `/proc` once it answers
 * and killed on stop; a row whose server left its group, or left a process or
 * a listener behind, fails. Rows are judged in order L1-L3 first, so a failing
 * attack row still reports every row's verdict in its message.
 *
 * Local-only: the Security suite never runs in CI.
 */


// # Server child
// ! The attacked servers never run in the Security suite's shared worker: the
//   spec re-executes this file as an isolated, session-led PHP process that
//   boots one real HTTP_Server_CLI (one worker) on its own loopback port.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--hhsc3-crash-server'
) {
   // !
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $base = rtrim((string) ($_SERVER['argv'][3] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][4] ?? 0);
   $row = (string) ($_SERVER['argv'][5] ?? '');
   $large = (int) ($_SERVER['argv'][6] ?? 0);

   // ?
   if (
      $root === ''
      || is_file("{$root}/autoboot.php") === false
      || $base === ''
      || is_dir($base) === false
      || $port < 1024
      || $large < 1
   ) {
      fwrite(STDERR, "H-HSC-3 server child received an invalid root, base, port or size.\n");
      exit(2);
   }

   define('BOOTGLY_STORAGE_BASE', "{$base}/storage");
   define('BOOTGLY_STORAGE_DIR', "{$base}/storage/");
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   // @ One worker, default configs except the row's own knob
   $Server = new HTTP_Server_CLI(Modes::Foreground);
   $Configs = [
      new HTTP_Server_CLI\Configs(
         host: '127.0.0.1',
         port: $port,
         workers: 1,
      ),
   ];
   if ($row === 'B') {
      $Configs[] = new Request\Configs(maxBodySize: 32 * 1024 * 1024);
   }
   $Server->configure(...$Configs);

   $Server->on(Events::RequestReceived, static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($large): Generator {
      // # Metrics: the worker PID and every ledger the rows observe
      yield $Router->route('/m', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Read = static function (string $class, string $name): int {
            try {
               $value = new ReflectionProperty($class, $name)->getValue();
            }
            catch (Throwable) {
               return -1;
            }

            return is_int($value) ? $value : -1;
         };
         // ! The Inbound share's footprint: unfinished bodies and their heads
         try {
            $held = new ReflectionProperty(Buffers::class, 'held')->getValue();
            $inbound = is_array($held) && is_int($held[Shares::Inbound->value] ?? null)
               ? $held[Shares::Inbound->value]
               : -1;
         }
         catch (Throwable) {
            $inbound = -1;
         }

         return $Response(body: (string) json_encode([
            'pid' => getmypid(),
            'group' => posix_getpgid(0),
            'pending' => TCP_Server_CLI::$pendingBytes,
            'budget' => TCP_Server_CLI::$maxWorkerPendingBytes,
            'cap' => TCP_Server_CLI::$maxPendingBytes,
            'inbound' => $inbound,
            'bodies' => $Read(Bodies::class, 'total'),
            'streams' => $Read(StreamBodies::class, 'total'),
            'entries' => count(Cache::$entries),
            'cached' => Cache::$bytes,
            'runs' => $GLOBALS['hhsc3_runs'] ?? 0,
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
         ]));
      }, GET);

      // # Cached route: one ~1 MiB wire per distinct query
      yield $Router->route('/c', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $GLOBALS['hhsc3_runs'] = ($GLOBALS['hhsc3_runs'] ?? 0) + 1;

         return $Response(body: str_pad($Request->URI, 1_048_400, 'c'));
      }, GET, cache: ['TTL' => 600]);

      // # Uncached large response for slow readers
      yield $Router->route('/p', static function (
         Request $Request,
         Response $Response,
      ) use ($large): Response {
         return $Response(body: str_repeat('p', $large));
      }, GET);

      // # Uploads
      yield $Router->route('/u', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $length = strlen($Request->Body->raw ?? '');

         return $Response(body: "ok {$length}");
      }, POST);
   });

   $Server->start();

   exit(0);
}


// # Spec
$Probe = new class {
   // * Data
   /** Root of the per-run temp area (server storage + logs). */
   public string $base = '';
   /** Next port offset inside the 19300-19399 range. */
   public int $cursor = -1;
   /** First-write acceptance of one SO_RCVBUF 4096 loopback peer. */
   public int $accepted = 0;
   /** Body size of `/p`: the calibrated acceptance plus the target suffix. */
   public int $large = 0;
   /** `TCP_Server_CLI::$pendingBytes` of this process before the case. */
   public int $pending = -1;
   /** @var array<string,array<string,mixed>> Evidence by row. */
   public array $rows = [];
};

// ! The unsent suffix every slow reader leaves pending: past the step where
//   one string is charged a whole 2 MiB chunk, while a raw-length ledger
//   charges it ~half of that.
$suffix = 1_090_000;
// ! The unfinished body of S1/S2/S6: one string past the same step.
$body = 1_048_600;
// ! The exact head of every S1/S6 body: its parsed form is charged with it.
$head = "POST /nope HTTP/1.1\r\nHost: localhost\r\nContent-Length: 1100000\r\n\r\n";

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
 * Exchange one request on a fresh loopback connection: write it whole, read
 * the head and exactly Content-Length body bytes (or until EOF/deadline).
 *
 * @return array{status:string,head:string,body:string}
 */
$Fetch = static function (int $port, string $request, float $timeout = 3.0): array {
   $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, $timeout);
   if ($Socket === false) {
      return ['status' => '', 'head' => '', 'body' => ''];
   }

   $wire = '';
   try {
      stream_set_blocking($Socket, false);
      $deadline = microtime(true) + $timeout;

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

      // @@ Read
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
      'head' => $separator === false ? $wire : substr($wire, 0, $separator + 4),
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
 * Poll `/m` until every `$fields` value equals `$targets` (or, with no
 * targets, until each stays the same across three samples in a row).
 *
 * @param array<int,string> $fields
 * @param null|array<int,int> $targets
 *
 * @return null|array<string,mixed>
 */
$Settle = static function (int $port, array $fields, null|array $targets = null, float $timeout = 6.0) use ($Measure): null|array {
   $deadline = microtime(true) + $timeout;
   $previous = null;
   $streak = 0;
   $metrics = null;

   // @@
   do {
      $metrics = $Measure($port, 2.0);
      if ($metrics !== null) {
         $values = [];
         foreach ($fields as $field) {
            $values[] = $metrics[$field] ?? null;
         }

         // ? At the targets, or unchanged across three samples in a row
         $streak = $values === $previous ? $streak + 1 : 0;
         if ($targets !== null ? $values === $targets : $streak >= 2) {
            return $metrics;
         }
         $previous = $values;
      }
      usleep(150_000);
   }
   while (microtime(true) < $deadline);

   // :
   return $metrics;
};

$Locate = static function () use ($Probe): int {
   // @@ The 19300-19399 range, starting at a random offset
   if ($Probe->cursor < 0) {
      $Probe->cursor = random_int(0, 99);
   }
   for ($attempt = 0; $attempt < 100; $attempt++) {
      $port = 19300 + ($Probe->cursor % 100);
      $Probe->cursor++;

      $Listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $code, $message);
      if ($Listener !== false) {
         fclose($Listener);

         return $port;
      }
   }

   throw new RuntimeException('H-HSC-3 found no free loopback port in 19300-19399.');
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
$Launch = static function (string $row, string $memory) use ($Probe, $Locate, $Measure, $Stop, $Survey): array {
   // !
   $port = $Locate();
   $base = "{$Probe->base}/{$row}";
   if (mkdir("{$base}/storage", 0700, true) === false) {
      throw new RuntimeException("H-HSC-3 could not create the {$row} server storage.");
   }
   $log = "{$base}/server.log";
   $large = max(1, $Probe->large);
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
         "memory_limit={$memory}",
         '-d',
         'opcache.jit=0',
         __FILE__,
         '--hhsc3-crash-server',
         BOOTGLY_ROOT_BASE,
         $base,
         (string) $port,
         $row,
         (string) $large,
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
      throw new RuntimeException("H-HSC-3 could not start the {$row} server child.");
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

   throw new RuntimeException("H-HSC-3 {$row} server never answered /m (survivors: {$survivors}): {$output}");
};

/**
 * Run one row: launch its server, attack it, record the worker PID before
 * and after plus the log evidence, and always stop the server.
 */
$Run = static function (string $row, string $memory, Closure $Attack) use ($Probe, $Launch, $Measure, $Stop, $Clean): string {
   // ! The case-wide fixture: this process' ledger and one temp area
   if ($Probe->pending < 0) {
      $Probe->pending = TCP_Server_CLI::$pendingBytes;
   }
   if ($Probe->base === '') {
      $token = bin2hex(random_bytes(6));
      $temporary = sys_get_temp_dir();
      $Probe->base = "{$temporary}/bootgly-hhsc3-crash-{$token}";
      @mkdir($Probe->base, 0700, true);
   }

   $evidence = [
      'error' => '',
      'before' => 0,
      'after' => 0,
      'budget' => 0,
      'fatal' => -1,
      'fatality' => '',
      'warnings' => [],
      'confined' => false,
      'groups' => [],
      'survivors' => null,
      'listening' => null,
   ];
   $server = null;

   try {
      $server = $Launch($row, $memory);
      $evidence['before'] = (int) ($server['metrics']['pid'] ?? 0);
      $evidence['budget'] = (int) ($server['metrics']['budget'] ?? 0);

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
         preg_match_all('/^.*(?:Worker memory budget|A request body of maxBodySize).*$/m', $log, $matches);
         $evidence['warnings'] = array_map('trim', $matches[0]);
      }
      $Probe->rows[$row] = $evidence;

      // ? The last row removes the case's temp area
      if ($row === 'B') {
         $Clean($Probe->base);
      }
   }

   // :
   return "GET /hhsc3-crash-harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
};

/**
 * Measure how many bytes one non-blocking write hands a loopback peer whose
 * receive buffer is 4096 bytes — the same first write the worker makes for a
 * slow reader. Deterministic per kernel: three probes must agree.
 */
$Calibrate = static function () use ($Probe, $suffix): void {
   // ?
   if ($Probe->accepted > 0) {
      return;
   }
   if (function_exists('socket_create') === false) {
      throw new RuntimeException('H-HSC-3 slow readers require ext-sockets (SO_RCVBUF has no stream-context option).');
   }

   $samples = [];
   for ($probe = 0; $probe < 3; $probe++) {
      $Listener = null;
      $Client = null;
      $Peer = null;
      try {
         $Listener = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         if ($Listener === false) {
            throw new RuntimeException("H-HSC-3 calibration listener failed: {$message}");
         }
         $port = (int) substr((string) strrchr((string) stream_socket_get_name($Listener, false), ':'), 1);

         $Client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
         socket_set_option($Client, SOL_SOCKET, SO_RCVBUF, 4096);
         if (@socket_connect($Client, '127.0.0.1', $port) === false) {
            throw new RuntimeException('H-HSC-3 calibration peer could not connect.');
         }
         $Peer = stream_socket_accept($Listener, 2);
         if ($Peer === false) {
            throw new RuntimeException('H-HSC-3 calibration listener accepted nothing.');
         }
         stream_set_blocking($Peer, false);
         usleep(20_000);

         $samples[] = (int) @fwrite($Peer, str_repeat('p', 4_000_000));
      }
      finally {
         if (is_resource($Peer)) {
            fclose($Peer);
         }
         if ($Client instanceof Socket) {
            socket_close($Client);
         }
         if (is_resource($Listener)) {
            fclose($Listener);
         }
      }
   }

   // ? Deterministic and plausible
   if (count(array_unique($samples)) !== 1 || $samples[0] < 4_096 || $samples[0] >= 4_000_000) {
      $observed = json_encode($samples);

      throw new RuntimeException("H-HSC-3 calibration is not deterministic: {$observed}");
   }

   $Probe->accepted = $samples[0];
   $Probe->large = $samples[0] + $suffix;
};


/**
 * Price one HTTP/1 request head the way `Request\Frame::weigh()` charges its
 * parsed form: the raw field block (the lines after the request line, with
 * their last CRLF) and every lowercased field name and trimmed value at their
 * allocator footprint (`Buffers::weigh()`), plus 96 bytes per value — its map
 * bucket and the copies a Request keeps.
 */
$Price = static function (string $head): int {
   // !
   $line = (int) strpos($head, "\r\n");
   $separator = (int) strpos($head, "\r\n\r\n");
   $block = substr($head, $line + 2, $separator - $line);
   $fields = [];

   // @@ Field lines
   foreach (explode("\r\n", $block) as $field) {
      if ($field === '') {
         continue;
      }
      [$name, $value] = explode(':', $field, 2) + ['', ''];
      $fields[strtolower($name)][] = trim($value, " \t");
   }

   // @@ The block, every name, every value
   $bytes = Buffers::weigh(strlen($block));
   foreach ($fields as $name => $values) {
      $bytes += Buffers::weigh(strlen((string) $name));
      foreach ($values as $value) {
         $bytes += Buffers::weigh(strlen($value)) + 96;
      }
   }

   // :
   return $bytes;
};

// # Attacks
/**
 * S1/S6 — `$connections` copies of `$head` (declaring 1,100,000 B) send
 * `$body` bytes each and go silent. Returns who was admitted, who was
 * refused, the ledgers at rest and after every attacker left.
 *
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
$Flood = static function (array $server, int $connections = 64) use ($Measure, $Settle, $body, $head): array {
   // !
   $port = (int) $server['port'];
   $filler = str_repeat('u', $body);
   $payload = "{$head}{$filler}";
   $total = strlen($payload);
   $Sockets = [];
   $offsets = [];
   $inputs = [];
   $closed = [];
   $samples = [];

   try {
      for ($index = 0; $index < $connections; $index++) {
         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-3 flood connection {$index} failed: {$message}");
         }
         stream_set_blocking($Socket, false);
         $Sockets[$index] = $Socket;
         $offsets[$index] = 0;
         $inputs[$index] = '';
         $closed[$index] = false;
      }

      // @@ Send every body, collect any answer, sample `/m` throughout
      $deadline = microtime(true) + 20.0;
      $sampled = 0.0;
      while (microtime(true) < $deadline) {
         $Reads = [];
         $Writes = [];
         foreach ($Sockets as $index => $Socket) {
            if ($closed[$index]) {
               continue;
            }
            $Reads[$index] = $Socket;
            if ($offsets[$index] < $total) {
               $Writes[$index] = $Socket;
            }
         }
         if ($Writes === []) {
            break;
         }

         $Except = null;
         if (@stream_select($Reads, $Writes, $Except, 0, 50_000) === false) {
            break;
         }
         foreach ($Writes as $index => $Socket) {
            $written = @fwrite($Socket, substr($payload, $offsets[$index], 65_536));
            if ($written === false) {
               $closed[$index] = true;
               continue;
            }
            $offsets[$index] += $written;
         }
         foreach ($Reads as $index => $Socket) {
            $chunk = @fread($Socket, 65_536);
            if ($chunk === false || ($chunk === '' && feof($Socket))) {
               $closed[$index] = true;
               continue;
            }
            $inputs[$index] .= $chunk;
         }

         if (microtime(true) - $sampled >= 0.5) {
            $sampled = microtime(true);
            $metrics = $Measure($port, 2.0);
            $samples[] = [(string) ($metrics['status'] ?? 'none'), (int) ($metrics['pid'] ?? 0)];
         }
      }

      // @ At rest: the admitted bodies are fully held
      $held = $Settle($port, ['bodies', 'pending']) ?? [];
      $samples[] = [(string) ($held['status'] ?? 'none'), (int) ($held['pid'] ?? 0)];

      // @ Late answers
      usleep(100_000);
      foreach ($Sockets as $index => $Socket) {
         if ($closed[$index] === false) {
            $chunk = @fread($Socket, 65_536);
            if (is_string($chunk)) {
               $inputs[$index] .= $chunk;
            }
            if ($chunk === false || ($chunk === '' && feof($Socket))) {
               $closed[$index] = true;
            }
         }
      }
   }
   finally {
      // ! Evidence first, then every attacker leaves
      $admitted = 0;
      $answered = 0;
      $reset = 0;
      $other = 0;
      foreach ($Sockets as $index => $Socket) {
         if (str_starts_with($inputs[$index], 'HTTP/1.1 503 Service Unavailable')) {
            $answered++;
         }
         else if ($closed[$index] && $inputs[$index] === '') {
            $reset++;
         }
         else if ($closed[$index] === false && $inputs[$index] === '' && $offsets[$index] === $total) {
            $admitted++;
         }
         else {
            $other++;
         }

         fclose($Socket);
      }
   }

   $released = $Settle($port, ['bodies', 'pending'], [0, 0]) ?? [];

   // :
   return [
      'connections' => $connections,
      'admitted' => $admitted,
      'answered' => $answered,
      'reset' => $reset,
      'other' => $other,
      'samples' => $samples,
      'held' => [
         'bodies' => $held['bodies'] ?? null,
         'inbound' => $held['inbound'] ?? null,
         'pending' => $held['pending'] ?? null,
         'peak' => $held['peak'] ?? null,
      ],
      'released' => [
         'bodies' => $released['bodies'] ?? null,
         'pending' => $released['pending'] ?? null,
      ],
   ];
};

/**
 * S2 — h2c prior knowledge: 7 connections x 9 streams, each POST stream
 * sending `$body` DATA bytes without END_STREAM, honoring flow control.
 *
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
$Stream = static function (array $server) use ($Measure, $Settle, $body): array {
   // !
   $port = (int) $server['port'];
   $Frame = static function (int $type, int $flags, int $stream, string $payload): string {
      $length = substr(pack('N', strlen($payload)), 1);
      $kind = chr($type);
      $flag = chr($flags);
      $identifier = pack('N', $stream & 0x7fffffff);

      return "{$length}{$kind}{$flag}{$identifier}{$payload}";
   };
   $Send = static function (mixed $Socket, string $bytes, float $timeout = 2.0): bool {
      $deadline = microtime(true) + $timeout;
      $offset = 0;
      $length = strlen($bytes);
      while ($offset < $length) {
         if (microtime(true) >= $deadline) {
            return false;
         }
         $Reads = null;
         $Writes = [$Socket];
         $Except = null;
         if (@stream_select($Reads, $Writes, $Except, 0, 20_000) < 1) {
            continue;
         }
         $written = @fwrite($Socket, substr($bytes, $offset));
         if ($written === false) {
            return false;
         }
         $offset += $written;
      }

      return true;
   };
   $Pump = static function (array &$connection, float $timeout) use ($Frame, $Send): void {
      // ?
      if ($connection['closed']) {
         return;
      }

      $Reads = [$connection['Socket']];
      $Writes = null;
      $Except = null;
      $seconds = (int) $timeout;
      $micro = (int) (($timeout - $seconds) * 1_000_000);
      if (@stream_select($Reads, $Writes, $Except, $seconds, $micro) < 1) {
         return;
      }
      $chunk = @fread($connection['Socket'], 65_536);
      if ($chunk === false || ($chunk === '' && feof($connection['Socket']))) {
         $connection['closed'] = true;

         return;
      }
      $connection['buffer'] .= $chunk;

      // @@ Every complete frame
      while (strlen($connection['buffer']) >= 9) {
         $prefix = substr($connection['buffer'], 0, 3);
         $length = unpack('N', "\0{$prefix}")[1];
         if (strlen($connection['buffer']) < 9 + $length) {
            break;
         }
         $type = ord($connection['buffer'][3]);
         $flags = ord($connection['buffer'][4]);
         $stream = unpack('N', substr($connection['buffer'], 5, 4))[1] & 0x7fffffff;
         $payload = substr($connection['buffer'], 9, $length);
         $connection['buffer'] = substr($connection['buffer'], 9 + $length);

         if ($type === 4 && ($flags & 1) === 0) {
            for ($offset = 0; $offset + 6 <= strlen($payload); $offset += 6) {
               $setting = unpack('n', substr($payload, $offset, 2))[1];
               $value = unpack('N', substr($payload, $offset + 2, 4))[1];
               if ($setting === 4) {
                  $delta = $value - $connection['initial'];
                  $connection['initial'] = $value;
                  foreach ($connection['windows'] as $identifier => $window) {
                     $connection['windows'][$identifier] = $window + $delta;
                  }
               }
            }
            $Send($connection['Socket'], $Frame(4, 1, 0, ''));
         }
         else if ($type === 8) {
            $increment = unpack('N', $payload)[1] & 0x7fffffff;
            if ($stream === 0) {
               $connection['window'] += $increment;
            }
            else if (isset($connection['windows'][$stream])) {
               $connection['windows'][$stream] += $increment;
            }
         }
         else if ($type === 3) {
            $connection['resets'][$stream] = unpack('N', $payload)[1];
         }
         else if ($type === 1) {
            // ! `:status` as a literal with the static name index 8
            $connection['statuses'][$stream] = str_starts_with($payload, "\x08\x03")
               ? substr($payload, 2, 3)
               : bin2hex(substr($payload, 0, 8));
         }
         else if ($type === 7) {
            $connection['closed'] = true;
         }
      }
   };

   $chunk = str_repeat('h', 16_384);
   $block = "\x83\x86\x04\x02/u\x01\x09localhost";
   $connections = [];
   $samples = [];
   $held = [];
   $released = [];

   try {
      // @@ Connections in sequence, streams in sequence
      for ($index = 0; $index < 7; $index++) {
         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-3 h2c connection {$index} failed: {$message}");
         }
         stream_set_blocking($Socket, false);
         $connection = [
            'Socket' => $Socket,
            'window' => 65_535,
            'windows' => [],
            'initial' => 65_535,
            'buffer' => '',
            'closed' => false,
            'resets' => [],
            'statuses' => [],
            'sent' => [],
         ];
         $preface = $Frame(4, 0, 0, '');
         if ($Send($Socket, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n{$preface}") === false) {
            $connection['closed'] = true;
         }
         $Pump($connection, 0.3);

         for ($ordinal = 0; $ordinal < 9 && $connection['closed'] === false; $ordinal++) {
            $stream = 1 + 2 * $ordinal;
            if ($Send($Socket, $Frame(1, 4, $stream, $block)) === false) {
               $connection['closed'] = true;
               break;
            }
            $connection['windows'][$stream] = $connection['initial'];

            $left = $body;
            $deadline = microtime(true) + 6.0;
            while (
               $left > 0
               && $connection['closed'] === false
               && isset($connection['resets'][$stream]) === false
               && isset($connection['statuses'][$stream]) === false
               && microtime(true) < $deadline
            ) {
               $size = min($left, 16_384, $connection['window'], $connection['windows'][$stream]);
               if ($size <= 0) {
                  $Pump($connection, 0.02);
                  continue;
               }
               if ($Send($Socket, $Frame(0, 0, $stream, substr($chunk, 0, $size))) === false) {
                  $connection['closed'] = true;
                  break;
               }
               $connection['window'] -= $size;
               $connection['windows'][$stream] -= $size;
               $left -= $size;
               $Pump($connection, 0);
            }
            $connection['sent'][$stream] = $body - $left;
         }
         $connections[$index] = $connection;

         $metrics = $Measure($port, 2.0);
         $samples[] = [(string) ($metrics['status'] ?? 'none'), (int) ($metrics['pid'] ?? 0)];
      }

      // @@ Late refusals
      for ($round = 0; $round < 10; $round++) {
         foreach ($connections as $index => $connection) {
            $Pump($connection, 0.03);
            $connections[$index] = $connection;
         }
      }

      // @ At rest
      $held = $Settle($port, ['streams', 'pending']) ?? [];
      $samples[] = [(string) ($held['status'] ?? 'none'), (int) ($held['pid'] ?? 0)];
   }
   finally {
      // ! Evidence first, then every attacker leaves
      $holding = 0;
      $refused = 0;
      $denied = 0;
      $other = 0;
      $open = 0;
      foreach ($connections as $connection) {
         if ($connection['closed'] === false) {
            $open++;
         }
         foreach ($connection['sent'] as $stream => $sent) {
            $status = $connection['statuses'][$stream] ?? null;
            if ($status !== null || isset($connection['resets'][$stream])) {
               $refused++;
               if ($status === '413') {
                  $denied++;
               }
            }
            else if ($sent === $body && $connection['closed'] === false) {
               $holding++;
            }
            else {
               $other++;
            }
         }

         fclose($connection['Socket']);
      }
   }

   $released = $Settle($port, ['streams', 'bodies', 'pending'], [0, 0, 0]) ?? [];

   // :
   return [
      'holding' => $holding,
      'refused' => $refused,
      'denied' => $denied,
      'other' => $other,
      'open' => $open,
      'samples' => $samples,
      'held' => [
         'streams' => $held['streams'] ?? null,
         'bodies' => $held['bodies'] ?? null,
         'pending' => $held['pending'] ?? null,
      ],
      'released' => [
         'streams' => $released['streams'] ?? null,
         'bodies' => $released['bodies'] ?? null,
         'pending' => $released['pending'] ?? null,
      ],
   ];
};

/**
 * S3 — 61 distinct cached ~1 MiB wires, each request sent keep-alive and the
 * connection closed unread; then one byte-correct read of the newest key.
 *
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
$Prime = static function (array $server) use ($Fetch, $Measure, $Settle): array {
   // !
   $port = (int) $server['port'];
   $requests = 61;
   $failures = 0;

   // @@
   for ($index = 0; $index < $requests; $index++) {
      $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
      if ($Socket === false) {
         $failures++;
         continue;
      }
      @fwrite($Socket, "GET /c?k={$index} HTTP/1.1\r\nHost: localhost\r\n\r\n");
      fclose($Socket);
   }

   // @ At rest
   $stored = $Settle($port, ['runs', 'entries', 'pending']) ?? [];

   // @ The newest key, keep-alive: served from the cache, byte-correct
   $key = $requests - 1;
   $exchange = $Fetch($port, "GET /c?k={$key} HTTP/1.1\r\nHost: localhost\r\n\r\n", 5.0);
   $hit = $Measure($port) ?? [];

   // :
   return [
      'requests' => $requests,
      'failures' => $failures,
      'samples' => [[(string) ($stored['status'] ?? 'none'), (int) ($stored['pid'] ?? 0)]],
      'stored' => [
         'runs' => $stored['runs'] ?? null,
         'entries' => $stored['entries'] ?? null,
         'cached' => $stored['cached'] ?? null,
         'pending' => $stored['pending'] ?? null,
      ],
      'read' => [
         'status' => $exchange['status'],
         'wire' => strlen($exchange['head']) + strlen($exchange['body']),
         'correct' => $exchange['body'] === str_pad("/c?k={$key}", 1_048_400, 'c'),
         'runs' => $hit['runs'] ?? null,
      ],
   ];
};

/**
 * S4/L3 — `$readers` slow readers (SO_RCVBUF 4096) request `/p` and do not
 * read; `/m` samples the ledger at rest; then every reader drains what it can.
 *
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
$Hold = static function (array $server, int $readers) use ($Probe, $Measure, $Settle): array {
   // !
   $port = (int) $server['port'];
   $Sockets = [];
   $samples = [];
   $pendings = [];
   $complete = 0;
   $truncated = 0;
   $correct = true;
   $head = 0;

   try {
      // @@ Connect and request, never read
      for ($index = 0; $index < $readers; $index++) {
         $Socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
         socket_set_option($Socket, SOL_SOCKET, SO_RCVBUF, 4096);
         if (@socket_connect($Socket, '127.0.0.1', $port) === false) {
            socket_close($Socket);
            throw new RuntimeException("H-HSC-3 slow reader {$index} could not connect.");
         }
         $Sockets[$index] = $Socket;
         @socket_write($Socket, "GET /p HTTP/1.1\r\nHost: localhost\r\n\r\n");
      }

      // @@ At rest: `/m` throughout the hold
      usleep(500_000);
      for ($sample = 0; $sample < 3; $sample++) {
         $metrics = $Measure($port, 2.0);
         $samples[] = [(string) ($metrics['status'] ?? 'none'), (int) ($metrics['pid'] ?? 0)];
         $pendings[] = (int) ($metrics['pending'] ?? -1);
         usleep(200_000);
      }

      // @@ Drain every reader: head, then exactly Content-Length 'p' bytes
      $states = [];
      foreach ($Sockets as $index => $Socket) {
         socket_set_nonblock($Socket);
         $states[$index] = ['head' => '', 'expected' => -1, 'received' => 0, 'done' => false];
      }
      $deadline = microtime(true) + 30.0;
      while (microtime(true) < $deadline) {
         $Reads = [];
         foreach ($Sockets as $index => $Socket) {
            if ($states[$index]['done'] === false) {
               $Reads[$index] = $Socket;
            }
         }
         if ($Reads === []) {
            break;
         }
         $Writes = null;
         $Except = null;
         if (@socket_select($Reads, $Writes, $Except, 0, 100_000) === false) {
            break;
         }
         foreach ($Reads as $index => $Socket) {
            $chunk = @socket_read($Socket, 1 << 16, PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
               $states[$index]['done'] = true;
               continue;
            }

            $state = $states[$index];
            if ($state['expected'] < 0) {
               $state['head'] .= $chunk;
               $separator = strpos($state['head'], "\r\n\r\n");
               if ($separator === false) {
                  $states[$index] = $state;
                  continue;
               }
               $prefix = substr($state['head'], 0, $separator + 2);
               $chunk = substr($state['head'], $separator + 4);
               $state['expected'] = preg_match('/\r\nContent-Length:[ \t]*(\d+)/i', $prefix, $matches) === 1
                  ? (int) $matches[1]
                  : 0;
               $head = $separator + 4;
               if (str_starts_with($prefix, 'HTTP/1.1 200') === false) {
                  $correct = false;
               }
            }
            if ($chunk !== '' && strspn($chunk, 'p') !== strlen($chunk)) {
               $correct = false;
            }
            $state['received'] += strlen($chunk);
            if ($state['received'] >= $state['expected']) {
               $state['done'] = true;
            }
            $states[$index] = $state;
         }
      }

      foreach ($states as $state) {
         if ($state['expected'] === $Probe->large && $state['received'] === $Probe->large) {
            $complete++;
         }
         else {
            $truncated++;
         }
      }
   }
   finally {
      foreach ($Sockets as $Socket) {
         socket_close($Socket);
      }
   }

   $released = $Settle($port, ['pending'], [0]) ?? [];

   // :
   return [
      'readers' => $readers,
      'cap' => (int) ($server['metrics']['cap'] ?? -1),
      'accepted' => $Probe->accepted,
      'large' => $Probe->large,
      'head' => $head,
      'complete' => $complete,
      'truncated' => $truncated,
      'correct' => $correct,
      'samples' => $samples,
      'pendings' => $pendings,
      'released' => ['pending' => $released['pending'] ?? null],
   ];
};

/**
 * L1 — `$count` concurrent uploads of `$bytes` each (Content-Length).
 *
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
$Upload = static function (array $server, int $count, int $bytes) use ($Settle): array {
   // !
   $port = (int) $server['port'];
   $filler = str_repeat('u', $bytes);
   $payload = "POST /u HTTP/1.1\r\nHost: localhost\r\nContent-Length: {$bytes}\r\nConnection: close\r\n\r\n{$filler}";
   $total = strlen($payload);
   $Sockets = [];
   $offsets = [];
   $inputs = [];
   $closed = [];

   try {
      for ($index = 0; $index < $count; $index++) {
         $Socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 3);
         if ($Socket === false) {
            throw new RuntimeException("H-HSC-3 upload {$index} could not connect: {$message}");
         }
         stream_set_blocking($Socket, false);
         $Sockets[$index] = $Socket;
         $offsets[$index] = 0;
         $inputs[$index] = '';
         $closed[$index] = false;
      }

      // @@ Interleaved writes, read every answer to EOF
      $deadline = microtime(true) + 30.0;
      while (microtime(true) < $deadline) {
         $Reads = [];
         $Writes = [];
         foreach ($Sockets as $index => $Socket) {
            if ($closed[$index]) {
               continue;
            }
            $Reads[$index] = $Socket;
            if ($offsets[$index] < $total) {
               $Writes[$index] = $Socket;
            }
         }
         if ($Reads === []) {
            break;
         }
         $Except = null;
         if (@stream_select($Reads, $Writes, $Except, 0, 50_000) === false) {
            break;
         }
         foreach ($Writes as $index => $Socket) {
            $written = @fwrite($Socket, substr($payload, $offsets[$index], 65_536));
            if ($written === false) {
               $closed[$index] = true;
               continue;
            }
            $offsets[$index] += $written;
         }
         foreach ($Reads as $index => $Socket) {
            $chunk = @fread($Socket, 65_536);
            if ($chunk === false || ($chunk === '' && feof($Socket))) {
               $closed[$index] = true;
               continue;
            }
            $inputs[$index] .= $chunk;
         }
      }
   }
   finally {
      foreach ($Sockets as $Socket) {
         fclose($Socket);
      }
   }

   $answers = [];
   foreach ($inputs as $input) {
      $separator = strpos($input, "\r\n\r\n");
      $status = strstr($input, "\r\n", true);
      $answers[] = [
         'status' => $status === false ? $input : $status,
         'body' => $separator === false ? '' : substr($input, $separator + 4),
      ];
   }
   $released = $Settle($port, ['bodies', 'pending'], [0, 0]) ?? [];

   // :
   return [
      'answers' => $answers,
      'released' => [
         'bodies' => $released['bodies'] ?? null,
         'pending' => $released['pending'] ?? null,
      ],
   ];
};

/**
 * L2 — 8 distinct cached ~1 MiB pages, each fetched twice keep-alive.
 *
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
$Page = static function (array $server) use ($Fetch, $Measure): array {
   // !
   $port = (int) $server['port'];
   $correct = 0;
   $statuses = [];

   // @@ A cold pass, then a pass that must hit
   for ($pass = 0; $pass < 2; $pass++) {
      for ($page = 0; $page < 8; $page++) {
         $exchange = $Fetch($port, "GET /c?page={$page} HTTP/1.1\r\nHost: localhost\r\n\r\n", 5.0);
         $statuses[] = $exchange['status'];
         if ($exchange['status'] === '200' && $exchange['body'] === str_pad("/c?page={$page}", 1_048_400, 'c')) {
            $correct++;
         }
      }
   }
   $metrics = $Measure($port) ?? [];

   // :
   return [
      'correct' => $correct,
      'statuses' => array_count_values($statuses),
      'runs' => $metrics['runs'] ?? null,
      'entries' => $metrics['entries'] ?? null,
   ];
};


return new Test(
   description: 'H-HSC-3: crash shapes stay inside the worker memory budget, legit loads pass',
   Separator: new Separator(line: true),

   requests: [
      // # Legit loads (must pass before and after the fix)
      static fn (): string => $Run('L1', '128M', static function (array $server) use ($Upload): array {
         $bytes = new ReflectionProperty(Request::class, 'maxBodySize')->getDefaultValue();

         return ['bytes' => $bytes] + $Upload($server, 3, $bytes);
      }),
      static fn (): string => $Run('L2', '128M', $Page),
      static function () use ($Run, $Hold, $Calibrate): string {
         $Calibrate();

         return $Run('L3', '128M', static fn (array $server): array => $Hold($server, 16));
      },
      // # Crash shapes
      static fn (): string => $Run('S1', '128M', static fn (array $server): array => $Flood($server, 64)),
      static fn (): string => $Run('S2', '128M', $Stream),
      static fn (): string => $Run('S3', '128M', $Prime),
      static function () use ($Run, $Hold, $Calibrate): string {
         $Calibrate();

         return $Run('S4', '128M', static fn (array $server): array => $Hold($server, 58));
      },
      static fn (): string => $Run('S6', '64M', static fn (array $server): array => $Flood($server, 64)),
      static fn (): string => $Run('B', '128M', static fn (array $server): array => []),
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/hhsc3-crash-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'HHSC3-HARNESS');
      }, GET);
   },

   test: static function (array $responses) use ($Probe, $Clean, $body, $head, $Price): Generator {
      // ! Idempotent: the last row already removed the temp area
      $Clean($Probe->base);

      // ! Values the design fixes: one unfinished body held whole (past half
      //   a transport read, so its string is charged as itself) and the parsed
      //   S1/S6 head it keeps and pays with it
      $footprint = Buffers::weigh($body);
      $price = $Price($head);
      $default = new ReflectionProperty(TCP_Server_CLI::class, 'maxWorkerPendingBytes')->getDefaultValue();
      $cap = new ReflectionProperty(TCP_Server_CLI::class, 'maxPendingBytes')->getDefaultValue();

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
      // # S1/S6: the Inbound share admits whole 2 MiB bodies with their
      //   parsed heads, exactly as many as fit L/2
      $Admission = static function (array $evidence) use ($footprint, $price, $body, $Liveness): array {
         $budget = (int) $evidence['budget'];
         $each = $footprint + $price;
         $admits = intdiv(intdiv($budget, 2), $each);

         return [
            '/m answered 200 throughout' => $Liveness($evidence),
            'admitted exactly what fits L/2, body and parsed head' => $evidence['admitted'] === $admits,
            'every other body refused (503 or reset), refusal reached' => $evidence['answered'] >= 1
               && $evidence['answered'] + $evidence['reset'] === $evidence['connections'] - $admits
               && $evidence['other'] === 0,
            'ledgers hold the admitted bodies and their heads exactly' => $evidence['held']['bodies'] === $admits * $body
               && $evidence['held']['inbound'] === $admits * $each
               && $evidence['held']['pending'] === $admits * $each,
            'everything released once the attackers left' => $evidence['released'] === ['bodies' => 0, 'pending' => 0],
         ];
      };

      // # Row checks
      $Checks = [
         'L1' => static fn (array $evidence): array => [
            'three 200 answers with the full body' => $evidence['answers'] === array_fill(0, 3, [
               'status' => 'HTTP/1.1 200 OK',
               'body' => "ok {$evidence['bytes']}",
            ]),
            'released' => $evidence['released'] === ['bodies' => 0, 'pending' => 0],
            'default config boots without budget warnings' => $evidence['warnings'] === [],
         ],
         'L2' => static fn (array $evidence): array => [
            '16 byte-correct 200 pages' => $evidence['correct'] === 16 && $evidence['statuses'] === ['200' => 16],
            'second pass served from the cache' => $evidence['runs'] === 8 && $evidence['entries'] === 8,
         ],
         'L3' => static fn (array $evidence): array => [
            'every reader drains the whole body' => $evidence['complete'] === 16
               && $evidence['truncated'] === 0
               && $evidence['correct'] === true,
            '/m answered 200 throughout' => $Liveness($evidence),
            'released' => $evidence['released'] === ['pending' => 0],
         ],
         'S1' => $Admission,
         'S2' => static function (array $evidence) use ($footprint, $body, $Liveness): array {
            $budget = (int) $evidence['budget'];
            $admits = intdiv(intdiv($budget, 2), $footprint);
            $pending = (int) ($evidence['held']['pending'] ?? -1);

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'exactly L/2 of footprint holds stream bodies' => $evidence['holding'] === $admits,
               'every other stream refused with 413' => $evidence['refused'] === 63 - $admits
                  && $evidence['denied'] === $evidence['refused']
                  && $evidence['other'] === 0
                  && $evidence['open'] === 7,
               'ledgers hold the admitted streams exactly' => $evidence['held']['streams'] === $admits * $body
                  && $evidence['held']['bodies'] === $admits * $body
                  && $pending >= $admits * $footprint
                  && $pending <= $budget,
               'everything released once the attackers left' => $evidence['released'] === ['streams' => 0, 'bodies' => 0, 'pending' => 0],
            ];
         },
         'S3' => static function (array $evidence) use ($Liveness): array {
            $budget = (int) $evidence['budget'];
            $wire = (int) $evidence['read']['wire'];
            $charge = Buffers::weigh($wire);
            $entries = intdiv(intdiv($budget, 4), $charge);

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'every request reached the cache store' => $evidence['failures'] === 0 && $evidence['stored']['runs'] === 61,
               'the Resident share keeps exactly L/4 of footprint' => $evidence['stored']['entries'] === $entries
                  && $evidence['stored']['cached'] === $entries * $wire
                  && $evidence['stored']['pending'] === $entries * $charge,
               'the newest key is a byte-correct cache hit' => $evidence['read']['status'] === '200'
                  && $evidence['read']['correct'] === true
                  && $evidence['read']['runs'] === 61,
            ];
         },
         'S4' => static function (array $evidence) use ($Liveness, $cap): array {
            $budget = (int) $evidence['budget'];
            $charge = Buffers::weigh($evidence['head'] + $evidence['large'] - $evidence['accepted']);
            $holds = $charge <= $evidence['cap'] ? min($evidence['readers'], intdiv($budget, $charge)) : 0;

            return [
               '/m answered 200 throughout' => $Liveness($evidence),
               'one suffix fits the default per-connection cap, so only L refuses' => $evidence['cap'] === $cap
                  && $charge <= $cap,
               // ? The cap compares footprints now: what one connection could
               //   hold raw before (a 4 MiB suffix) must still fit it
               'one connection still holds a 4 MiB suffix at its footprint' => Buffers::weigh(4 * 1024 * 1024) <= $evidence['cap'],
               'exactly L of footprint holds output' => $evidence['complete'] === $holds
                  && $evidence['correct'] === true,
               'every other reader aborted, the budget was reached' => $evidence['truncated'] === $evidence['readers'] - $holds
                  && $evidence['truncated'] >= 1,
               'the charged total never exceeded L' => $evidence['pendings'] === array_fill(0, 3, $holds * $charge)
                  && $holds * $charge <= $budget,
               'released' => $evidence['released'] === ['pending' => 0],
            ];
         },
         'S6' => static function (array $evidence) use ($Admission, $default): array {
            $fitted = Buffers::fit($default, ini_parse_quantity('64M'));

            return [
               'the budget is lowered to half of memory_limit' => $evidence['budget'] === $fitted
                  && $fitted === intdiv(ini_parse_quantity('64M'), 2),
               'the lowering is logged' => array_filter(
                  $evidence['warnings'],
                  static fn (string $line): bool => str_contains(
                     $line,
                     "Worker memory budget (maxWorkerPendingBytes) lowered from {$default} to {$fitted} bytes to fit memory_limit 64M"
                  ),
               ) !== [],
            ] + $Admission($evidence);
         },
         'B' => static function (array $evidence): array {
            $size = 32 * 1024 * 1024;
            $share = intdiv((int) $evidence['budget'], 2);

            return [
               'the body does not fit the Inbound share' => Buffers::weigh($size) > $share,
               'the boot warns about maxBodySize' => array_filter(
                  $evidence['warnings'],
                  static fn (string $line): bool => str_contains(
                     $line,
                     "A request body of maxBodySize ({$size} bytes) cannot fit what unfinished bodies may hold ({$share} bytes, half of maxWorkerPendingBytes"
                  ),
               ) !== [],
            ];
         },
      ];
      $descriptions = [
         'L1' => 'L1 legit: 3 concurrent 10 MiB uploads answer 200 on the same worker',
         'L2' => 'L2 legit: 8 cached ~1 MiB pages are stored and hit byte-correct',
         'L3' => 'L3 legit: 16 slow readers holding a 2 MiB suffix each drain fully',
         'S1' => 'S1: 64 unfinished 1 MiB HTTP/1 bodies — what fits L/2 with its parsed head admitted, the rest 503, no refork',
         'S2' => 'S2: 7x9 unfinished h2c stream bodies — L/2 held, the rest 413, no refork',
         'S3' => 'S3: 61 distinct ~1 MiB cache wires — L/4 kept, newest hit byte-correct, no refork',
         'S4' => 'S4: 58 slow readers with 2 MiB suffixes, each within the per-connection cap — L held, the rest aborted, no refork',
         'S6' => 'S6: memory_limit 64M lowers L to 32 MiB with a warning; S1 admits what fits L/2, no refork',
         'B' => 'B: a 32 MiB maxBodySize warns at boot that it cannot fit the Inbound share',
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

      // @ The native harness reached the suite worker once per row
      yield new Assertion(
         description: 'Harness: every row answered on the suite worker',
         fallback: "Harness responses: {$summary}",
      )
         ->expect(
            array_map(static fn (string $response): bool => str_contains($response, 'HHSC3-HARNESS'), $responses),
            Op::Identical,
            array_fill(0, 9, true),
         )
         ->assert();

      // @@ One assertion per row, legit loads first
      foreach ($verdicts as $row => $verdict) {
         $evidence = (string) json_encode($verdict['evidence'], JSON_UNESCAPED_SLASHES);

         yield new Assertion(
            description: $descriptions[$row],
            fallback: "{$row} evidence: {$evidence} | all rows: {$summary}",
         )
            ->expect($verdict['failed'], Op::Identical, [])
            ->assert();
      }

      // @ This process' ledger is where the case found it
      yield new Assertion(
         description: 'The spec process ledger (TCP_Server_CLI::$pendingBytes) is unchanged',
      )
         ->expect(TCP_Server_CLI::$pendingBytes, Op::Identical, $Probe->pending)
         ->assert();
   },
);
