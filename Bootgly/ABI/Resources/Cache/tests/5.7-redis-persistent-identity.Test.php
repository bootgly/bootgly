<?php

use Bootgly\ACI\Tests\Suite\Test;


// ! The driver prefers ext-redis whenever it is loaded, which makes the native RESP path
//   unreachable in-process. Clearing the ini scan directory usually drops the extension;
//   probe a child to find out whether this machine can reach the path at all.
//   The child is the environment that decides, and it is not this one — it runs with the
//   scan directory cleared, which on some hosts also unloads `pcntl`. Both capabilities are
//   therefore probed THERE, so a host that cannot deliver is skipped by this guard instead
//   of being reported by the body, where a skip is indistinguishable from a pass.
$PHP = PHP_BINARY;
$probe = @shell_exec(
   'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP)
      . ' -r ' . escapeshellarg(
         'echo extension_loaded("redis") ? "1" : "0",'
            . ' function_exists("pcntl_fork") ? "1" : "0";'
      ) . ' 2>/dev/null'
);
$native = trim((string) $probe) === '01';

return new Test(
   description: 'Cache(Redis): a connection that needs its own session never joins the shared pool',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('shell_exec') === false
      || $native === false,
   test: function () use ($PHP) {
      // ! A stub Redis driven in a child process without ext-redis
      $script = __DIR__ . '/redis-persistent.php';
      $output = @shell_exec(
         'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP)
            . ' -r ' . escapeshellarg('require $_SERVER["argv"][1] ?? "";')
            . ' ' . escapeshellarg($script) . ' 2>/dev/null'
      );
      $observed = json_decode(trim((string) $output), true);

      // ? The child refused a run the guard admitted, so the two disagree about the same
      //   environment. This must fail: a body-side skip is reported as a pass, and the case
      //   would then be green while measuring nothing at all.
      if (is_array($observed) === true && isset($observed['skip']) === true) {
         yield assert(
            assertion: false,
            description: 'The child refused a run this case was admitted for: '
               . (string) $observed['skip']
         );

         return;
      }

      yield assert(
         assertion: is_array($observed) && isset(
            $observed['shared'],
            $observed['borrowed'],
            $observed['undrained'],
            $observed['identities'],
         ),
         description: 'The stub-server probe produced no readable result: '
            . substr((string) $output, 0, 400)
      );

      if (is_array($observed) === false) {
         return;
      }

      $shared = is_array($observed['shared'] ?? null) ? $observed['shared'] : [];
      $borrowed = is_array($observed['borrowed'] ?? null) ? $observed['borrowed'] : [];
      /** @var array<int,string> $sharedLines */
      $sharedLines = is_array($shared['transcript'] ?? null) ? $shared['transcript'] : [];
      /** @var array<int,string> $borrowedLines */
      $borrowedLines = is_array($borrowed['transcript'] ?? null) ? $borrowed['transcript'] : [];
      $said = static function (array $lines, int $connection, string $command): bool {
         foreach ($lines as $line) {
            if ($line === "{$connection}|{$command}") {
               return true;
            }
         }

         return false;
      };
      $connections = static function (array $lines): int {
         $seen = [];

         foreach ($lines as $line) {
            $seen[strstr((string) $line, '|', true)] = true;
         }

         return count($seen);
      };

      // # A driver that needs its own database gets its own socket
      //   PHP keys the persistent pool on "tcp://host:port" alone, so two drivers on one
      //   endpoint are handed the same stream and the second one's SELECT moves the
      //   first one's database with nothing to detect it.
      yield assert(
         assertion: ($shared['shared'] ?? null) === false
            && ($shared['default.stream'] ?? '') !== ($shared['other.stream'] ?? '')
            && $connections($sharedLines) === 2,
         description: 'Two databases on one endpoint do not share a stream, found: '
            . json_encode([$shared['default.stream'] ?? null, $shared['other.stream'] ?? null])
      );

      yield assert(
         assertion: $said($sharedLines, 2, 'SELECT 3')
            && $said($sharedLines, 1, 'SELECT 0') === true,
         description: 'The unpooled driver selects on its own connection, found: '
            . json_encode($sharedLines)
      );

      // # …and pays for no resync, because its socket is new
      //   Aligning a stream is for one a previous owner handed over. A driver that just
      //   opened its own owes no round trip for that.
      $resynced = false;

      foreach ($sharedLines as $line) {
         if (str_starts_with((string) $line, '2|ECHO ')) {
            $resynced = true;
         }
      }

      yield assert(
         assertion: $resynced === false,
         description: 'An unpooled connection does not resync, found: ' . json_encode($sharedLines)
      );

      // # A pooled connection states its database instead of inheriting one
      //   This is the half that survives a stranger: the stream really is shared here —
      //   asserted, so the section cannot pass by failing to reproduce — and the driver
      //   must repair what it inherits.
      yield assert(
         assertion: ($borrowed['borrowed'] ?? null) === true,
         description: 'The pooled driver must be handed the stranger stream, found: '
            . json_encode([$borrowed['stranger.stream'] ?? null, $borrowed['default.stream'] ?? null])
      );

      $repaired = false;
      $stranger = false;

      foreach ($borrowedLines as $line) {
         if ($line === '1|SELECT 3') {
            $stranger = true;
         }

         if ($stranger === true && $line === '1|SELECT 0') {
            $repaired = true;
         }
      }

      yield assert(
         assertion: $stranger === true && $repaired === true,
         description: 'A pooled connect selects its own database over the inherited one, found: '
            . json_encode($borrowedLines)
      );

      // # …and the realignment comes first in the transcript, not just in its effect
      //   This pins the round trip's presence and its position at once: a pooled connect
      //   that never plants the landmark, and one that plants it after the SELECT, both
      //   leave `1|SELECT 0` ahead of `1|ECHO `.
      $echo = -1;
      $select = -1;

      foreach ($borrowedLines as $index => $line) {
         if ($echo === -1 && str_starts_with((string) $line, '1|ECHO ')) {
            $echo = $index;
         }

         if ($select === -1 && $line === '1|SELECT 0') {
            $select = $index;
         }
      }

      yield assert(
         assertion: $echo >= 0 && $select >= 0 && $echo < $select,
         description: 'A pooled connect realigns before it selects, found: '
            . json_encode($borrowedLines)
      );

      // # …and it realigns that stream before it reads anything on it
      //   Order is the whole guarantee here. read() refuses debris — it hands a
      //   stranger's error frame back as this caller's failure and calls a surplus
      //   reply a desync — while resync() is built to skip past exactly that. Any
      //   command ordered ahead of the landmark fails an operation on a stream that
      //   was perfectly recoverable. `borrowed` is asserted too, so the section
      //   cannot pass by quietly getting a stream of its own.
      $undrained = is_array($observed['undrained'] ?? null) ? $observed['undrained'] : [];

      yield assert(
         assertion: ($undrained['outcome'] ?? null) === 'served'
            && ($undrained['borrowed'] ?? null) === true,
         description: 'A pooled connect realigns an undrained stream before reading on it, found: '
            . json_encode($undrained)
      );

      // # Two credentials on one endpoint never share a connection
      //   `AUTH` is connection state exactly as `SELECT` is, and the pool key carries
      //   neither. On a shared stream the second driver's `AUTH` re-authenticates the socket
      //   the first one still holds, and every later command of the first one runs as
      //   somebody else — the same defect as the database, with the blast radius of an
      //   identity.
      $identities = is_array($observed['identities'] ?? null) ? $observed['identities'] : [];
      /** @var array<int,string> $identityLines */
      $identityLines = is_array($identities['transcript'] ?? null) ? $identities['transcript'] : [];

      yield assert(
         assertion: ($identities['shared'] ?? null) === false
            && $said($identityLines, 1, 'AUTH alpha')
            && $said($identityLines, 2, 'AUTH beta'),
         description: 'Two passwords on one endpoint do not share a stream, found: '
            . json_encode($identityLines)
      );
   }
);
