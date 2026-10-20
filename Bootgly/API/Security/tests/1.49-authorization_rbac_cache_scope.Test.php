<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\AuthorizationRBACCacheScope;


use function array_merge;
use function assert;
use function extension_loaded;
use function gc_collect_cycles;
use function get_class;
use function getmypid;
use function is_dir;
use function json_encode;
use function preg_match;
use function rmdir;
use function strlen;
use function symlink;
use function touch;
use function unlink;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SQLite3;
use Throwable;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Authorization\RBAC;
use Bootgly\API\Security\Identity;


/**
 * A real SQLite database that counts the queries it is asked to run.
 */
class CountingSQL extends SQL
{
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
}


/*
 * Resolvers sharing one cache store: each realm (connection identity + the
 * four table names, or an explicit `realm:` + the table names) keeps its own
 * lists and its own invalidations. SQLite files under one reserved temporary; the file cache
 * driver, with an independent Cache object per resolver over one path (two
 * workers, two modules).
 */
return new Test(
   description: 'Authorization: RBAC resolvers sharing a cache store keep their lists and invalidations within their own realm',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $base = Temporaries::reserve('rbac-scope');
      /** @var array<int,SQL> $Databases */
      $Databases = [];
      /** @var array<int,Transaction> $Transactions */
      $Transactions = [];
      /** @var array<string,true|string> $rows leg => verdict */
      $rows = [];

      // ! One table set (optionally prefixed): u-42 holds the given grants
      $fixture = static function (string $path, string $prefix, array $grants): void {
         $Native = new SQLite3($path);
         $Native->busyTimeout(5000);
         $Native->exec('PRAGMA journal_mode=WAL');
         $Native->exec("CREATE TABLE {$prefix}roles (id INTEGER PRIMARY KEY, name TEXT)");
         $Native->exec("CREATE TABLE {$prefix}permissions (id INTEGER PRIMARY KEY, name TEXT)");
         $Native->exec("CREATE TABLE {$prefix}role_permissions (role_id INTEGER, permission_id INTEGER)");
         $Native->exec("CREATE TABLE {$prefix}user_roles (user_id TEXT, role_id INTEGER)");
         $Native->exec("INSERT INTO {$prefix}roles (id, name) VALUES (1, 'staff')");
         $Native->exec("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES ('u-42', 1)");
         foreach ($grants as $index => $grant) {
            $id = $index + 1;
            $Native->exec("INSERT INTO {$prefix}permissions (id, name) VALUES ({$id}, '{$grant}')");
            $Native->exec("INSERT INTO {$prefix}role_permissions (role_id, permission_id) VALUES (1, {$id})");
         }
         $Native->close();
      };
      $native = static function (string $path, string $SQL): void {
         $Native = new SQLite3($path);
         $Native->busyTimeout(5000);
         $Native->exec($SQL);
         $Native->close();
      };
      $connect = static function (string $path, string $username = '') use (&$Databases): CountingSQL {
         $Database = new CountingSQL([
            'driver' => 'sqlite',
            'database' => $path,
            'username' => $username,
            'timeout' => 5.0,
            'statements' => 0,
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $Databases[] = $Database;

         return $Database;
      };
      // ! A connection-free resolver over a server config (pool of zero)
      $remote = static function (array $config): RBAC {
         return new RBAC(new SQL(array_merge([
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'app',
            'username' => 'app',
            'pool' => ['min' => 0, 'max' => 0],
         ], $config)));
      };
      // ! A connection-free SQLite facade
      $offline = static function (array $config): SQL {
         return new SQL(array_merge(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]], $config));
      };
      $store = "{$base}/cache";
      $cache = static fn (): Cache => new Cache(['driver' => 'file', 'path' => $store]);
      $record = static function (string $leg, bool $passed, string $evidence) use (&$rows): void {
         $rows[$leg] = $passed ? true : $evidence;
      };
      $verdict = static function (string $leg) use (&$rows): string {
         $value = $rows[$leg] ?? 'not reached';

         return $value === true ? '' : " — FAILED {$value}";
      };
      $bool = static fn (mixed $value): string => $value === true ? 'true' : ($value === false ? 'false' : 'n/a');
      $threw = static function (Throwable $Throwable): string {
         $class = get_class($Throwable);

         return "threw {$class}: {$Throwable->getMessage()}";
      };
      $equal = static fn (bool $same): string => $same ? 'equal' : 'differ';

      try {
         // @ 1. Two table sets in one database: A (default names) and B (b_)
         $shared = "{$base}/app.sqlite";
         $fixture($shared, '', ['invoices:read']);
         $fixture($shared, 'b_', ['billing:refund']);
         $DatabaseA = $connect($shared);
         $DatabaseB = $connect($shared);
         $A = new RBAC($DatabaseA, Cache: $cache(), lifetime: 300);
         $B = new RBAC(
            $DatabaseB,
            'b_roles', 'b_permissions', 'b_role_permissions', 'b_user_roles',
            Cache: $cache(),
            lifetime: 300
         );
         try {
            $first = $B->check(new Identity(id: 'u-42'), 'billing:refund');
            $refund = $A->check(new Identity(id: 'u-42'), 'billing:refund');
            $read = $A->check(new Identity(id: 'u-42'), 'invoices:read');
            $Loaded = $A->load(new Identity(id: 'u-42'));
            // ! Each realm keeps its own entry: B's list is still a hit
            $before = $DatabaseB->queries;
            $again = $B->check(new Identity(id: 'u-42'), 'billing:refund');
            $queries = $DatabaseB->queries - $before;
            $scopes = json_encode($Loaded->scopes);
            $record(
               'tables',
               $first === true && $refund === false && $read === true
                  && $Loaded->scopes === ['invoices:read'] && $A->realm !== $B->realm
                  && $again === true && $queries === 0,
               "B={$bool($first)} A.refund={$bool($refund)} A.read={$bool($read)} A.scopes={$scopes} B.again={$bool($again)} B.queries={$queries}"
            );
         }
         catch (Throwable $Throwable) {
            $record('tables', false, $threw($Throwable));
         }

         // @ 3. B's invalidations do not reach A's lists
         try {
            $before = $DatabaseA->queries;
            $B->invalidate(new Identity(id: 'u-42'));
            $B->invalidate();
            $read = $A->check(new Identity(id: 'u-42'), 'invoices:read');
            $queries = $DatabaseA->queries - $before;
            $record(
               'foreign invalidate',
               $read === true && $queries === 0,
               "A.read={$bool($read)} A.queries={$queries}"
            );
         }
         catch (Throwable $Throwable) {
            $record('foreign invalidate', false, $threw($Throwable));
         }

         // @ 8. AUTH-2: A's user_roles dropped — A's next database read denies
         //   instead of throwing or borrowing B's list
         try {
            $native($shared, 'DROP TABLE user_roles');
            $A->invalidate(new Identity(id: 'u-42'));
            $read = $A->check(new Identity(id: 'u-42'), 'invoices:read');
            $refund = $A->check(new Identity(id: 'u-42'), 'billing:refund');
            $Kept = $A->load(new Identity(id: 'u-42', scopes: ['token:scope']));
            $other = $B->check(new Identity(id: 'u-42'), 'billing:refund');
            $scopes = json_encode($Kept->scopes);
            $record(
               'dropped user_roles',
               $read === false && $refund === false && $Kept->scopes === ['token:scope'] && $other === true,
               "A.read={$bool($read)} A.refund={$bool($refund)} A.scopes={$scopes} B.refund={$bool($other)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('dropped user_roles', false, $threw($Throwable));
         }

         // @ 2. Two tenant files with the default table names
         try {
            $acme = "{$base}/acme.sqlite";
            $globex = "{$base}/globex.sqlite";
            $fixture($acme, '', ['invoices:read']);
            $fixture($globex, '', ['billing:refund']);
            $Acme = new RBAC($connect($acme), Cache: $cache(), lifetime: 300);
            $Globex = new RBAC($connect($globex), Cache: $cache(), lifetime: 300);
            $Seen = $Globex->load(new Identity(id: 'u-42'));
            $refund = $Acme->check(new Identity(id: 'u-42'), 'billing:refund');
            $read = $Acme->check(new Identity(id: 'u-42'), 'invoices:read');
            $scopes = json_encode($Seen->scopes);
            $record(
               'tenants',
               $Seen->scopes === ['billing:refund'] && $refund === false && $read === true
                  && $Acme->realm !== $Globex->realm,
               "globex.scopes={$scopes} acme.refund={$bool($refund)} acme.read={$bool($read)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('tenants', false, $threw($Throwable));
         }

         // @ 4. One permission set through two Database objects: the writer's
         //   invalidations reach the reader
         try {
            $one = "{$base}/one.sqlite";
            $fixture($one, '', ['invoices:read', 'invoices:delete']);
            $ReaderSQL = $connect($one);
            $Reader = new RBAC($ReaderSQL, Cache: $cache(), lifetime: 300);
            $Writer = new RBAC($connect($one), Cache: $cache(), lifetime: 300);
            $warm = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
            $native($one, 'DELETE FROM role_permissions WHERE permission_id = 2');
            $Writer->invalidate(new Identity(id: 'u-42'));
            $revoked = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
            $native($one, 'INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 2)');
            $Writer->invalidate();
            $granted = $Reader->check(new Identity(id: 'u-42'), 'invoices:delete');
            $record(
               'reach',
               $warm === true && $revoked === false && $granted === true && $Reader->realm === $Writer->realm,
               "warm={$bool($warm)} afterRevoke={$bool($revoked)} afterGrant={$bool($granted)} realms {$equal($Reader->realm === $Writer->realm)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('reach', false, $threw($Throwable));
         }

         // @ 5. A resolver over a Transaction shares the realm of its database
         try {
            $Plain = new RBAC($connect($one), Cache: $cache());
            $Transaction = new Transaction($connect($one));
            $Transactions[] = $Transaction;
            $Bound = new RBAC($Transaction, Cache: $cache());
            $record(
               'transaction',
               strlen($Bound->realm) > 0 && $Bound->realm === $Plain->realm,
               "transaction realm {$Bound->realm} vs database realm {$Plain->realm}"
            );
         }
         catch (Throwable $Throwable) {
            $record('transaction', false, $threw($Throwable));
         }

         // @ 6. The connection identity: driver, host, port, database and
         //   username each name a different realm; an identical config does
         //   not. An SQLite file is named by its path alone (host, port and
         //   username select nothing there), under any spelling of it
         try {
            $Base = $remote([]);
            $same = $remote([])->realm === $Base->realm;
            $differ = [];
            foreach ([
               'host' => ['host' => 'db.replica'],
               'port' => ['port' => 6432],
               'database' => ['database' => 'other'],
               'username' => ['username' => 'admin'],
               'driver' => ['driver' => 'mysql'],
            ] as $field => $config) {
               if ($remote($config)->realm === $Base->realm) {
                  $differ[] = $field;
               }
            }
            $File = new RBAC($offline(['database' => $one]));
            $ignored = [];
            foreach ([
               'host' => ['host' => 'db.internal'],
               'port' => ['port' => 6432],
               'username' => ['username' => 'admin'],
            ] as $field => $config) {
               if ((new RBAC($offline(['database' => $one] + $config)))->realm !== $File->realm) {
                  $ignored[] = $field;
               }
            }
            $spelled = (new RBAC($offline(['database' => "{$base}/./one.sqlite"])))->realm === $File->realm;
            // ! The fields are joined unambiguously: a separator inside one
            //   field never spells another field set
            $joined = (new RBAC($offline(['database' => "{$base}/x.sqlite|r"]), 's'))->realm
               !== (new RBAC($offline(['database' => "{$base}/x.sqlite"]), 'r|s'))->realm
               && $remote(['database' => 'app|ops', 'username' => 'svc'])->realm
                  !== $remote(['database' => 'app', 'username' => 'ops|svc'])->realm;
            $hexadecimal = preg_match('/^[0-9a-f]{32}$/', $File->realm) === 1
               && preg_match('/^[0-9a-f]{32}$/', (new RBAC($offline(['database' => $one]), realm: 'acme'))->realm) === 1;
            $unchanged = json_encode($differ);
            $split = json_encode($ignored);
            $record(
               'connection identity',
               $same && $differ === [] && $ignored === [] && $spelled && $joined && $hexadecimal,
               "identical={$equal($same)} unchanged-by={$unchanged} sqlite-split-by={$split} sqlite-path-spellings={$equal($spelled)} separator-collision={$bool(! $joined)} hexadecimal={$bool($hexadecimal)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('connection identity', false, $threw($Throwable));
         }

         // @ 7. The realm: override joins two spellings of one permission set
         //   (here the file and a symlink to it); one realm string over
         //   another table set is still another realm
         try {
            $alias = "{$base}/alias.sqlite";
            symlink($one, $alias);
            $split = (new RBAC($connect($one)))->realm !== (new RBAC($connect($alias)))->realm;
            $AppSQL = $connect($one);
            $App = new RBAC($AppSQL, Cache: $cache(), lifetime: 300, realm: 'acme-rbac');
            $Operator = new RBAC($connect($alias), Cache: $cache(), lifetime: 300, realm: 'acme-rbac');
            $derived = (new RBAC($connect($one)))->realm;
            $tables = (new RBAC($connect($one), 'b_roles', 'b_permissions', 'b_role_permissions', 'b_user_roles', realm: 'acme-rbac'))->realm;
            $warm = $App->check(new Identity(id: 'u-42'), 'invoices:delete');
            $native($one, 'DELETE FROM role_permissions WHERE permission_id = 2');
            $Operator->invalidate(new Identity(id: 'u-42'));
            $revoked = $App->check(new Identity(id: 'u-42'), 'invoices:delete');
            $applied = $App->realm !== $derived;
            $record(
               'realm override',
               $split && $App->realm === $Operator->realm && $applied && $tables !== $App->realm
                  && $warm === true && $revoked === false,
               "spellings-split={$bool($split)} realms {$equal($App->realm === $Operator->realm)} override-applied={$bool($applied)} other-tables {$equal($tables === $App->realm)} warm={$bool($warm)} afterRevoke={$bool($revoked)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('realm override', false, $threw($Throwable));
         }

         // @ 9. Lists of the previous key layout (until 1.1.0): invalidate()
         //   still drops them for workers that have not been upgraded
         try {
            $Legacy = $cache();
            $Legacy->store('rbac:u-42', ['invoices:delete'], 300, ['rbac']);
            $Legacy->store('rbac:u-7', ['invoices:delete'], 300, ['rbac']);
            $Shim = new RBAC($connect($one), Cache: $cache(), lifetime: 300);
            $Shim->invalidate(new Identity(id: 'u-42'));
            $single = $Legacy->fetch('rbac:u-42') === null && $Legacy->fetch('rbac:u-7') !== null;
            $Shim->invalidate();
            $every = $Legacy->fetch('rbac:u-7') === null;
            $record(
               'legacy shim',
               $single && $every,
               "invalidate(\$Identity) dropped rbac:u-42 only={$bool($single)} invalidate() dropped the rbac tag={$bool($every)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('legacy shim', false, $threw($Throwable));
         }

         // @ 10. An SQLite file names one realm before and after it exists: a
         //   worker built before the migration creates it shares the admin's
         try {
            // ! A spelling that is not canonical (`/./`), as a relative or
            //   symlinked path would be
            $late = "{$base}/./late.sqlite";
            $Before = new RBAC($connect($late), Cache: $cache(), lifetime: 300);
            touch($late);
            $After = new RBAC($connect($late), Cache: $cache(), lifetime: 300);
            $Relative = new RBAC($connect("{$base}/late.sqlite"), Cache: $cache(), lifetime: 300);
            $record(
               'file before and after',
               $Before->realm === $After->realm && $After->realm === $Relative->realm,
               "before={$Before->realm} after={$After->realm} spelled={$Relative->realm}"
            );
         }
         catch (Throwable $Throwable) {
            $record('file before and after', false, $threw($Throwable));
         }

         // @ 11. A private SQLite database (`:memory:`, the empty name) is the
         //   realm of its SQL object: two of them never share lists
         try {
            $seed = static function (SQL $Database, string $grant): void {
               foreach ([
                  'CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)',
                  'CREATE TABLE permissions (id INTEGER PRIMARY KEY, name TEXT)',
                  'CREATE TABLE role_permissions (role_id INTEGER, permission_id INTEGER)',
                  'CREATE TABLE user_roles (user_id TEXT, role_id INTEGER)',
                  "INSERT INTO roles (id, name) VALUES (1, 'staff')",
                  "INSERT INTO permissions (id, name) VALUES (1, '{$grant}')",
                  'INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 1)',
                  "INSERT INTO user_roles (user_id, role_id) VALUES ('u-42', 1)",
               ] as $statement) {
                  $Database->await($Database->query($statement));
               }
            };
            $MemoryA = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'statements' => 0]);
            $MemoryB = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'statements' => 0]);
            $Databases[] = $MemoryA;
            $Databases[] = $MemoryB;
            $seed($MemoryA, 'tenant-a:admin');
            $seed($MemoryB, 'tenant-b:read');
            $PrivateA = new RBAC($MemoryA, Cache: $cache(), lifetime: 300);
            $PrivateB = new RBAC($MemoryB, Cache: $cache(), lifetime: 300);
            $SeenA = $PrivateA->load(new Identity(id: 'u-42'));
            $borrowed = $PrivateB->check(new Identity(id: 'u-42'), 'tenant-a:admin');
            $SeenB = $PrivateB->load(new Identity(id: 'u-42'));
            $sibling = (new RBAC($MemoryA))->realm === $PrivateA->realm;
            // ! A fork copies the database and its token: the lists stay in
            //   the process that holds them
            $process = getmypid();
            $Store = $cache();
            $local = $Store->fetch("rbac:v2:{$PrivateA->realm}:{$process}:u:u-42") !== null
               && $Store->fetch("rbac:v2:{$PrivateA->realm}:u:u-42") === null;
            $MemoryTransaction = $MemoryA->begin();
            $Transactions[] = $MemoryTransaction;
            $bound = (new RBAC($MemoryTransaction))->realm === $PrivateA->realm;
            $empty = (new RBAC($offline(['database' => ''])))->realm !== (new RBAC($offline(['database' => ''])))->realm;
            $scopesA = json_encode($SeenA->scopes);
            $scopesB = json_encode($SeenB->scopes);
            $record(
               'private databases',
               $SeenA->scopes === ['tenant-a:admin'] && $borrowed === false && $SeenB->scopes === ['tenant-b:read']
                  && $PrivateA->realm !== $PrivateB->realm && $sibling && $bound && $empty && $local,
               "A.scopes={$scopesA} B.borrowed={$bool($borrowed)} B.scopes={$scopesB} same-object {$equal($sibling)} transaction {$equal($bound)} empty-names-split={$bool($empty)} keyed-by-process={$bool($local)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('private databases', false, $threw($Throwable));
         }

         // @ 12. A resolver over a Transaction reads the transaction's own view
         //   and never caches it — not an uncommitted grant, not a snapshot
         //   older than a revoke
         try {
            $view = "{$base}/view.sqlite";
            $fixture($view, '', ['invoices:read', 'invoices:delete']);
            $Store = $cache();
            $Plain = new RBAC($connect($view), Cache: $cache(), lifetime: 300);

            // # Uncommitted: a grant inside the transaction, then a rollback
            $Granting = $connect($view);
            $Uncommitted = $Granting->begin();
            $Transactions[] = $Uncommitted;
            $Granting->await($Uncommitted->Operation);
            $Granting->await($Uncommitted->query("INSERT INTO permissions (id, name) VALUES (3, 'system:wipe')"));
            $Granting->await($Uncommitted->query('INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 3)'));
            $Inside = new RBAC($Uncommitted, Cache: $cache(), lifetime: 300);
            $own = $Inside->check(new Identity(id: 'u-42'), 'system:wipe');
            $kept = $Store->fetch("rbac:v2:{$Inside->realm}:u:u-42") !== null;
            $Granting->await($Uncommitted->rollback());
            $leaked = $Plain->check(new Identity(id: 'u-42'), 'system:wipe');

            // # Snapshot: the transaction reads, a revoke + invalidate() commit
            //   elsewhere, then the transaction's check
            $Reading = $connect($view);
            $Snapshot = $Reading->begin();
            $Transactions[] = $Snapshot;
            $Reading->await($Snapshot->Operation);
            $Reading->await($Snapshot->query('SELECT count(*) FROM user_roles'));
            $native($view, 'DELETE FROM role_permissions WHERE permission_id = 2');
            $Plain->invalidate(new Identity(id: 'u-42'));
            $Old = new RBAC($Snapshot, Cache: $cache(), lifetime: 300);
            $older = $Old->check(new Identity(id: 'u-42'), 'invoices:delete');
            $revoked = $Plain->check(new Identity(id: 'u-42'), 'invoices:delete');
            $Reading->await($Snapshot->rollback());

            // # Warm: a plain resolver's list is current, then the transaction
            //   revokes and grants on its own — its checks answer its view,
            //   never the cached list
            $warmed = "{$base}/warm.sqlite";
            $fixture($warmed, '', ['invoices:read', 'invoices:delete']);
            $Warm = new RBAC($connect($warmed), Cache: $cache(), lifetime: 300);
            $cached = $Warm->check(new Identity(id: 'u-42'), 'invoices:delete');
            $Changing = $connect($warmed);
            $Changes = $Changing->begin();
            $Transactions[] = $Changes;
            $Changing->await($Changes->Operation);
            $Changing->await($Changes->query('DELETE FROM role_permissions WHERE permission_id = 2'));
            $Changing->await($Changes->query("INSERT INTO permissions (id, name) VALUES (3, 'invoices:void')"));
            $Changing->await($Changes->query('INSERT INTO role_permissions (role_id, permission_id) VALUES (1, 3)'));
            $Own = new RBAC($Changes, Cache: $cache(), lifetime: 300);
            $ownRevoke = $Own->check(new Identity(id: 'u-42'), 'invoices:delete');
            $ownGrant = $Own->check(new Identity(id: 'u-42'), 'invoices:void');
            $Changing->await($Changes->rollback());
            $record(
               'transaction view',
               $own === true && $kept === false && $leaked === false && $older === true && $revoked === false
                  && $cached === true && $ownRevoke === false && $ownGrant === true,
               "inside={$bool($own)} cached-inside={$bool($kept)} plain-after-rollback={$bool($leaked)} snapshot={$bool($older)} plain-after-revoke={$bool($revoked)} warm={$bool($cached)} own-revoke={$bool($ownRevoke)} own-grant={$bool($ownGrant)}"
            );
         }
         catch (Throwable $Throwable) {
            $record('transaction view', false, $threw($Throwable));
         }
      }
      finally {
         // @ Release the transactions, the connections and the files
         foreach ($Transactions as $Transaction) {
            try {
               if ($Transaction->depth > 0) {
                  $Transaction->await($Transaction->rollback());
               }
            }
            catch (Throwable) {
               // ...
            }
         }
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
         unset($Transactions, $Transaction, $Databases, $A, $B, $DatabaseA, $Acme, $Globex);
         unset($Reader, $ReaderSQL, $Writer, $Plain, $Bound, $App, $AppSQL, $Operator, $Shim, $Before, $After, $Relative);
         unset($DatabaseB, $File, $MemoryA, $MemoryB, $PrivateA, $PrivateB, $MemoryTransaction, $Granting, $Uncommitted, $Inside, $Reading, $Snapshot, $Old);
         unset($Warm, $Changing, $Changes, $Own);
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

      yield assert(
         assertion: ($rows['tables'] ?? null) === true,
         description: "Two table sets in one database: A never gets B-only permissions and A.load() scopes are A's own{$verdict('tables')}"
      );
      yield assert(
         assertion: ($rows['tenants'] ?? null) === true,
         description: "Two tenant files with the default table names keep isolated lists{$verdict('tenants')}"
      );
      yield assert(
         assertion: ($rows['foreign invalidate'] ?? null) === true,
         description: "B's invalidate(\$Identity) and invalidate() do not force a re-query on A{$verdict('foreign invalidate')}"
      );
      yield assert(
         assertion: ($rows['reach'] ?? null) === true,
         description: "One permission set through two Database objects: the writer's invalidate(\$Identity) and invalidate() reach the reader{$verdict('reach')}"
      );
      yield assert(
         assertion: ($rows['transaction'] ?? null) === true,
         description: "A resolver over a Transaction has the realm of its database{$verdict('transaction')}"
      );
      yield assert(
         assertion: ($rows['connection identity'] ?? null) === true,
         description: "Driver, host, port, database and username each name a different realm; an identical config the same; an SQLite file by its path alone, under any spelling; fields joined unambiguously{$verdict('connection identity')}"
      );
      yield assert(
         assertion: ($rows['realm override'] ?? null) === true,
         description: "The realm: override joins two spellings of one permission set: the operator's invalidate() reaches the app; another table set stays another realm{$verdict('realm override')}"
      );
      yield assert(
         assertion: ($rows['dropped user_roles'] ?? null) === true,
         description: "After A's user_roles is dropped A's checks deny without throwing, load() keeps the identity and B is unaffected{$verdict('dropped user_roles')}"
      );
      yield assert(
         assertion: ($rows['legacy shim'] ?? null) === true,
         description: "invalidate(\$Identity) drops the legacy rbac:{id} entry and invalidate() the legacy rbac tag{$verdict('legacy shim')}"
      );
      yield assert(
         assertion: ($rows['file before and after'] ?? null) === true,
         description: "An SQLite file names one realm before and after it exists, and under another spelling of its directory{$verdict('file before and after')}"
      );
      yield assert(
         assertion: ($rows['private databases'] ?? null) === true,
         description: "Two private SQLite databases (:memory:, the empty name) never share lists; resolvers over one SQL object (or its Transaction) do, within its process{$verdict('private databases')}"
      );
      yield assert(
         assertion: ($rows['transaction view'] ?? null) === true,
         description: "A resolver over a Transaction never caches its own view (no uncommitted grant and no pre-revoke snapshot reaches plain resolvers) and never answers from a cached list{$verdict('transaction view')}"
      );
   }
);
