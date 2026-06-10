<?php

namespace Bootgly\API\Security\Tests\AuthorizationRBACCache;


use function assert;
use function count;
use function sys_get_temp_dir;
use function uniqid;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test\Specification;
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
   public bool $fail;
   public int $queries = 0;


   /**
    * @param array<int,array<string,mixed>> $rows
    */
   public function __construct (array $rows = [], bool $fail = false)
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
      // ! Zero-size pool keeps the real SQL constructor connection-free for this double.

      // * Data
      $this->rows = $rows;
      $this->fail = $fail;
   }

   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = [], null|object $Scope = null): SQLOperation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new SQLOperation(null, $Normalized->sql, $Normalized->parameters, $this->Config->timeout);

      $this->queries++;

      if ($this->fail) {
         return $Operation->fail('RBAC database failure.');
      }

      $Operation->rows = $this->rows;
      $Operation->resolve(new Result('SELECT', rows: $this->rows));

      return $Operation;
   }

   public function await (SQLOperation $Operation): SQLOperation
   {
      return $Operation;
   }
}


return new Specification(
   description: 'Authorization: RBAC permission-list caching (resolve + tag invalidation)',
   test: function () {
      // ! Deterministic clock driving the cache backend
      $now = 1000;
      $clock = function () use (&$now): int {
         return $now;
      };

      $cache = function () use ($clock): Cache {
         $dir = sys_get_temp_dir() . '/bootgly-rbac-test-' . uniqid();

         return new Cache([
            'driver' => 'file',
            'path' => $dir,
            'clock' => $clock,
         ]);
      };

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

      // @ Hit → load() and check() are served without further queries
      $RBAC->load($Identity);
      $allowed = $RBAC->check($Identity, 'demo:read');
      $denied = $RBAC->check($Identity, 'posts:delete');
      yield assert(
         assertion: $Database->queries === 1 && $allowed === true && $denied === false,
         description: 'Cached permission list serves load() and check() with zero queries'
      );

      // @ Per-identity invalidation → next load re-queries
      $RBAC->invalidate($Identity);
      $RBAC->load($Identity);
      yield assert(
         assertion: $Database->queries === 2,
         description: 'invalidate(Identity) drops the entry and forces a re-query'
      );

      // @ Tag invalidation (all identities) → next check re-queries
      $RBAC->invalidate();
      $RBAC->check($Identity, 'demo:read');
      yield assert(
         assertion: $Database->queries === 3,
         description: 'invalidate() drops every rbac-tagged entry and forces a re-query'
      );

      // @ TTL expiry → entry vanishes natively and is recomputed
      $now = 1200;
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
   }
);
