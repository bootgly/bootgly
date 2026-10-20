<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\AuthorizationRBACCacheGeneration;


use function array_keys;
use function array_merge;
use function assert;
use function copy;
use function count;
use function extension_loaded;
use function gc_collect_cycles;
use function get_class;
use function implode;
use function is_dir;
use function random_int;
use function rmdir;
use function str_contains;
use function unlink;
use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SQLite3;
use Throwable;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\API\Security\Authorization\RBAC;
use Bootgly\API\Security\Identity;


/**
 * A real SQLite database whose await() runs a one-shot hook after the rows
 * are read — inside the window between a check's database read and its
 * cache store.
 */
class HookedSQL extends SQL
{
   // * Data
   public null|Closure $Hook = null;

   // * Metadata
   public private(set) int $queries = 0;


   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = [], null|object $Scope = null): SQLOperation
   {
      $this->queries++;

      return parent::query($query, $parameters, $Scope);
   }

   public function await (SQLOperation $Operation): SQLOperation
   {
      $Operation = parent::await($Operation);

      // @ The window: the rows are read, the caller has not stored them yet
      $Hook = $this->Hook;
      if ($Hook !== null) {
         $this->Hook = null;
         $Hook();
      }

      return $Operation;
   }
}


/**
 * A cache that can refuse writes — every key, or the keys holding a fragment
 * — while it still reads, and (unless told otherwise) still deletes: a full
 * disk returns `false`, Redis at maxmemory under noeviction throws. It can
 * also fail reads of some keys (an unreachable backend throws).
 */
class RefusingCache extends Cache
{
   // * Data
   /**
    * Key fragments whose writes are refused (`''` refuses every key).
    *
    * @var array<int,string>
    */
   public array $refused = [];
   /** Whether a refused key can still be deleted. */
   public bool $deletes = true;
   /** Whether a refusal throws (the Redis shape) instead of returning `false`. */
   public bool $throws = false;
   /**
    * Key fragments whose reads throw.
    *
    * @var array<int,string>
    */
   public array $unreadable = [];

   // * Metadata
   /**
    * Keys written so far.
    *
    * @var array<int,string>
    */
   public private(set) array $stored = [];


   /**
    * @param array<int,string> $tags
    */
   public function fetch (string $key): mixed
   {
      // ?
      foreach ($this->unreadable as $fragment) {
         if (str_contains($key, $fragment)) {
            throw new RuntimeException('Cache read failed.');
         }
      }

      // :
      return parent::fetch($key);
   }

   public function store (string $key, mixed $value, int $TTL = 0, array $tags = []): bool
   {
      // ?
      if ($this->match($key)) {
         return $this->throws
            ? throw new RuntimeException('OOM command not allowed when used memory > maxmemory.')
            : false;
      }

      $this->stored[] = $key;

      // :
      return parent::store($key, $value, $TTL, $tags);
   }

   public function delete (string $key): bool
   {
      // ?
      if ($this->deletes === false && $this->match($key)) {
         return $this->throws ? throw new RuntimeException('Cache delete failed.') : false;
      }

      // :
      return parent::delete($key);
   }

   public function invalidate (string $tag): bool
   {
      // ? Every key refused, deletes included
      if ($this->deletes === false && $this->match('')) {
         return $this->throws ? throw new RuntimeException('Cache invalidate failed.') : false;
      }

      // :
      return parent::invalidate($tag);
   }

   /**
    * Match a key against the refused fragments.
    */
   private function match (string $key): bool
   {
      foreach ($this->refused as $fragment) {
         if ($fragment === '' || str_contains($key, $fragment)) {
            return true;
         }
      }

      return false;
   }
}


/*
 * Every leg runs on a fresh SQLite file under each cache driver: a reader RBAC
 * (the login) and an admin RBAC (another worker: its own SQL object and, on
 * the file and shared drivers, its own Cache object over one store; the
 * memory driver is per process, so both share one Cache). The revoke is
 * committed through a native SQLite3 connection.
 */
return new Test(
   description: 'Authorization: an RBAC invalidate() that lands while a check reads the database is never undone by that check (file, memory, shared caches)',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $shared = extension_loaded('sysvshm') && extension_loaded('sysvsem');
      $base = Temporaries::reserve('rbac-generation');
      /** @var array<int,SQL> $Databases */
      $Databases = [];
      /** @var array<int,Cache> $Segments */
      $Segments = [];
      /** @var array<string,array<string,true|string>> $rows leg => driver => verdict */
      $rows = [];

      // ! One fixture database: u-42 holds invoices:read and invoices:delete
      $sequence = 0;
      $fixture = static function () use ($base, &$sequence): string {
         $sequence++;
         $path = "{$base}/rbac-{$sequence}.sqlite";
         $Native = new SQLite3($path);
         $Native->busyTimeout(5000);
         $Native->exec('PRAGMA journal_mode=WAL');
         $Native->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
         $Native->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY, name TEXT)');
         $Native->exec('CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER)');
         $Native->exec('CREATE TABLE user_roles (user_id TEXT, role_id INTEGER)');
         $Native->exec("INSERT INTO roles (id, name) VALUES (1, 'staff')");
         $Native->exec("INSERT INTO permissions (id, name) VALUES (1, 'invoices:read'), (2, 'invoices:delete')");
         $Native->exec('INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 1), (1, 2)');
         $Native->exec("INSERT INTO user_roles (user_id, role_id) VALUES ('u-42', 1)");
         $Native->close();

         return $path;
      };
      // ! A committed write through a connection RBAC does not own
      $native = static function (string $path, string $SQL): void {
         $Native = new SQLite3($path);
         $Native->busyTimeout(5000);
         $Native->exec($SQL);
         $Native->close();
      };
      $revoke = static function (string $path) use ($native): void {
         $native(
            $path,
            "DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE name = 'invoices:delete')"
         );
      };
      $connect = static function (string $path, string $replica = '') use (&$Databases): HookedSQL {
         $config = [
            'driver' => 'sqlite',
            'database' => $path,
            'timeout' => 5.0,
            'statements' => 0,
            'pool' => ['min' => 0, 'max' => 1],
         ];
         if ($replica !== '') {
            $config['replicas'] = [['driver' => 'sqlite', 'host' => 'replica', 'database' => $replica]];
         }
         $Database = new HookedSQL($config);
         $Databases[] = $Database;

         return $Database;
      };
      // ! The reader's and the admin's caches over one store
      $caches = static function (string $driver) use ($base, &$sequence, &$Segments): array {
         $sequence++;
         switch ($driver) {
            case 'file':
               $config = ['driver' => 'file', 'path' => "{$base}/cache-{$sequence}"];

               return [new RefusingCache($config), new RefusingCache($config)];
            case 'memory':
               $Cache = new RefusingCache(['driver' => 'memory']);

               return [$Cache, $Cache];
            default:
               $config = [
                  'driver' => 'shared',
                  'segment' => random_int(200_000, 9_000_000),
                  'size' => 262_144,
               ];
               $Cache = new RefusingCache($config);
               $Cache->clear();
               $Segments[] = $Cache;

               return [$Cache, new RefusingCache($config)];
         }
      };
      // ! One leg's world: a fresh database and a fresh store
      $world = static function (string $driver, string $replica = '') use ($fixture, $connect, $caches): array {
         $path = $fixture();
         if ($replica !== '') {
            copy($path, $replica);
         }
         [$ReaderCache, $AdminCache] = $caches($driver);
         $ReaderSQL = $connect($path, $replica);
         $Reader = new RBAC($ReaderSQL, Cache: $ReaderCache, lifetime: 300);
         $Admin = new RBAC($connect($path, $replica), Cache: $AdminCache, lifetime: 300);

         return [$path, $ReaderSQL, $Reader, $Admin, $AdminCache, $ReaderCache];
      };
      $record = static function (string $leg, string $driver, bool $passed, string $evidence) use (&$rows): void {
         $rows[$leg][$driver] = $passed ? true : $evidence;
      };
      $list = static function (string $leg) use (&$rows): string {
         $failures = [];
         foreach ($rows[$leg] ?? [] as $driver => $verdict) {
            if ($verdict !== true) {
               $failures[] = "{$driver}: {$verdict}";
            }
         }

         $failed = implode('; ', $failures);

         return $failures === [] ? '' : " — FAILED {$leg}: {$failed}";
      };
      $bool = static fn (mixed $value): string => $value === true ? 'true' : ($value === false ? 'false' : 'n/a');
      $threw = static function (Throwable $Throwable): string {
         $class = get_class($Throwable);

         return "threw {$class}: {$Throwable->getMessage()}";
      };

      $drivers = $shared ? ['file', 'memory', 'shared'] : ['file', 'memory'];

      try {
         foreach ($drivers as $driver) {
            // @ L1 + L5 (per identity) and L2 + L5 (global): a cold check whose
            //   database read lands before a revoke + invalidate() that both
            //   finish inside its window
            foreach (['L1' => true, 'L2' => false] as $leg => $identity) {
               try {
                  [$path, $ReaderSQL, $Reader, $Admin] = $world($driver);
                  $Subject = new Identity(id: 'u-42');
                  $ReaderSQL->Hook = static function () use ($revoke, $path, $Admin, $Subject, $identity): void {
                     $revoke($path);
                     $identity ? $Admin->invalidate($Subject) : $Admin->invalidate();
                  };

                  $first = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $before = $ReaderSQL->queries;
                  $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $requeries = $ReaderSQL->queries - $before;
                  $record(
                     $leg,
                     $driver,
                     $first === true && $after === false && $requeries === 1,
                     "first={$bool($first)} after={$bool($after)} requeries={$requeries}"
                  );

                  // @ L5: the recovered list is cached again — a hit with 0 queries
                  $before = $ReaderSQL->queries;
                  $denied = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $read = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
                  $queries = $ReaderSQL->queries - $before;
                  $record(
                     "L5{$leg}",
                     $driver,
                     $denied === false && $read === true && $queries === 0,
                     "delete={$bool($denied)} read={$bool($read)} queries={$queries}"
                  );
               }
               catch (Throwable $Throwable) {
                  $record($leg, $driver, false, $threw($Throwable));
                  $record("L5{$leg}", $driver, false, 'not reached');
               }
            }

            // @ L3: the reload shape — a warm list, an invalidate(), then the
            //   re-query's window takes the revoke + invalidate()
            foreach (['L3 identity' => true, 'L3 global' => false] as $leg => $identity) {
               try {
                  [$path, $ReaderSQL, $Reader, $Admin] = $world($driver);
                  $Subject = new Identity(id: 'u-42');
                  $warm = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete')
                     && $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $warmed = $ReaderSQL->queries;
                  $identity ? $Admin->invalidate($Subject) : $Admin->invalidate();
                  $ReaderSQL->Hook = static function () use ($revoke, $path, $Admin, $Subject, $identity): void {
                     $revoke($path);
                     $identity ? $Admin->invalidate($Subject) : $Admin->invalidate();
                  };

                  $reload = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $before = $ReaderSQL->queries;
                  $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $requeries = $ReaderSQL->queries - $before;
                  $record(
                     $leg,
                     $driver,
                     $warm && $warmed === 1 && $reload === true && $after === false && $requeries === 1,
                     "warm={$bool($warm)} warmQueries={$warmed} reload={$bool($reload)} after={$bool($after)} requeries={$requeries}"
                  );
               }
               catch (Throwable $Throwable) {
                  $record($leg, $driver, false, $threw($Throwable));
               }
            }

            // @ L4: the generation stored by the invalidate() is lost inside the
            //   window (evicted, expired) — a lost generation only costs misses
            foreach (['L4 identity' => true, 'L4 global' => false] as $leg => $identity) {
               try {
                  [$path, $ReaderSQL, $Reader, $Admin, $AdminCache] = $world($driver);
                  $Subject = new Identity(id: 'u-42');
                  $realm = $Admin->realm;
                  $ReaderSQL->Hook = static function () use ($revoke, $path, $Admin, $AdminCache, $Subject, $identity, $realm): void {
                     $revoke($path);
                     if ($identity) {
                        $Admin->invalidate($Subject);
                        $AdminCache->delete("rbac:v2:{$realm}:s:u-42");
                     }
                     else {
                        $Admin->invalidate();
                        $AdminCache->delete("rbac:v2:{$realm}:gen");
                     }
                  };

                  $first = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $read = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
                  $record(
                     $leg,
                     $driver,
                     $first === true && $after === false && $read === true,
                     "first={$bool($first)} after={$bool($after)} read={$bool($read)}"
                  );
               }
               catch (Throwable $Throwable) {
                  $record($leg, $driver, false, $threw($Throwable));
               }
            }

            // @ L6: a database failure denies, is not cached, and does not
            //   resurrect the list it replaced
            try {
               [$path, $ReaderSQL, $Reader, $Admin] = $world($driver);
               $Subject = new Identity(id: 'u-42');
               $warm = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
               $Admin->invalidate($Subject);
               $native($path, 'ALTER TABLE user_roles RENAME TO user_roles_offline');

               $failed = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
               $native($path, 'ALTER TABLE user_roles_offline RENAME TO user_roles');
               $before = $ReaderSQL->queries;
               $recovered = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
               $requeries = $ReaderSQL->queries - $before;
               $before = $ReaderSQL->queries;
               $cached = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
               $hits = $ReaderSQL->queries - $before;
               $record(
                  'L6',
                  $driver,
                  $warm === true && $failed === false && $recovered === true && $requeries === 1
                     && $cached === true && $hits === 0,
                  "warm={$bool($warm)} failed={$bool($failed)} recovered={$bool($recovered)} requeries={$requeries} cached={$bool($cached)} queries={$hits}"
               );
            }
            catch (Throwable $Throwable) {
               $record('L6', $driver, false, $threw($Throwable));
            }

            // @ L7: a cache that refuses the generations from the start — each
            //   check goes uncached, nothing is cached under a missing
            //   generation, and invalidate() holds (no generation stands);
            //   once a generation stands and can neither be replaced nor
            //   dropped, invalidate() reports it and the list stays current
            foreach ([
               'L7 drop' => [true, false, false],
               'L7 stuck' => [false, false, false],
               'L7 thrown' => [true, true, false],
               'L7 thrown stuck' => [false, true, false],
               'L7 standing' => [false, false, true],
               'L7 thrown standing' => [false, true, true],
            ] as $leg => [$deletes, $throws, $standing]) {
               try {
                  [$path, $ReaderSQL, $Reader, $Admin, $AdminCache, $ReaderCache] = $world($driver);
                  $Subject = new Identity(id: 'u-42');
                  // ! A standing generation (and its list) before the refusal
                  $warm = $standing ? $Reader->check(new Identity(id: 'u-42'), 'invoices:delete') : true;
                  // ! A standing generation: every write and delete refused (the
                  //   legacy shim's too); otherwise the generations alone
                  foreach ([$ReaderCache, $AdminCache] as $Refusing) {
                     $Refusing->refused = $standing ? [''] : [':gen', ':s:'];
                     $Refusing->deletes = $deletes;
                     $Refusing->throws = $throws;
                  }
                  $first = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $revoke($path);
                  $single = $Admin->invalidate($Subject);
                  $every = $Admin->invalidate();
                  $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $entries = 0;
                  // ! The memory driver's reader and admin share one Cache
                  $writes = $ReaderCache === $AdminCache
                     ? $ReaderCache->stored
                     : array_merge($ReaderCache->stored, $AdminCache->stored);
                  foreach ($writes as $key) {
                     $entries += str_contains($key, ':u:') ? 1 : 0;
                  }
                  $passed = $standing
                     ? $warm === true && $first === true && $single === false && $every === false
                        && $after === true && $entries === 1 && $ReaderSQL->queries === 1
                     : $first === true && $single === true && $every === true
                        && $after === false && $entries === 0 && $ReaderSQL->queries === 2;
                  $record(
                     $leg,
                     $driver,
                     $passed,
                     "warm={$bool($warm)} first={$bool($first)} invalidate(id)={$bool($single)} invalidate()={$bool($every)} after={$bool($after)} entries={$entries} queries={$ReaderSQL->queries}"
                  );
               }
               catch (Throwable $Throwable) {
                  $record($leg, $driver, false, $threw($Throwable));
               }
            }

            // @ L8: the admin's cache starts refusing every write after the
            //   lists are warm (full disk, maxmemory): invalidate() drops the
            //   generation instead, and the revoke is still not served
            foreach ([
               'L8 identity' => [true, false],
               'L8 global' => [false, false],
               'L8 thrown identity' => [true, true],
               'L8 thrown global' => [false, true],
            ] as $leg => [$identity, $throws]) {
               try {
                  [$path, $ReaderSQL, $Reader, $Admin, $AdminCache] = $world($driver);
                  $Subject = new Identity(id: 'u-42');
                  $warm = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete')
                     && $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $AdminCache->refused = [''];
                  $AdminCache->throws = $throws;
                  $revoke($path);
                  $invalidated = $identity ? $Admin->invalidate($Subject) : $Admin->invalidate();
                  $AdminCache->refused = [];
                  $AdminCache->throws = false;
                  $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $read = $Reader->check(new Identity(id: 'u-42'), 'invoices:read');
                  $record(
                     $leg,
                     $driver,
                     $warm && $invalidated === true && $after === false && $read === true,
                     "warm={$bool($warm)} invalidate={$bool($invalidated)} after={$bool($after)} read={$bool($read)}"
                  );
               }
               catch (Throwable $Throwable) {
                  $record($leg, $driver, false, $threw($Throwable));
               }
            }

            // @ L10: cache reads and entry writes that throw — the check answers
            //   from the database and never throws
            foreach ([
               'L10 entry store' => [[':u:'], []],
               'L10 entry read' => [[], [':u:']],
               'L10 generation read' => [[], [':gen']],
            ] as $leg => [$refused, $unreadable]) {
               try {
                  [$path, $ReaderSQL, $Reader, $Admin, $AdminCache, $ReaderCache] = $world($driver);
                  $ReaderCache->refused = $refused;
                  $ReaderCache->throws = true;
                  $ReaderCache->unreadable = $unreadable;
                  $first = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $revoke($path);
                  $invalidated = $Admin->invalidate(new Identity(id: 'u-42'));
                  $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
                  $record(
                     $leg,
                     $driver,
                     $first === true && $invalidated === true && $after === false && $ReaderSQL->queries === 2,
                     "first={$bool($first)} invalidate={$bool($invalidated)} after={$bool($after)} queries={$ReaderSQL->queries}"
                  );
               }
               catch (Throwable $Throwable) {
                  $record($leg, $driver, false, $threw($Throwable));
               }
            }

            // @ L9: a read replica that has not applied the revoke — the
            //   checks read the primary, so the lag is never cached
            try {
               $sequence++;
               $replica = "{$base}/replica-{$sequence}.sqlite";
               [$path, $ReaderSQL, $Reader, $Admin] = $world($driver, $replica);
               // ! A grant only the replica holds: a read it served would show it
               $native($replica, "INSERT INTO permissions (id, name) VALUES (3, 'replica:marker')");
               $native($replica, 'INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 3)');
               $Probe = $ReaderSQL->await($ReaderSQL->query("SELECT id FROM permissions WHERE name = 'replica:marker'"));
               $routed = $Probe->rows !== [];
               $Subject = new Identity(id: 'u-42');
               $warm = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
               $marker = $Reader->check(new Identity(id: 'u-42'), 'replica:marker');
               $revoke($path);
               $Admin->invalidate($Subject);
               $after = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
               $record(
                  'L9',
                  $driver,
                  $routed && $warm === true && $marker === false && $after === false,
                  "replica-routed-read={$bool($routed)} warm={$bool($warm)} marker={$bool($marker)} after={$bool($after)}"
               );
            }
            catch (Throwable $Throwable) {
               $record('L9', $driver, false, $threw($Throwable));
            }
         }
      }
      finally {
         // @ Release the connections, the segments and the files
         foreach ($Databases as $Database) {
            foreach (array_merge([$Database->Pool], $Database->ReplicaPools) as $Pool) {
               $Pool->Connection->disconnect();
               foreach ($Pool->idle as $Connection) {
                  $Connection->disconnect();
               }
               foreach ($Pool->busy as $Connection) {
                  $Connection->disconnect();
               }
            }
         }
         foreach ($Segments as $Cache) {
            $Driver = $Cache->Driver;
            if ($Driver instanceof Shared) {
               $Driver->destroy();
            }
         }
         unset($Databases, $Segments, $Reader, $Admin, $ReaderSQL, $AdminCache);
         gc_collect_cycles();
         if (is_dir($base)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Info) {
               $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
            }
            rmdir($base);
         }
      }

      // : Every driver must take every leg
      $count = static fn (string $leg): int => count(array_keys($rows[$leg] ?? []));
      $expected = count($drivers);

      yield assert(
         assertion: $count('L1') === $expected && $list('L1') === '',
         description: "L1: a revoke + invalidate(\$Identity) inside a cold check's window — the next check denies with exactly one re-query{$list('L1')}"
      );
      yield assert(
         assertion: $count('L2') === $expected && $list('L2') === '',
         description: "L2: a revoke + invalidate() inside a cold check's window — the next check denies with exactly one re-query{$list('L2')}"
      );
      yield assert(
         assertion: $count('L3 identity') === $expected && $list('L3 identity') === ''
            && $count('L3 global') === $expected && $list('L3 global') === '',
         description: "L3: warm, invalidate, then a revoke + invalidate inside the re-query's window — the next check denies{$list('L3 identity')}{$list('L3 global')}"
      );
      yield assert(
         assertion: $count('L4 identity') === $expected && $list('L4 identity') === ''
            && $count('L4 global') === $expected && $list('L4 global') === '',
         description: "L4: the identity or resolver generation lost inside the window — the revoked permission is still not served{$list('L4 identity')}{$list('L4 global')}"
      );
      yield assert(
         assertion: $count('L5L1') === $expected && $list('L5L1') === ''
            && $count('L5L2') === $expected && $list('L5L2') === '',
         description: "L5: after the recovery re-query the list is a cache hit again (0 queries){$list('L5L1')}{$list('L5L2')}"
      );
      yield assert(
         assertion: $count('L6') === $expected && $list('L6') === '',
         description: "L6: a database failure denies without throwing, is not cached and does not resurrect the replaced list{$list('L6')}"
      );
      $legs = ['L7 drop', 'L7 stuck', 'L7 thrown', 'L7 thrown stuck', 'L7 standing', 'L7 thrown standing'];
      $sevens = true;
      $failures = '';
      foreach ($legs as $leg) {
         $sevens = $sevens && $count($leg) === $expected && $list($leg) === '';
         $failures = "{$failures}{$list($leg)}";
      }
      yield assert(
         assertion: $sevens,
         description: "L7: generations the cache refuses (false or thrown) send every check uncached with no list cached, and invalidate() holds; a standing generation that can neither be replaced nor dropped makes invalidate() false{$failures}"
      );
      yield assert(
         assertion: $count('L8 identity') === $expected && $list('L8 identity') === ''
            && $count('L8 global') === $expected && $list('L8 global') === ''
            && $count('L8 thrown identity') === $expected && $list('L8 thrown identity') === ''
            && $count('L8 thrown global') === $expected && $list('L8 thrown global') === '',
         description: "L8: an admin cache refusing every write (false or thrown) still invalidates (the generation is dropped) and the revoke is not served{$list('L8 identity')}{$list('L8 global')}{$list('L8 thrown identity')}{$list('L8 thrown global')}"
      );
      yield assert(
         assertion: $count('L10 entry store') === $expected && $list('L10 entry store') === ''
            && $count('L10 entry read') === $expected && $list('L10 entry read') === ''
            && $count('L10 generation read') === $expected && $list('L10 generation read') === '',
         description: "L10: a thrown entry write, entry read or generation read answers from the database without throwing, and the revoke is not served{$list('L10 entry store')}{$list('L10 entry read')}{$list('L10 generation read')}"
      );
      yield assert(
         assertion: $count('L9') === $expected && $list('L9') === '',
         description: "L9: with a lagging read replica the checks read the primary — the revoke is never cached from the replica{$list('L9')}"
      );

      if ($shared === false) {
         yield (new Assertion(description: 'the shared cache driver needs ext-sysvshm and ext-sysvsem'))->skip();
      }
   }
);
