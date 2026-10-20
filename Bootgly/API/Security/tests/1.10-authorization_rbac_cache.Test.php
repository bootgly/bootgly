<?php

namespace Bootgly\API\Security\Tests\AuthorizationRBACCache;


use const PHP_INT_MAX;
use function assert;
use function implode;
use function intdiv;
use function is_dir;
use function is_string;
use function rmdir;
use function unlink;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\API\Security\Authorization\RBAC;
use Bootgly\API\Security\Identity;


class CountingSQL extends SQL
{
   /**
    * @var array<int,array<string,mixed>>
    */
   public array $rows;
   /**
    * Rows per subject, keyed on the bound user_id (`rows` otherwise).
    *
    * @var array<string,array<int,array<string,mixed>>>
    */
   public array $subjects;
   public bool $fail;
   /**
    * Awaits left that throw without recording an error (the operation
    * stays unfinished, as when the pool wait itself breaks).
    */
   public int $aborts = 0;
   public int $queries = 0;
   /**
    * The user_id bound by each query, in order.
    *
    * @var array<int,mixed>
    */
   public array $queried = [];


   /**
    * @param array<int,array<string,mixed>> $rows
    * @param array<string,array<int,array<string,mixed>>> $subjects
    */
   public function __construct (array $rows = [], bool $fail = false, array $subjects = [])
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
      // ! Zero-size pool keeps the real SQL constructor connection-free for this double.

      // * Data
      $this->rows = $rows;
      $this->fail = $fail;
      $this->subjects = $subjects;
   }

   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = [], null|object $Scope = null): SQLOperation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new SQLOperation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      $this->queries++;
      // ! The RBAC query binds the user_id first
      $subject = $Operation->parameters[0] ?? null;
      $this->queried[] = $subject;

      if ($this->fail) {
         return $Operation->fail('RBAC database failure.');
      }
      // ? Still running: the await breaks before it finishes
      if ($this->aborts > 0) {
         return $Operation;
      }

      $rows = is_string($subject) && isSet($this->subjects[$subject])
         ? $this->subjects[$subject]
         : $this->rows;
      $Operation->rows = $rows;
      $Operation->resolve(new Result('SELECT', rows: $rows));

      return $Operation;
   }

   public function await (SQLOperation $Operation): SQLOperation
   {
      // ? The wait breaks with nothing recorded
      if ($this->aborts > 0 && $Operation->finished === false) {
         $this->aborts--;
         throw new RuntimeException('RBAC database wait aborted.');
      }
      // ? Like Pool::wait(): a failed operation throws
      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }

      return $Operation;
   }
}


return new Test(
   description: 'Authorization: RBAC permission-list caching (generation-bound lists and invalidation)',
   test: function () {
      // ! Deterministic clock driving the cache backend
      $now = 1000;
      $clock = function () use (&$now): int {
         return $now;
      };

      /** @var array<int,string> $paths */
      $paths = [];
      $cache = function () use ($clock, &$paths): Cache {
         $dir = Temporaries::reserve('rbac-cache');
         $paths[] = $dir;

         return new Cache([
            'driver' => 'file',
            'path' => $dir,
            'clock' => $clock,
         ]);
      };

      try {
         $Identity = new Identity(id: 'demo-user', scopes: ['token:scope']);

         // @ Miss → one query; permissions land in identity scopes
         $Database = new CountingSQL(rows: [
            ['name' => 'demo:read'],
            ['name' => 'demo:write'],
         ]);
         $RBAC = new RBAC($Database, Cache: $cache(), lifetime: 100);

         $RBAC->load($Identity);
         yield assert(
            assertion: $Database->queries === 1
               && $Identity->scopes === ['token:scope', 'demo:read', 'demo:write'],
            description: 'First load() queries once and fills identity scopes'
         );

         // @ Hit → load() and check() are served without further queries,
         //   until the last second of the lifetime
         $RBAC->load($Identity);
         $allowed = $RBAC->check($Identity, 'demo:read');
         $now = 1099;
         $denied = $RBAC->check($Identity, 'posts:delete');
         yield assert(
            assertion: $Database->queries === 1 && $allowed === true && $denied === false,
            description: 'Cached permission list serves load() and check() with zero queries for its whole lifetime'
         );

         // @ Per-identity invalidation → next load re-queries
         $invalidated = $RBAC->invalidate($Identity);
         $RBAC->load($Identity);
         yield assert(
            assertion: $invalidated === true && $Database->queries === 2,
            description: 'invalidate(Identity) returns true, makes the identity\'s cached list stale and forces a re-query'
         );

         // @ Resolver invalidation (all identities) → next check re-queries
         $invalidated = $RBAC->invalidate();
         $RBAC->check($Identity, 'demo:read');
         yield assert(
            assertion: $invalidated === true && $Database->queries === 3,
            description: 'invalidate() returns true, makes every cached list of the resolver stale and forces a re-query'
         );

         // @ TTL expiry → entry vanishes natively and is recomputed (the
         //   generations, minted for twice the lifetime, are still current)
         $now = 1250;
         $RBAC->check($Identity, 'demo:read');
         yield assert(
            assertion: $Database->queries === 4,
            description: 'Expired permission list is recomputed after the TTL'
         );

         // @ Empty permission list is a valid cached value (no re-query)
         $Nobody = new Identity(id: 'powerless');
         $Database = new CountingSQL(rows: []);
         $RBAC = new RBAC($Database, Cache: $cache(), lifetime: 100);

         $RBAC->load($Nobody);
         $RBAC->load($Nobody);
         yield assert(
            assertion: $Database->queries === 1 && $Nobody->scopes === [],
            description: 'An identity without permissions caches the empty list'
         );

         // @ Database failures are never cached — and check() fails closed
         $Failing = new CountingSQL(fail: true);
         $RBAC = new RBAC($Failing, Cache: $cache(), lifetime: 100);

         $Before = new Identity(id: 'demo-user', scopes: ['token:scope']);
         $RBAC->load($Before);
         $failed = $RBAC->check($Before, 'demo:read');
         yield assert(
            assertion: $Failing->queries === 2
               && $Before->scopes === ['token:scope']
               && $failed === false,
            description: 'Errors leave the identity untouched, fail closed and retry (not cached)'
         );

         // @ A wait that breaks with nothing recorded is a failure too: it
         //   denies, and the unfinished operation is not cached as a list
         $Aborting = new CountingSQL(rows: [['name' => 'demo:read']]);
         $Aborting->aborts = 1;
         $RBAC = new RBAC($Aborting, Cache: $cache(), lifetime: 100);
         $aborted = $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
         $recovered = $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
         $cached = $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
         yield assert(
            assertion: $aborted === false && $recovered === true && $cached === true
               && $Aborting->queries === 2,
            description: 'A wait that throws without a recorded error denies and is not cached: the next check queries once'
         );

         // @ An identity generation lives twice the lifetime: one minted by a
         //   failed check still covers a list stored later in the lifetime
         $start = $now;
         $Flaky = new CountingSQL(rows: [['name' => 'demo:read']], fail: true);
         $RBAC = new RBAC($Flaky, Cache: $cache(), lifetime: 100);
         $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
         $Flaky->fail = false;
         $now = $start + 50;
         $stored = $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
         $now = $start + 120;
         $hit = $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
         yield assert(
            assertion: $stored === true && $hit === true && $Flaky->queries === 2,
            description: 'An identity generation outlives the lifetime: a list stored after it was minted is a hit for its whole lifetime'
         );

         // @ Long lifetimes keep the generation TTL an int under a real clock
         //   (twice the lifetime up to PHP_INT_MAX / 4, the lifetime above)
         $failed = [];
         foreach ([intdiv(PHP_INT_MAX, 4), intdiv(PHP_INT_MAX, 4) + 1, intdiv(PHP_INT_MAX, 2)] as $lifetime) {
            $dir = Temporaries::reserve('rbac-cache');
            $paths[] = $dir;
            $Database = new CountingSQL(rows: [['name' => 'demo:read']]);
            $RBAC = new RBAC($Database, Cache: new Cache(['driver' => 'file', 'path' => $dir]), lifetime: $lifetime);
            $long = $RBAC->check(new Identity(id: 'demo-user'), 'demo:read')
               && $RBAC->invalidate(new Identity(id: 'demo-user'))
               && $RBAC->check(new Identity(id: 'demo-user'), 'demo:read');
            if ($long !== true || $Database->queries !== 2) {
               $failed[] = $lifetime;
            }
         }
         $joined = implode(', ', $failed);
         $overflowed = $failed === [] ? '' : " — failed: {$joined}";
         yield assert(
            assertion: $failed === [],
            description: "Lifetimes up to PHP_INT_MAX / 2 cache, invalidate and re-query without overflowing{$overflowed}"
         );

         // @ I-16: two subjects on one RBAC and one Cache keep their own lists
         $Database = new CountingSQL(subjects: [
            'admin-1' => [['name' => 'users:delete'], ['name' => '42']],
            'guest-2' => [['name' => 'posts:read']],
         ]);
         $RBAC = new RBAC($Database, Cache: $cache(), lifetime: 100);
         $Admin = new Identity(id: 'admin-1');
         $Guest = new Identity(id: 'guest-2');

         $RBAC->load($Admin);
         $guestDelete = $RBAC->check($Guest, 'users:delete');
         yield assert(
            assertion: $Admin->scopes === ['users:delete', '42']
               && $guestDelete === false
               && $Database->queries === 2
               && $Database->queried === ['admin-1', 'guest-2'],
            description: "A cached admin list never answers for a guest: one query per subject"
         );

         $adminDelete = $RBAC->check($Admin, 'users:delete');
         $guestRead = $RBAC->check($Guest, 'posts:read');
         $RBAC->invalidate($Admin);
         $adminAgain = $RBAC->check($Admin, 'users:delete');
         $guestAgain = $RBAC->check($Guest, 'posts:read');
         yield assert(
            assertion: $adminDelete === true && $guestRead === true
               && $adminAgain === true && $guestAgain === true
               && $Database->queried === ['admin-1', 'guest-2', 'admin-1'],
            description: 'invalidate($Admin) re-queries the admin only; the guest list stays cached'
         );

         yield assert(
            assertion: $RBAC->check($Admin, '42') === true
               && $RBAC->check($Admin, '42.0') === false
               && $RBAC->check($Admin, '042') === false
               && $RBAC->check($Guest, '42') === false,
            description: 'A numeric-string permission matches only its exact name (strict comparison)'
         );
      }
      finally {
         // @ Remove the cache directories
         foreach ($paths as $path) {
            if (is_dir($path) === false) {
               continue;
            }
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Info) {
               $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
            }
            rmdir($path);
         }
      }
   }
);
