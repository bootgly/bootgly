<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Resources\Cache as Storage;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Security\JWT\Cache;
use Bootgly\API\Security\JWT\Replay;
use Bootgly\API\Security\JWT\Token;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\JWT\Vault;


/*
 * Races run under a depth-first scheduler, one store op per step. The default
 * run explores, per race, every interleaving with at most 3 preemptions (a
 * switch away from a host that could still run): 2,220 schedules over 8
 * explorations, about 2 s — each natural mutant of Tokens measured fails
 * within 2 in some exploration.
 * BOOTGLY_DFS_EXHAUSTIVE=1 explores every interleaving: 269,280 schedules,
 * about 52 s (measured 2026-10-18) — for local runs.
 */
return new Test(
   description: 'JWT: a consumed refresh token stays detectable on every host of one store (interleavings with at most 3 preemptions; BOOTGLY_DFS_EXHAUSTIVE=1: all)',
   test: function () {
      $secret = bin2hex(random_bytes(32));
      // ! Interleavings explored per race: every schedule with at most this
      //   many preemptions (a switch away from a host that could still run);
      //   BOOTGLY_DFS_EXHAUSTIVE=1 explores every schedule (slow, local runs)
      $exhaustive = getenv('BOOTGLY_DFS_EXHAUSTIVE') === '1';
      $bound = $exhaustive ? PHP_INT_MAX : 3;
      $schedules = $exhaustive ? 'every interleaving' : "every interleaving with at most {$bound} preemptions";
      // ! The floor bound: each exploration must reach MORE schedules than
      //   the same race yields at this bound, counted in the same run — a
      //   floor relative to Tokens' store-op count (it grows and shrinks with
      //   it), so a bound lowered to it or a search that stops forking fails
      $floor = 2;
      $clean = static function (string $path): void {
         if (is_dir($path) === false) {
            return;
         }
         // ! The vault storage backend shards records into subdirectories
         $Iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Iterator as $Info) {
            $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
         }
         rmdir($path);
      };
      // ! The store keys Tokens derives (its index() is private)
      $index = static function (string $scope, string $value): string {
         $hash = hash('sha256', $value);

         return "jwt:refresh:{$scope}:{$hash}";
      };

      // ! What the store saw, shared by every host double: the scheduler
      //   flag, the injected faults and hooks, each operation and the
      //   absolute expiry of every successful write/claim
      $Recorder = new class {
         // * Data
         public bool $scheduled = false;
         /**
          * "op:scope" pairs the store refuses: `true` refuses every such op,
          * a list refuses (true) or serves (false) the next ones in order.
          *
          * @var array<string,bool|array<int,bool>>
          */
         public array $refusals = [];
         /** @var array<string,bool> scopes whose writes the store drops */
         public array $drops = [];
         /** @var array<string,Closure> "op:scope" => runs once, before that op */
         public array $Hooks = [];
         /** The store's clock for recorded expiries (null: the wall clock) */
         public null|int $clock = null;

         // * Metadata
         /** @var array<string,array<int,int>> */
         public array $expiries = [];
         /** @var array<int,array{host:string,op:string,key:string,ok:bool}> */
         public array $operations = [];


         public function reset (): void
         {
            $this->scheduled = false;
            $this->refusals = [];
            $this->drops = [];
            $this->Hooks = [];
            $this->clock = null;
            $this->expiries = [];
            $this->operations = [];
         }

         public function refuse (string $op, string $key): bool
         {
            $scope = explode(':', $key)[2] ?? '';
            $fault = "{$op}:{$scope}";
            $refusal = $this->refusals[$fault] ?? false;
            // ? A queue: one verdict per op, then the store serves
            if (is_array($refusal)) {
               $verdict = array_shift($refusal) ?? false;
               $this->refusals[$fault] = $refusal;

               return $verdict;
            }

            return $refusal;
         }

         public function drop (string $key): bool
         {
            $scope = explode(':', $key)[2] ?? '';

            return isset($this->drops[$scope]);
         }

         public function trigger (string $op, string $key): void
         {
            $scope = explode(':', $key)[2] ?? '';
            $Hook = $this->Hooks["{$op}:{$scope}"] ?? null;
            if ($Hook === null) {
               return;
            }

            // ! Once: the hook may run store ops of its own
            unset($this->Hooks["{$op}:{$scope}"]);
            $Hook();
         }

         public function log (string $host, string $op, string $key, bool $ok, int $ttl = 0): void
         {
            $this->operations[] = [
               'host' => $host,
               'op' => $op,
               'key' => $key,
               'ok' => $ok,
            ];
            if ($ok && $ttl > 0) {
               $this->expiries[$key][] = ($this->clock ?? time()) + $ttl;
            }
         }
      };

      // ! A "host": its own re-entrant lock no peer sees (a lock file on its
      //   own disk), every store op forwarded to ONE shared Vault — and,
      //   while the scheduler runs, a Fiber suspension before each op
      $host = static function (Vault $Vault, object $Recorder, string $name): Cache {
         return new class ($Vault, $Recorder, $name) implements Cache {
            // * Data
            private Vault $Vault;
            private object $Recorder;
            private string $name;

            // * Metadata
            private bool $locked = false;


            public function __construct (Vault $Vault, object $Recorder, string $name)
            {
               // * Data
               $this->Vault = $Vault;
               $this->Recorder = $Recorder;
               $this->name = $name;
            }

            public function lock (Closure $Closure): mixed
            {
               if ($this->locked) {
                  return $Closure();
               }

               $this->locked = true;
               try {
                  return $Closure();
               }
               finally {
                  $this->locked = false;
               }
            }

            public function read (string $key): null|string
            {
               $this->pause();
               $this->Recorder->trigger('read', $key);
               // ? A failed read reports a miss
               if ($this->Recorder->refuse('read', $key)) {
                  $this->Recorder->log($this->name, 'read', $key, false);

                  return null;
               }
               $value = $this->Vault->read($key);
               $this->Recorder->log($this->name, 'read', $key, $value !== null);

               return $value;
            }

            public function write (string $key, string $value, int $ttl): bool
            {
               $this->pause();
               $this->Recorder->trigger('write', $key);
               if ($this->Recorder->refuse('write', $key)) {
                  $this->Recorder->log($this->name, 'write', $key, false);

                  return false;
               }
               // ? A dropped write reports success and stores nothing
               if ($this->Recorder->drop($key)) {
                  $this->Recorder->log($this->name, 'write', $key, true);

                  return true;
               }

               $written = $this->Vault->write($key, $value, $ttl);
               $this->Recorder->log($this->name, 'write', $key, $written, $ttl);

               return $written;
            }

            public function claim (string $key, string $value, int $ttl): bool
            {
               $this->pause();
               $this->Recorder->trigger('claim', $key);
               if ($this->Recorder->refuse('claim', $key)) {
                  $this->Recorder->log($this->name, 'claim', $key, false);

                  return false;
               }

               $claimed = $this->Vault->claim($key, $value, $ttl);
               $this->Recorder->log($this->name, 'claim', $key, $claimed, $ttl);

               return $claimed;
            }

            public function take (string $key): null|string
            {
               $this->pause();
               $this->Recorder->trigger('take', $key);
               // ? A refused evict: the record stays, the caller sees a miss
               if ($this->Recorder->refuse('take', $key)) {
                  $this->Recorder->log($this->name, 'take', $key, false);

                  return null;
               }

               $value = $this->Vault->take($key);
               $this->Recorder->log($this->name, 'take', $key, $value !== null);

               return $value;
            }

            public function delete (string $key): bool
            {
               $this->pause();
               $this->Recorder->trigger('delete', $key);
               // ? A dropped delete reports success and deletes nothing
               if ($this->Recorder->drop($key)) {
                  $this->Recorder->log($this->name, 'delete', $key, true);

                  return true;
               }
               $deleted = $this->Vault->delete($key);
               $this->Recorder->log($this->name, 'delete', $key, $deleted);

               return $deleted;
            }

            public function purge (): bool
            {
               return $this->Vault->purge();
            }

            private function pause (): void
            {
               if ($this->Recorder->scheduled && Fiber::getCurrent() !== null) {
                  Fiber::suspend();
               }
            }
         };
      };

      // ! Active-record keys a host claimed (its successors)
      $claims = static function (object $Recorder, string $host = ''): array {
         $keys = [];
         foreach ($Recorder->operations as $operation) {
            if (
               $operation['op'] === 'claim'
               && $operation['ok']
               && ($host === '' || $operation['host'] === $host)
            ) {
               $keys[] = $operation['key'];
            }
         }

         return $keys;
      };
      // ! The family marker exists and outlives every readable active record
      //   of the family (the store keeps the TTL of the marker's last write)
      $cover = static function (Vault $Vault, string $family, array $keys) use ($index, $Recorder): null|string {
         $marker = $index('family', $family);
         if ($Vault->read($marker) === null) {
            return 'no family marker';
         }
         $expiries = $Recorder->expiries[$marker] ?? [];
         $covered = $expiries === [] ? null : $expiries[count($expiries) - 1];

         foreach ($keys as $key) {
            $value = $Vault->read($key);
            if ($value === null) {
               continue;
            }

            $Record = json_decode($value, true);
            if (is_array($Record) === false || ($Record['family'] ?? null) !== $family) {
               continue;
            }
            if ($covered === null || $Record['expires'] > $covered) {
               $gap = $Record['expires'] - ($covered ?? 0);

               return "a live token of the family outlives its marker by {$gap}s";
            }
         }

         return null;
      };
      // ! No claimed successor is readable
      $gone = static function (Vault $Vault, array $keys): bool {
         foreach ($keys as $key) {
            if ($Vault->read($key) !== null) {
               return false;
            }
         }

         return true;
      };

      // ! Two hosts, one store op per scheduler step
      $run = static function (Closure $Setup, array $plans, Closure $Choose, array $drops = []) use ($secret, $Recorder, $host): array {
         $Recorder->reset();
         $Vault = new Vault(new Storage(['driver' => 'memory']), secret: $secret);

         // ! One process drives every host: the Vault's own lock, held for
         //   the whole run, spares a lock-file round trip per store op (each
         //   host's exclusion is its double's lock)
         return $Vault->lock(static function () use ($Vault, $Setup, $plans, $Choose, $drops, $Recorder, $host): array {
            // ! One clock for every host of the run: replaying a schedule
            //   prefix must take the same branches (a second boundary between
            //   two issues would change ban()'s op count)
            $now = time();
            $context = $Setup((new Tokens($Vault))->freeze($now));
            $Recorder->reset();
            $Recorder->drops = $drops;

            $Results = ['A' => null, 'B' => null];
            $Fibers = [];
            foreach (['A', 'B'] as $name) {
               [$action, $ttl] = $plans[$name];
               $Tokens = (new Tokens($host($Vault, $Recorder, $name)))->freeze($now);
               $refresh = $context[$name];
               $Fibers[$name] = new Fiber(static function () use ($Tokens, $action, $ttl, $refresh, $name, &$Results): void {
                  try {
                     $Results[$name] = $action === 'revoke'
                        ? $Tokens->revoke($refresh)
                        : $Tokens->rotate($refresh, $ttl);
                  }
                  catch (Throwable $Throwable) {
                     $Results[$name] = $Throwable;
                  }
               });
            }

            $Recorder->scheduled = true;
            $Fibers['A']->start();
            $Fibers['B']->start();
            $path = '';
            $forks = [];
            // @@ One store op per step
            while ($Fibers['A']->isTerminated() === false || $Fibers['B']->isTerminated() === false) {
               $alive = [
                  'A' => $Fibers['A']->isTerminated() === false,
                  'B' => $Fibers['B']->isTerminated() === false,
               ];
               if ($alive['A'] && $alive['B']) {
                  $forks[] = strlen($path);
               }

               $who = $Choose(strlen($path), $alive, $path === '' ? '' : $path[-1]);
               // ? A replayed prefix must stay replayable
               if ($alive[$who] === false) {
                  throw new LogicException("Schedule {$path}{$who} picks a finished host: the run is not deterministic.");
               }
               $Fibers[$who]->resume();
               $path .= $who;
            }
            $Recorder->scheduled = false;

            return [
               'Vault' => $Vault,
               'context' => $context,
               'plans' => $plans,
               'Results' => $Results,
               'path' => $path,
               'forks' => $forks,
               'operations' => count($Recorder->operations),
            ];
         });
      };
      // ! The verdict every schedule must meet
      $judge = static function (array $Run) use ($cover, $claims, $Recorder): null|string {
         $Vault = $Run['Vault'];
         $context = $Run['context'];

         $reason = $cover($Vault, $context['family'], [...$context['known'], ...$claims($Recorder)]);
         if ($reason !== null) {
            return $reason;
         }

         $Observer = new Tokens($Vault);
         $replays = 0;
         $tokens = 0;
         foreach ($Run['Results'] as $name => $Result) {
            if ($Result instanceof Throwable) {
               return "{$name} threw: {$Result->getMessage()}";
            }
            if ($Run['plans'][$name][0] === 'revoke' && $Result !== true) {
               return "{$name}: revoke() did not return true";
            }
            if ($Result instanceof Replay) {
               $replays++;
            }
            if ($Result instanceof Token) {
               $tokens++;
               if ($Observer->check($Result->refresh)) {
                  return "{$name}: the returned Token is live";
               }
            }
         }

         if ($Run['plans']['A'][0] === 'rotate' && $Run['plans']['B'][0] === 'rotate') {
            if ($replays < 1) {
               return 'no Replay';
            }
            if ($tokens > 1) {
               return 'two Tokens';
            }
         }

         return null;
      };
      // ! Every schedule within a preemption limit, depth-first: a run
      //   follows its prefix, then keeps running the host that ran last; it
      //   forks the other host at every step where both could move, while
      //   that switch keeps the schedule within the limit
      $search = static function (Closure $Setup, array $plans, Closure $Verdict, array $drops, int $limit) use ($run): array {
         $count = 0;
         $failures = [];
         $prefixes = [['', 0]];
         // @@
         while ($prefixes !== []) {
            [$prefix, $preemptions] = array_pop($prefixes);
            $length = strlen($prefix);
            $Run = $run($Setup, $plans, static function (int $step, array $alive, string $last) use ($prefix, $length): string {
               if ($step < $length) {
                  return $prefix[$step];
               }

               return $last !== '' && $alive[$last] ? $last : ($alive['A'] ? 'A' : 'B');
            }, $drops);
            $count++;

            // ? Every store op is a scheduling point (a harness that stops
            //   pausing on some op would explore fewer interleavings in silence)
            $steps = strlen($Run['path']);
            $reason = $Run['operations'] === $steps
               ? $Verdict($Run)
               : "the schedule paused before {$steps} of {$Run['operations']} store ops";
            if ($reason !== null) {
               $failures[$Run['path']] = $reason;
            }

            foreach ($Run['forks'] as $step) {
               // ? Switching away from a host that could still run is a preemption
               $switches = $preemptions + ($step > 0 ? 1 : 0);
               if ($step >= $length && $switches <= $limit) {
                  $fork = substr($Run['path'], 0, $step);
                  $other = $Run['path'][$step] === 'A' ? 'B' : 'A';
                  $prefixes[] = ["{$fork}{$other}", $switches];
               }
            }
         }

         return [
            'count' => $count,
            'failures' => $failures,
         ];
      };
      // ! A race within the bound, judged; then the same race within the
      //   floor bound, counted only
      $explore = static function (Closure $Setup, array $plans, Closure $Verdict, array $drops = []) use ($search, $bound, $floor): array {
         $exploration = $search($Setup, $plans, $Verdict, $drops, $bound);
         $exploration['floor'] = $search($Setup, $plans, static fn (array $Run): null => null, $drops, $floor)['count'];

         return $exploration;
      };
      // ! An exploration passes with no failing schedule and more schedules
      //   than its floor (a count down to the floor is lost coverage)
      $pass = static function (array $exploration): bool {
         return $exploration['count'] > $exploration['floor'] && $exploration['failures'] === [];
      };
      $summarize = static function (array $exploration): string {
         if ($exploration['count'] > $exploration['floor'] && $exploration['failures'] === []) {
            return '';
         }

         $failed = count($exploration['failures']);
         $path = array_key_first($exploration['failures']);
         $reason = $path === null ? '' : "; first {$path}: {$exploration['failures'][$path]}";

         return " [{$exploration['count']} schedules (floor {$exploration['floor']}), {$failed} fail{$reason}]";
      };
      $list = static function (array $rows): string {
         $failed = array_keys(array_filter($rows, static fn (bool $row): bool => $row === false));
         $names = implode(', ', $failed);

         return $failed === [] ? '' : " [failed: {$names}]";
      };

      // ! Setups: one fresh token presented twice; a rotated family whose
      //   head (R2) and spent token (R1) are presented together
      $fresh = static function (Tokens $Tokens) use ($index): array {
         $R1 = $Tokens->mint('alice', 300, ['role' => 'admin']);

         return [
            'A' => $R1->refresh,
            'B' => $R1->refresh,
            'family' => $R1->family,
            'known' => [$index('active', $R1->refresh)],
         ];
      };
      $hop = static function (Tokens $Tokens) use ($index): array {
         $R1 = $Tokens->mint('alice', 300);
         $R2 = $Tokens->rotate($R1->refresh, 600);
         if ($R2 instanceof Token === false) {
            throw new RuntimeException('Setup rotation failed.');
         }

         return [
            'A' => $R2->refresh,
            'B' => $R1->refresh,
            'family' => $R1->family,
            'known' => [$index('active', $R1->refresh), $index('active', $R2->refresh)],
         ];
      };

      // @ 1. rotate(1200) || rotate(60): a double-submit whose winner's
      //   successor may outlive the token and the other successor
      $exploration = $explore($fresh, ['A' => ['rotate', 1200], 'B' => ['rotate', 60]], $judge);

      yield assert(
         assertion: $pass($exploration),
         description: "{$schedules} of a double-submit on two hosts yields a Replay, at most one Token and a family marker that outlives every successor{$summarize($exploration)}"
      );

      // @ 2. Multi-hop: the family head rotates while its spent ancestor is replayed / revoked
      $replayed = $explore($hop, ['A' => ['rotate', 1200], 'B' => ['rotate', 600]], $judge);
      $revoked = $explore($hop, ['A' => ['rotate', 1200], 'B' => ['revoke', 0]], $judge);

      yield assert(
         assertion: $pass($replayed) && $pass($revoked),
         description: "in {$schedules}, replaying or revoking a spent ancestor while the head rotates revokes the newest successor too{$summarize($replayed)}{$summarize($revoked)}"
      );

      // @ 3. rotate || revoke, both orders: the logout returns true, no
      //   successor stays live and the rotation's tombstone lives as long as
      //   any successor the store still holds
      $outlive = static function (array $Run) use ($judge, $claims, $index, $Recorder): null|string {
         $reason = $judge($Run);
         if ($reason !== null) {
            return $reason;
         }

         foreach ($claims($Recorder) as $key) {
            $value = $Run['Vault']->read($key);
            $Record = $value === null ? null : json_decode($value, true);
            if (is_array($Record) === false) {
               continue;
            }
            foreach ($Run['context']['spent'] ?? [$Run['context']['A']] as $refresh) {
               $tombstones = $Recorder->expiries[$index('spent', $refresh)] ?? [];
               if ($tombstones !== [] && $tombstones[count($tombstones) - 1] < $Record['expires']) {
                  $gap = $Record['expires'] - $tombstones[count($tombstones) - 1];

                  return "the presented token's tombstone expires {$gap}s before a stored successor";
               }
            }
         }

         return null;
      };
      $forward = $explore($fresh, ['A' => ['rotate', 600], 'B' => ['revoke', 0]], $outlive);
      $backward = $explore($fresh, ['A' => ['revoke', 0], 'B' => ['rotate', 600]], $outlive);

      yield assert(
         assertion: $pass($forward) && $pass($backward),
         description: "in {$schedules}, a logout racing a rotation on another host returns true, leaves no live successor and keeps the consumed token detectable as long as its successor{$summarize($forward)}{$summarize($backward)}"
      );

      // @ 4. A store that drops deletes: an orphan successor stays covered
      $forward = $explore($fresh, ['A' => ['rotate', 600], 'B' => ['revoke', 0]], $judge, ['active' => true]);
      $backward = $explore($fresh, ['A' => ['revoke', 0], 'B' => ['rotate', 600]], $judge, ['active' => true]);

      yield assert(
         assertion: $pass($forward) && $pass($backward),
         description: "in {$schedules}, a successor the store failed to delete under a racing logout never outlives the family marker{$summarize($forward)}{$summarize($backward)}"
      );

      // @ 5. A store that drops the tombstone: a loser that saw the token
      //   live still reports the replay and revokes the family
      $raced = 0;
      $dropped = $explore($fresh, ['A' => ['rotate', 600], 'B' => ['rotate', 600]], static function (array $Run) use ($judge, $Recorder, &$raced): null|string {
         // ? Only the schedules where both hosts read the token live
         $reads = [];
         foreach ($Recorder->operations as $operation) {
            if ($operation['op'] === 'read' && str_starts_with($operation['key'], 'jwt:refresh:active:')) {
               $reads[$operation['host']] ??= $operation['ok'];
            }
         }
         if (($reads['A'] ?? false) === false || ($reads['B'] ?? false) === false) {
            return null;
         }

         $raced++;

         return $judge($Run);
      }, ['spent' => true]);

      yield assert(
         assertion: $raced > 0 && $pass($dropped),
         description: "in {$schedules}, with the tombstone lost, a double-submit that raced past the read still yields a Replay and a family marker{$summarize($dropped)}"
      );

      // @ 6. The M12 schedule: A pauses right after its take() evicts the
      //   token; B runs to the end; then A finishes
      $late = static function (int $step, array $alive) use ($Recorder): string {
         $taken = false;
         foreach ($Recorder->operations as $operation) {
            if ($operation['host'] === 'A' && $operation['op'] === 'take' && $operation['ok']) {
               $taken = true;
            }
         }

         return $alive['A'] && ($taken === false || $alive['B'] === false) ? 'A' : 'B';
      };
      $Rotation = $run($fresh, ['A' => ['rotate', 600], 'B' => ['rotate', 600]], $late);
      $successors = $claims($Recorder, 'A');
      $reason = $judge($Rotation);
      $detail = $reason === null ? '' : " [{$reason}]";

      yield assert(
         assertion: $Rotation['Results']['B'] instanceof Replay
            && $reason === null
            && $successors !== []
            && $gone($Rotation['Vault'], $successors),
         description: "a late double-submit after the winner consumed the token is a Replay and the winner's successor is deleted{$detail}"
      );

      $Logout = $run($fresh, ['A' => ['rotate', 600], 'B' => ['revoke', 0]], $late);
      $successors = $claims($Recorder, 'A');
      $reason = $judge($Logout);
      $detail = $reason === null ? '' : " [{$reason}]";

      yield assert(
         assertion: $Logout['Results']['B'] === true
            && $reason === null
            && $successors !== []
            && $gone($Logout['Vault'], $successors),
         description: "a late logout after a rotation consumed the token returns true and the successor is deleted{$detail}"
      );

      // ! One host over a fresh store (sequential, faults injectable)
      $single = static function () use ($secret, $Recorder, $host): array {
         $Recorder->reset();
         $Vault = new Vault(new Storage(['driver' => 'memory']), secret: $secret);

         return [$Vault, new Tokens($host($Vault, $Recorder, 'A'))];
      };
      $attempt = static function (Closure $Closure): mixed {
         try {
            return $Closure();
         }
         catch (RuntimeException $Exception) {
            return $Exception;
         }
      };

      // @ 7. JWT-10: a refused store operation leaves the presented token valid
      // L1 — the successor claim is refused
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      $Recorder->refusals = ['claim:active' => true];
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));

      yield assert(
         assertion: $First instanceof RuntimeException
            && $Retry instanceof Token
            && $Vault->read($index('family', $R1->family)) === null,
         description: 'a refused successor claim throws and the retry rotates (no family revocation)'
      );

      // L2 — the tombstone write is refused
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      $Recorder->operations = [];
      $Recorder->refusals = ['write:spent' => true];
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $orphaned = $gone($Vault, $claims($Recorder)) === false;
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));

      yield assert(
         assertion: $First instanceof RuntimeException
            && $orphaned === false
            && $Retry instanceof Token
            && $Tokens->check($Retry->refresh),
         description: 'a refused tombstone write throws, deletes the unpublished successor and the retry rotates'
      );

      // L3 — the evict is refused (take() misses, the record stays)
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      $Recorder->operations = [];
      $Recorder->refusals = ['take:active' => true];
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $orphaned = $gone($Vault, $claims($Recorder)) === false;
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));

      yield assert(
         assertion: $First instanceof RuntimeException
            && str_contains($First->getMessage(), 'could not be consumed')
            && $orphaned === false
            && $Retry instanceof Token
            && $Vault->read($index('family', $R1->family)) === null,
         description: 'a refused evict throws "could not be consumed" (no false Replay) and the retry rotates'
      );

      // L4 — revoke() with the family marker write refused
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      $Recorder->refusals = ['write:family' => true];
      $First = $attempt(fn () => $Tokens->revoke($R1->refresh));
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->revoke($R1->refresh));

      yield assert(
         assertion: $First instanceof RuntimeException
            && $Retry === true
            && $Tokens->check($R1->refresh) === false,
         description: 'a logout whose family marker write is refused throws and the retry revokes'
      );

      // L5 — revoke() with the tombstone write refused
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      $Recorder->refusals = ['write:spent' => true];
      $First = $attempt(fn () => $Tokens->revoke($R1->refresh));
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->revoke($R1->refresh));

      yield assert(
         assertion: $First instanceof RuntimeException
            && $Retry === true
            && $Tokens->check($R1->refresh) === false,
         description: 'a logout whose tombstone write is refused throws and the retry still returns true'
      );

      // L6 — the family horizon write is refused after the token was consumed:
      //   the claimed successor is gone before any retry
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      // ! Only the rotation's claims: the mint's own claim is no successor
      $Recorder->operations = [];
      $Recorder->refusals = ['write:horizon' => true];
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $Successors = $claims($Recorder);
      $orphaned = $gone($Vault, $Successors) === false;
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $reason = $cover($Vault, $R1->family, [$index('active', $R1->refresh), ...$claims($Recorder)]);
      $detail = $reason === null ? '' : " [{$reason}]";

      yield assert(
         assertion: $First instanceof RuntimeException
            && $Successors !== []
            && $orphaned === false
            && $Retry instanceof Replay
            && $reason === null,
         description: "a refused horizon write after the election fails closed: no claimed successor is readable, the retry is a Replay and no successor stays live{$detail}"
      );

      // @ 8. A ban never lowers a revocation the store already holds: the
      //   marker carries the horizon it covers and survives a partial failure
      $marked = static function (string $family) use ($index, $Recorder): int {
         $expiries = $Recorder->expiries[$index('family', $family)] ?? [];

         return $expiries === [] ? 0 : $expiries[count($expiries) - 1];
      };
      // ! A stolen family: R1 → S1 → S2; the oldest token R1 gets replayed
      $stolen = static function () use ($single): array {
         [$Vault, $Tokens] = $single();
         $R1 = $Tokens->mint('alice', 60);
         $S1 = $Tokens->rotate($R1->refresh, 120);
         $S2 = $S1 instanceof Token ? $Tokens->rotate($S1->refresh, 3600) : null;
         if ($S2 instanceof Token === false) {
            throw new RuntimeException('Setup rotation failed.');
         }

         return [$Vault, $Tokens, $R1, $S2];
      };
      // ! The marker outlives S2, holds S2's expiry and S2 is dead
      $hold = static function (Vault $Vault, Tokens $Tokens, Token $R1, Token $S2) use ($index, $marked): bool {
         return $marked($R1->family) >= $S2->expires
            && $Vault->read($index('family', $R1->family)) === (string) $S2->expires
            && $Tokens->check($S2->refresh) === false;
      };

      // ! The marker's value now covers at least this horizon
      $covers = static function (Vault $Vault, string $family, int $horizon) use ($index): bool {
         $value = $Vault->read($index('family', $family));

         return $value !== null && (int) $value >= $horizon;
      };

      $rows = [];
      // V1 — the first ban: S2 rotates to S3 right before the ban's marker
      //   write, so the ban must extend the marker — and that second write
      //   is refused. The first write already covered S2 (the newest token
      //   it knew); the attempt throws and its retry covers S3
      [$Vault, $Tokens, $R1, $S2] = $stolen();
      $S3 = null;
      $Recorder->Hooks['write:family'] = static function () use ($Tokens, $S2, &$S3): void {
         $S3 = $Tokens->rotate($S2->refresh, 7200);
      };
      $Recorder->refusals = ['write:family' => [false, true]];
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $fired = $Recorder->refusals['write:family'] === [];
      $covered = $covers($Vault, $R1->family, $S2->expires);
      $Recorder->refusals = [];
      $Replay = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $rows['V1'] = $S3 instanceof Token
         && $First instanceof RuntimeException
         && $fired
         && $covered
         && $Replay instanceof Replay
         && $hold($Vault, $Tokens, $R1, $S3);

      // V2 — after a completed ban, the oldest token replayed again while a
      //   rotation in flight stores a newer horizon between the ban's marker
      //   write and its re-read; the extension write is refused. The marker
      //   never drops below the completed ban; the retry covers the horizon
      [$Vault, $Tokens, $R1, $S2] = $stolen();
      $Honest = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $horizon = $S2->expires + 3600;
      $Recorder->Hooks['write:family'] = static function () use ($Vault, $index, $R1, $horizon): void {
         $Vault->write($index('horizon', $R1->family), (string) $horizon, 7200);
      };
      $Recorder->refusals = ['write:family' => [false, true]];
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $fired = $Recorder->refusals['write:family'] === [];
      $covered = $covers($Vault, $R1->family, $S2->expires);
      $Recorder->refusals = [];
      $Replay = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $rows['V2'] = $Honest instanceof Replay
         && $First instanceof RuntimeException
         && $fired
         && $covered
         && $Replay instanceof Replay
         && $Vault->read($index('family', $R1->family)) === (string) $horizon
         && $marked($R1->family) >= $horizon
         && $Tokens->check($S2->refresh) === false;

      // V3 — after a completed ban, the oldest token replayed again while
      //   every horizon key read fails silently (a miss)
      [$Vault, $Tokens, $R1, $S2] = $stolen();
      $Honest = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $Recorder->refusals = ['read:horizon' => true];
      $Replay = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $Recorder->refusals = [];
      $rows['V3'] = $Honest instanceof Replay
         && $Replay instanceof Replay
         && $hold($Vault, $Tokens, $R1, $S2);

      // V4 — a logout of the head after a replay banned the family past it:
      //   the logout's shorter horizon (the head's, which the horizon key
      //   also holds) never lowers the marker
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 3600);
      $S1 = $Tokens->rotate($R1->refresh, 60);
      $Replay = $attempt(fn () => $Tokens->rotate($R1->refresh, 60));
      $revoked = $S1 instanceof Token ? $attempt(fn () => $Tokens->revoke($S1->refresh)) : null;
      $rows['V4'] = $Replay instanceof Replay
         && $revoked === true
         && $Vault->read($index('family', $R1->family)) === (string) $R1->expires
         && $marked($R1->family) >= $R1->expires;

      // V5 — a marker below the horizon key (an earlier ban that could not
      //   extend it): a replay covers the newest token with its first write,
      //   so a refused second write changes nothing
      [$Vault, $Tokens, $R1, $S2] = $stolen();
      $Vault->write($index('family', $R1->family), (string) ($R1->expires + 60), 180);
      $Recorder->refusals = ['write:family' => [false, true]];
      $Replay = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $Recorder->refusals = [];
      $rows['V5'] = $Replay instanceof Replay
         && $hold($Vault, $Tokens, $R1, $S2);

      // V6 — a ban whose first write already covers the newest token writes
      //   the marker once: a refusal queued for a second write never fires
      [$Vault, $Tokens, $R1, $S2] = $stolen();
      $Recorder->refusals = ['write:family' => [false, true]];
      $Replay = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $unfired = $Recorder->refusals['write:family'] === [true];
      $Recorder->refusals = [];
      $rows['V6'] = $Replay instanceof Replay
         && $unfired
         && $hold($Vault, $Tokens, $R1, $S2);

      yield assert(
         assertion: $list($rows) === '',
         description: "a ban never writes the family marker below a horizon the store already holds (an earlier marker or the horizon key), even when its extension write is refused or the horizon key cannot be read{$list($rows)}"
      );

      // @ 9. A token that expires during its own rotation is an expired token
      //   (null), not a Replay
      // E1 — one host: the store expires the token between the read and the take
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 5);
      // ! Only the rotation's claims: the mint's own claim is no successor
      $Recorder->operations = [];
      $expired = false;
      $Recorder->Hooks['take:active'] = static function () use ($Vault, $Tokens, $R1, $index, &$expired): void {
         $Vault->delete($index('active', $R1->refresh));
         $Tokens->freeze($R1->expires);
         $expired = true;
      };
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $Successors = $claims($Recorder);
      $tombstone = $Vault->read($index('spent', $R1->refresh));
      $Again = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $revoked = $attempt(fn () => $Tokens->revoke($R1->refresh));

      yield assert(
         assertion: $expired
            && $First === null
            && $Successors !== []
            && $gone($Vault, $Successors)
            && $tombstone === null
            && $Again === null
            && $revoked === false
            && $marked($R1->family) >= max(array_map(static fn (string $key): int => $Recorder->expiries[$key][0] ?? PHP_INT_MAX, $Successors)),
         description: 'a token the store expires between its read and its take rotates to null, leaves no successor, no tombstone that would make a later presentation a Replay and a family marker that outlives every successor it claimed'
      );

      // E2 — two hosts: another host consumed the token in its last instant
      [$Vault, $Tokens] = $single();
      $Other = new Tokens($host($Vault, $Recorder, 'B'));
      $R1 = $Tokens->mint('alice', 5);
      $Won = null;
      $Recorder->Hooks['take:active'] = static function () use ($Other, $Tokens, $R1, &$Won): void {
         $Won = $Other->rotate($R1->refresh, 600);
         $Tokens->freeze($R1->expires);
      };
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $reason = $cover($Vault, $R1->family, $claims($Recorder));
      $detail = $reason === null ? '' : " [{$reason}]";

      yield assert(
         assertion: $Won instanceof Token
            && $First === null
            && $Tokens->check($Won->refresh) === false
            && $reason === null,
         description: "a rotation that finds its token consumed by another host and then expired returns null and revokes the family: the other host's successor is dead{$detail}"
      );

      // E3 — E2 with the ban's marker write refused: the attempt throws and
      //   keeps the token's tombstone (deleting it before the ban would leave
      //   neither the tombstone nor the marker), so the retry is a Replay
      //   and the other host's successor dies
      [$Vault, $Tokens] = $single();
      $Other = new Tokens($host($Vault, $Recorder, 'B'));
      $R1 = $Tokens->mint('alice', 5);
      $Won = null;
      $Recorder->Hooks['take:active'] = static function () use ($Other, $Tokens, $R1, $Recorder, &$Won): void {
         $Won = $Other->rotate($R1->refresh, 600);
         $Tokens->freeze($R1->expires);
         $Recorder->refusals = ['write:family' => [true]];
      };
      $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $fired = ($Recorder->refusals['write:family'] ?? null) === [];
      $tombstone = $Vault->read($index('spent', $R1->refresh));
      $Recorder->refusals = [];
      $Retry = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
      $dead = $Won instanceof Token && $Tokens->check($Won->refresh) === false;
      $Third = $attempt(fn () => (new Tokens($host($Vault, $Recorder, 'C')))->rotate($R1->refresh, 600));
      $reason = $cover($Vault, $R1->family, $claims($Recorder));
      $detail = $reason === null ? '' : " [{$reason}]";

      yield assert(
         assertion: $Won instanceof Token
            && $First instanceof RuntimeException
            && $fired
            && $tombstone !== null
            && $Retry instanceof Replay
            && $dead
            && $Third instanceof Replay
            && $reason === null,
         description: "an expired-during-rotation ban that the store refuses throws and keeps the tombstone: the retry and any other host see a Replay and the other host's successor is dead{$detail}"
      );

      // E4 — the rotating host's clock lags the store's (the minting host's)
      //   at the expiry edge: within the tolerated skew (60 s) an expiry is
      //   still an expiry — null and no tombstone left; beyond it the edge
      //   expiry reads as a consumption — a Replay (the family dies either way)
      $rows = [];
      foreach ([1, 59, 60, 61] as $lag) {
         [$Vault, $Tokens] = $single();
         $now = time();
         $R1 = (new Tokens($Vault))->freeze($now)->mint('alice', 5);
         $Tokens->freeze($now - $lag);
         $expired = false;
         $Recorder->Hooks['take:active'] = static function () use ($Vault, $Tokens, $R1, $index, $lag, &$expired): void {
            // ! The store's TTL fires on its clock; this host's lags behind
            $Vault->delete($index('active', $R1->refresh));
            $Tokens->freeze($R1->expires - $lag);
            $expired = true;
         };
         $First = $attempt(fn () => $Tokens->rotate($R1->refresh, 600));
         $tombstone = $Vault->read($index('spent', $R1->refresh)) !== null;
         $banned = $Vault->read($index('family', $R1->family)) !== null;
         $Again = $attempt(fn () => $Tokens->freeze($R1->expires + 10)->rotate($R1->refresh, 600));
         $rows["{$lag} s"] = $expired
            && $banned
            && ($lag <= 60
               ? $First === null && $tombstone === false && $Again === null
               : $First instanceof Replay && $tombstone && $Again instanceof Replay);
      }

      yield assert(
         assertion: $list($rows) === '',
         description: "a host whose clock lags the store's by up to 60 s rotates a token expiring under it to null with no tombstone left; beyond 60 s that expiry reads as a Replay (the family is revoked either way){$list($rows)}"
      );

      // @ 10. JWT-4: the revocation lives as long as the family's newest token
      $rows = [];
      // J1 — single hop
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 3600);
      $Replay = $Tokens->rotate($R1->refresh, 3600);
      $rows['J1'] = $R2 instanceof Token
         && $Replay instanceof Replay
         && $marked($R1->family) >= $R2->expires;

      // J2 — multi-hop: replaying R1 revokes up to R3
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 600);
      $R3 = $R2 instanceof Token ? $Tokens->rotate($R2->refresh, 3600) : null;
      $Replay = $Tokens->rotate($R1->refresh, 600);
      $rows['J2'] = $R3 instanceof Token
         && $Replay instanceof Replay
         && $marked($R1->family) >= $R3->expires;

      // J3 — multi-hop: revoking the spent R1 revokes up to R3
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 600);
      $R3 = $R2 instanceof Token ? $Tokens->rotate($R2->refresh, 3600) : null;
      $revoked = $Tokens->revoke($R1->refresh);
      $rows['J3'] = $R3 instanceof Token
         && $revoked === true
         && $marked($R1->family) >= $R3->expires
         && $Tokens->check($R3->refresh) === false;

      // J4 — the horizon key is lost: the tombstone's horizon still covers R2
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 3600);
      $Vault->delete($index('horizon', $R1->family));
      $Replay = $Tokens->rotate($R1->refresh, 600);
      $rows['J4'] = $R2 instanceof Token
         && $Replay instanceof Replay
         && $marked($R1->family) >= $R2->expires;

      // J5 — the tombstone lives as long as the successor
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 3600);
      $tombstones = $Recorder->expiries[$index('spent', $R1->refresh)] ?? [0];
      $rows['J5'] = $R2 instanceof Token
         && $tombstones[count($tombstones) - 1] >= $R2->expires;

      // J6 — clock skew: a host whose clock runs 60 s ahead (the tolerated
      //   skew) replays R1 while another host rotates R2 → R3 between its
      //   ban's reads; the marker's extension still covers R3 (the slack).
      //   The store and the other host share one frozen clock: no wall-clock
      //   second between the setup and the write can pad the margin
      [$Vault, $Tokens] = $single();
      $now = time();
      $Recorder->clock = $now;
      $Tokens->freeze($now);
      $Ahead = (new Tokens($host($Vault, $Recorder, 'B')))->freeze($now + 60);
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 600);
      $R3 = null;
      $Recorder->Hooks['write:family'] = static function () use ($Tokens, $R2, &$R3): void {
         $R3 = $R2 instanceof Token ? $Tokens->rotate($R2->refresh, 3600) : null;
      };
      $Replay = $Ahead->rotate($R1->refresh, 3600);
      $writes = 0;
      foreach ($Recorder->operations as $operation) {
         $writes += $operation['host'] === 'B'
            && $operation['op'] === 'write'
            && $operation['key'] === $index('family', $R1->family)
            && $operation['ok'] ? 1 : 0;
      }
      $rows['J6'] = $R3 instanceof Token
         && $Replay instanceof Replay
         && $writes === 2
         && $marked($R1->family) >= $R3->expires
         && $Tokens->check($R3->refresh) === false;

      // J7 — a successor shorter than the consumed token: the tombstone
      //   lives as long as the consumed token
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 300);
      $R2 = $Tokens->rotate($R1->refresh, 60);
      $tombstones = $Recorder->expiries[$index('spent', $R1->refresh)] ?? [0];
      $rows['J7'] = $R2 instanceof Token
         && $tombstones[count($tombstones) - 1] >= $R1->expires;

      // J8 — a successor longer than the consumed token: the family horizon
      //   key lives as long as the successor
      [$Vault, $Tokens] = $single();
      $R1 = $Tokens->mint('alice', 60);
      $R2 = $Tokens->rotate($R1->refresh, 3600);
      $horizons = $Recorder->expiries[$index('horizon', $R1->family)] ?? [0];
      $rows['J8'] = $R2 instanceof Token
         && $horizons[count($horizons) - 1] >= $R2->expires;

      yield assert(
         assertion: $list($rows) === '',
         description: "family revocation and tombstones are sized to the newest token of the family{$list($rows)}"
      );

      // @ 11. Real time: the revocation outlives the replayed token's expiry
      $path = Temporaries::reserve('jwt-tombstone');
      try {
         $Tokens = new Tokens(new Vault($path));
         // ! Start at the top of a second so the 1 s token survives its rotation
         usleep((int) ((1 - fmod(microtime(true), 1)) * 1000000) + 20000);
         $R1 = $Tokens->mint('alice', 1);
         $R2 = $Tokens->rotate($R1->refresh, 6);
         $Replay = $Tokens->rotate($R1->refresh, 6);
         // ! Past R1's expiry (and the expiry a tombstone sized to R1 would have)
         usleep(max(0, (int) (($R1->expires - microtime(true)) * 1000000)) + 50000);
         $live = $R2 instanceof Token && $Tokens->check($R2->refresh);
         $Again = $Tokens->rotate($R1->refresh, 6);
      }
      finally {
         $clean($path);
      }

      yield assert(
         assertion: $R2 instanceof Token
            && $Replay instanceof Replay
            && $live === false
            && $Again instanceof Replay,
         description: 'after the replayed token expires its successor stays revoked and a second replay is still detected'
      );

      // @ 12. Mixed versions: records written by an earlier release
      $rows = [];
      [$Vault, $Tokens] = $single();
      // ! A legacy tombstone carries no horizon
      $legacy = bin2hex(random_bytes(16));
      $family = bin2hex(random_bytes(16));
      $expires = time() + 300;
      $Vault->write($index('spent', $legacy), json_encode([
         'subject' => 'alice',
         'family' => $family,
         'claims' => ['role' => 'admin'],
         'expires' => $expires,
      ], JSON_THROW_ON_ERROR), 300);
      $Replay = $Tokens->rotate($legacy, 600);
      $rows['replay'] = $Replay instanceof Replay
         && $Replay->family === $family
         && $Replay->expires === $expires
         && $marked($family) >= $expires;

      // ! A legacy active record whose family has no horizon key
      $legacy = bin2hex(random_bytes(16));
      $family = bin2hex(random_bytes(16));
      $Vault->write($index('active', $legacy), json_encode([
         'subject' => 'alice',
         'family' => $family,
         'claims' => [],
         'issued' => time(),
         'expires' => $expires,
      ], JSON_THROW_ON_ERROR), 300);
      $rows['revoke'] = $Tokens->revoke($legacy) === true
         && $Tokens->check($legacy) === false
         && $marked($family) >= $expires;

      // ! A rolling deploy: an earlier host rotated R1 (legacy tombstone, no
      //   horizon key), this release rotated R2 → R3; replaying R1 revokes R3
      $legacy = bin2hex(random_bytes(16));
      $R2 = $Tokens->mint('alice', 120);
      $Vault->write($index('spent', $legacy), json_encode([
         'subject' => 'alice',
         'family' => $R2->family,
         'claims' => [],
         'expires' => time() + 60,
      ], JSON_THROW_ON_ERROR), 60);
      $R3 = $Tokens->rotate($R2->refresh, 3600);
      $Replay = $Tokens->rotate($legacy, 600);
      $rows['chain'] = $R3 instanceof Token
         && $Replay instanceof Replay
         && $marked($R2->family) >= $R3->expires
         && $Tokens->check($R3->refresh) === false;

      yield assert(
         assertion: $list($rows) === '',
         description: "tombstones without a horizon and families without a horizon key still revoke correctly{$list($rows)}"
      );

      // @ 13. Single-host parity over one real Vault (same outcomes as before)
      $path = Temporaries::reserve('jwt-tombstone');
      $rows = [];
      try {
         $Tokens = new Tokens(new Vault($path));
         $R1 = $Tokens->mint('alice', 300, ['role' => 'admin']);
         $R2 = $Tokens->rotate($R1->refresh, 300);
         $rows['rotate'] = $R2 instanceof Token
            && $R2->subject === 'alice'
            && $R2->family === $R1->family
            && $R2->claims === ['role' => 'admin'];
         $rows['check'] = $Tokens->check($R1->refresh) === false
            && $R2 instanceof Token
            && $Tokens->check($R2->refresh) === true;
         $Replay = $Tokens->rotate($R1->refresh, 300);
         $rows['replay'] = $Replay instanceof Replay
            && $Replay->subject === 'alice'
            && $Replay->claims === ['role' => 'admin']
            && $Replay->expires === $R1->expires;
         $Head = $R2 instanceof Token ? $R2->refresh : '';
         $rows['revoked'] = $Tokens->rotate($Head, 300) === null
            && $Tokens->rotate($Head, 300) === null
            && $Tokens->check($Head) === false;
         $R4 = $Tokens->mint('bob', 300);
         $rows['revoke'] = $Tokens->revoke($R4->refresh) === true
            && $Tokens->revoke($R4->refresh) === true
            && $Tokens->check($R4->refresh) === false;
         $rows['after'] = $Tokens->rotate($R4->refresh, 300) instanceof Replay;
         $rows['unknown'] = $Tokens->rotate('unknown-refresh-token', 300) === null
            && $Tokens->revoke('unknown-refresh-token') === false
            && $Tokens->check('unknown-refresh-token') === false;
      }
      finally {
         $clean($path);
      }

      yield assert(
         assertion: $list($rows) === '',
         description: "single-host rotate, replay, revoke and unknown-token outcomes are unchanged{$list($rows)}"
      );
   }
);
