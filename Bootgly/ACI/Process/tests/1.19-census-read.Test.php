<?php


use Bootgly\ACI\Process\Census;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\Census: one `ps` run reads the parent, UIDs, state and birth of live PIDs — absent, reaped and refused PIDs have no row',
   // ? A host without the system `ps` keywords (BusyBox lacks them) has
   //   nothing to read — never skipped on Darwin, where the proof needs it
   skip: function_exists('pcntl_fork') === false
      || (PHP_OS_FAMILY !== 'Darwin' && Census::read((int) getmypid()) === []),
   test: function () {
      $self = (int) getmypid();
      /** @var array<int,int> $children PIDs reaped in finally */
      $children = [];
      // ! A forked child runs its body, then kills itself: no shutdown
      //   handler of the runner may run twice
      $spawn = static function (Closure $Body) use (&$children): int {
         $PID = pcntl_fork();
         if ($PID === 0) {
            $Body();
            posix_kill(posix_getpid(), SIGKILL);
         }
         if ($PID > 0) {
            $children[] = $PID;
         }

         return $PID;
      };

      try {
         // # This process
         $row = Census::read($self)[$self] ?? null;

         yield assert(
            assertion: $row !== null
               && $row['ppid'] === posix_getppid()
               && $row['uid'] === posix_geteuid()
               && $row['ruid'] === posix_getuid()
               && $row['svuid'] === posix_geteuid()
               && $row['birth'] <= time()
               && str_starts_with($row['state'], 'Z') === false,
            description: 'the row of this process carries its parent, its effective, real and saved UIDs, a past birth and a live state — got ' . json_encode($row)
         );

         // # Birth, in UTC epoch seconds
         $before = time();
         $born = $spawn(static fn () => usleep(10000000));
         $after = time();
         $birth = Census::read($born)[$born]['birth'] ?? 0;

         yield assert(
            assertion: $born > 0 && $birth >= $before - 2 && $birth <= $after + 2,
            description: "a child born now reads as born now — the C-locale `lstart` parsed in UTC (birth {$birth}, now {$after})"
         );

         // # Refused lists: never a process group, never unbounded
         yield assert(
            assertion: Census::read() === []
               && Census::read(0) === []
               && Census::read(-1) === []
               && Census::read($self, 0) === []
               && Census::read($self, -1) === [],
            description: 'an empty list and any PID of 0 or below are refused whole'
         );
         // @ LIMIT distinct PIDs, this process among them: the one row sure
         //   to exist (a host may hide other accounts' processes, PID 1 too)
         $list = array_slice(array_values(array_diff(range(1, Census::LIMIT), [$self])), 0, Census::LIMIT - 1);
         $list[] = $self;

         yield assert(
            assertion: Census::read(...range(1, Census::LIMIT + 1)) === []
               && count(array_unique($list)) === Census::LIMIT
               && isSet(Census::read(...$list)[$self]),
            description: 'a list over LIMIT distinct PIDs is refused; LIMIT itself is read'
         );
         yield assert(
            assertion: array_keys(Census::read(...array_fill(0, Census::LIMIT + 10, $self))) === [$self],
            description: 'the limit counts distinct PIDs: duplicates read once'
         );

         // # Absent PIDs
         $reaped = pcntl_fork();
         if ($reaped === 0) {
            posix_kill(posix_getpid(), SIGKILL);
         }
         if ($reaped > 0) {
            pcntl_waitpid($reaped, $status);
         }

         yield assert(
            assertion: $reaped > 0 && Census::read($reaped) === [],
            description: 'a reaped child has no row'
         );
         yield assert(
            assertion: array_keys(Census::read($self, $reaped)) === [$self],
            description: 'a mixed list returns the rows of the live PIDs only'
         );

         // # A zombie: exited, not reaped
         $zombie = $spawn(static function (): void {});
         $state = '';
         $parent = 0;
         $deadline = microtime(true) + 2.0;
         // @@
         while ($zombie > 0 && microtime(true) < $deadline) {
            $zombieRow = Census::read($zombie)[$zombie] ?? null;
            $state = $zombieRow['state'] ?? '';
            $parent = $zombieRow['ppid'] ?? 0;
            if (str_starts_with($state, 'Z')) {
               break;
            }
            usleep(10000);
         }

         yield assert(
            assertion: str_starts_with($state, 'Z') && $parent === $self,
            description: "an unreaped child reads as a zombie of this process (state {$state})"
         );

         // # Pins: the system `ps` by absolute path, run without a shell
         yield assert(
            assertion: Census::BINARIES === ['/bin/ps', '/usr/bin/ps'],
            description: 'only the absolute system `ps` paths are ever run'
         );

         $tokens = token_get_all((string) file_get_contents((string) (new ReflectionClass(Census::class))->getFileName()));
         $significant = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => is_string($token)
               || ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT)
         ));
         $call = null;
         $shells = [];
         $guarded = false;
         foreach ($significant as $index => $token) {
            // ? The PID guard: `if ($PID <= 0) { return []; }`
            $window = array_map(
               static fn (array|string $piece): string => is_array($piece) ? $piece[1] : $piece,
               array_slice($significant, $index, 10)
            );
            if ($window === ['if', '(', '$PID', '<=', '0', ')', '{', 'return', '[', ']']) {
               $guarded = true;
            }
            if (is_array($token) === false || $token[0] !== T_STRING) {
               continue;
            }
            if (in_array($token[1], ['exec', 'shell_exec', 'system', 'passthru', 'popen'], true) && ($significant[$index + 1] ?? '') === '(') {
               $shells[] = $token[1];
            }
            // ? The proc_open call: its argument tokens, to the closing paren
            if ($token[1] === 'proc_open' && ($significant[$index + 1] ?? '') === '(') {
               $call = [];
               $depth = 0;
               for ($cursor = $index + 1; isSet($significant[$cursor]); $cursor++) {
                  $piece = $significant[$cursor];
                  $text = is_array($piece) ? $piece[1] : $piece;
                  $depth += match ($text) { '(', '[' => 1, ')', ']' => -1, default => 0 };
                  $call[] = $text;
                  if ($depth === 0) {
                     break;
                  }
               }
            }
         }
         $arguments = $call ?? [];

         yield assert(
            assertion: ($arguments[1] ?? '') === '[' && ($arguments[2] ?? '') === '$binary' && $shells === [],
            description: '`ps` runs as an argv array headed by the chosen absolute binary — no shell anywhere'
         );
         // ! procps refuses a list holding PID 0 whole by itself (`process ID
         //   out of range`), so the refusal above passes here either way; BSD
         //   `ps` lists PID 0 (Darwin's kernel_task). The guard is pinned for it.
         yield assert(
            assertion: $guarded,
            description: 'Census refuses any PID of 0 or below itself, before `ps` runs (`if ($PID <= 0) { return []; }`) — never a process group, nor Darwin\'s PID 0'
         );
         yield assert(
            assertion: count(array_keys($arguments, "'-o'", true)) === 7
               && array_filter($arguments, static fn (string $text): bool => str_contains($text, '=,')) === [],
            description: 'one `-o` per keyword: POSIX makes the rest of an `-o` argument after `=` its header'
         );
         yield assert(
            assertion: in_array("'LC_ALL'", $arguments, true) && in_array("'C'", $arguments, true)
               && in_array("'TZ'", $arguments, true) && in_array("'UTC'", $arguments, true),
            description: '`ps` runs in the C locale and UTC, so `lstart` parses the same everywhere'
         );
      }
      finally {
         foreach ($children as $PID) {
            if (pcntl_waitpid($PID, $status, WNOHANG) === 0) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
            }
         }
      }
   }
);
