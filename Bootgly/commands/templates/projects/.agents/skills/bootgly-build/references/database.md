# SQL databases — ADI, for every platform

Tables, schema changes, seed data, ORM models, queries and transactions in a project under
`projects/<Name>/`. File names: [Organizational_structures.md](../../../../.agents/rules/Organizational_structures.md).
Pick the engine (SQLite: no server, one worker; MySQL; PostgreSQL) and **ask** if the user did not.
Working reference: the shipped `projects/Demo/HTTP_Server_CLI/` (`configs/database/`, `router/routes/Database.routes.php`).
Pinned source: `<kit>/Bootgly/Bootgly/ADI/Databases/SQL/`.

## 1. Configure: `configs/database/database.Config.php`
Directory, file and `scope:` are all `database`; the WPI `Database` response resource reads this scope (§5).
```php
<?php

use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\Config\Types;


return new Config(scope: 'database')
   ->Enabled->bind(key: 'DB_ENABLED', default: true, cast: Types::Boolean)
   ->Default->bind(key: 'DB_CONNECTION', default: 'sqlite')
   ->Connections
      ->SQLite
         ->Driver->bind(key: '', default: 'sqlite')
         ->Database->bind(key: 'DB_NAME', default: __DIR__ . '/../../database/app.sqlite')
         ->up()
      ->up();
```
- **MySQL:** a `->MySQL` block with `->Driver` (`'mysql'`), `->Host`, `->Port` (3306, `cast: Types::Integer`), `->Database`, `->Username`, `->Password` bound to `DB_HOST DB_PORT DB_NAME DB_USER DB_PASS`, and `Default` `'mysql'`. **PostgreSQL:** same keys in a `->PostgreSQL` block, driver `'pgsql'`, port 5432.
- `Default` must name a declared block, or the config throws `…missing the selected connection scope…`. TLS goes in
  the block: `->Secure->Mode->bind(key: 'DB_SSLMODE', default: 'prefer')->up()` (a local Docker MySQL on kits ≤ 1.0.2
  needs `'disable'`; see the Shop cookbook). Secrets stay in env keys or `configs/database/.env`, which you never commit.

## 2. Migrations (commands for §2 and §3)
```bash
bootgly project <Name> migrate create "create notes"   # database/migrations/<YmdHis>_create_notes.php
bootgly project <Name> migrate status                  # Applied / Local only (pending) / DB only
bootgly project <Name> migrate up                      # apply every pending one, as one batch
bootgly project <Name> migrate down 1                  # revert the last N (the number is required)
bootgly project <Name> seed create "notes"             # database/seeders/notes.php (a slug; never overwrites)
bootgly project <Name> seed run --dry-run              # print the SQL, execute nothing
bootgly project <Name> seed run [notes]                # all seeders in file order, or one
```
Fill the stub — `Up` and `Down` each return one query or a list of queries:
```php
<?php

use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;


return new Migration(
   Up: function (Migrating $Schema) {
      return $Schema->create('notes', function (Blueprint $Table): void {
         $Table->add('id', Types::BigInteger)->generate()->constrain(Keys::Primary);
         $Table->add('title', Types::String)->limit(120);
         $Table->add('body', Types::Text)->nullable = true;
         $Table->add('created_at', Types::Timestamp)->default = new Expression('CURRENT_TIMESTAMP');
      });
   },
   Down: fn (Migrating $Schema) => $Schema->drop('notes')
);
```
- Columns are `NOT NULL` by default; `nullable`/`default` are properties, assigned last. Types: `BigInteger Integer String Text Boolean Decimal Float Date Time Timestamp Timestamptz Json JsonB Uuid`; keys: `Primary Unique`.
- Foreign key: `->reference('users')` on the column; delete rules via `$Table->reference('team_id', 'teams', 'id')->delete(References::Cascade)`. Unique index: `$Schema->index('votes', ['poll_id', 'voter'], unique: true)` in the list.
- Schema changes go in a **new** migration (`$Schema->alter('notes', function (Blueprint $Table): void { … })`). Never edit or rename one that ran. `Down` reverses `Up`; MySQL DDL commits as it goes, PostgreSQL/SQLite roll back the list.
- `migrate sync` rewrites history without running anything and needs an interactive confirmation. Leave it to the user.
- To migrate and seed at start, as the cookbooks do, add these imports to `<Name>.Project.php` and build `$Database`
  inside `boot`, before the server or shell starts, from the `database` scope the `Database` resource reads. A CLI
  project opens its database the same way. SQLite: `workers: 1`; git-ignore `*.sqlite` and `*.lock*`.
```php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Runner as Migrations;
use Bootgly\ADI\Databases\SQL\Seed\Runner as Seeds;
use Bootgly\API\Environment\Configs\DatabaseConfig;

$Database = new SQL(new DatabaseConfig(BOOTGLY_PROJECT->Configs->get('database'))->configure());
new Migrations($Database, __DIR__ . '/database/migrations', __DIR__ . '/database/app.migrations.lock')->up();
new Seeds($Database, __DIR__ . '/database/seeders', __DIR__ . '/database/app.seeders.lock')->run();
```

## 3. Seeders
```php
<?php

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;


return new Seeder(
   // : Fixed ids + upsert — no ledger: every run re-executes the file, so it must be rerunnable
   Run: fn (SQL $Database, Seed $Seed) => $Database->table(new Identifier('notes'))
      ->insert()
      ->set(new Identifier('id'), 1, 2)
      ->set(new Identifier('title'), 'Welcome', 'Read the docs')
      ->upsert(new Identifier('id'))
);
```
Return one query, a list or `null`; no top-level `class`/`function`. `$Seed->fake('Email', seed: 1)` gives fake data.

## 4. Models (ORM): `projects/<Name>/Models/`, namespace = path
```php
<?php

namespace Shop\Models;


use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;


#[Table('orders')]
class Order
{
   #[Key]
   public null|int $id = null;
   #[Column('customer_name')]
   public string $customer = '';
   // # Relations — local property, foreign property (OrderItem::$order is #[Column('order_id')])
   /** @var array<int,OrderItem> */
   #[Relation(Relations::HasMany, OrderItem::class, 'id', 'order')]
   public array $Items = [];
}
```
Relations: `HasMany HasOne BelongsTo BelongsToMany`. DB-filled columns: `#[Column('created_at', insert: false, update: false)]`.
`DECIMAL` hydrates as a string; `TIMESTAMP` as `DateTimeImmutable` on MySQL/PostgreSQL but as a **string on SQLite**.

## 5. Query and transact in routes (WPI: `$Response->Database`)
A bare WPI project mounts the resource in its boot, beside its server `Configs`; a platform shell may mount it for
you (its build skill says so). Imports: `Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Configs` as `ResponseConfigs`,
`Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database` as `DatabaseResource`.
```php
$Server->configure(
   new Configs(host: '0.0.0.0', port: getenv('PORT') ? (int) getenv('PORT') : 8081, workers: 1),
   new ResponseConfigs(Resources: ['Database' => DatabaseResource::provide(__DIR__ . '/configs/')])
);
```
```php
$Result = $Response->Database->fetch('SELECT id, title FROM notes WHERE id = $1', [$id]); // ->rows ->row ->empty ->inserted
$Notes = $Response->Database->map(Note::class);
$Note = $Notes->hydrate($Response->Database->await($Notes->find($id)))->entity;    // null when missing
$Saved = $Notes->hydrate($Response->Database->await($Notes->save($Note)))->entity; // inserts when id is null
$page = $Response->Database->paginate(Note::class); // reads ?page/?limit, sets X-Total-Count + Link; ['items', 'pages', …]
```
- Raw placeholders: `$1, $2` (PostgreSQL, SQLite), `?` (MySQL). The Builder writes them for you: `table(new Identifier('notes'))
  ->select(…)->filter(new Identifier('id'), Operators::Equal, $id)`, plus `order()`, `join()`, `aggregate()`, `lock(Locks::Update)`; `update()`/`delete()` require a `filter()`.
- `->output(new Identifier('id'))` (`RETURNING`) is **PostgreSQL only**; MySQL/SQLite throw, so read `->inserted`.
- Relations: `$Notes->select()->filter(…)->load('Items')`. Outside a route (a command, a CLI project), build `$Database`
  as in §2 and follow the DBAL guide.
```php
// ! Use the callback's $Database (the transaction) for every statement; returning commits, throwing rolls back
$id = $Response->Database->transact(function (Transaction $Transaction, Database $Database) use ($title): int {
   $Result = $Database->fetch($Database->table(new Identifier('notes'))->insert()->set(new Identifier('title'), $title));
   return (int) $Result->inserted; // PostgreSQL: ->output(new Identifier('id')) and ->row['id']
});
```
Imports: `Bootgly\ADI\Databases\SQL\Transaction`, `Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database`.

## 6. Test it
Add a case to a registered suite ([Testing_guidelines.md](../../../../.agents/rules/Testing_guidelines.md); the bootgly-test skill runs the loop). It runs the real migrations in memory, under a lock path of its own:
```php
<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Runner as Migrations;


return new Test(
   description: 'Database: every migration applies on a fresh database',
   test: function () {
      // ! Throwaway in-memory database, never the project's real one, and a lock path of this run only
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $lock = sys_get_temp_dir() . '/notes-tests.' . bin2hex(random_bytes(6)) . '.lock';
      try {
         $applied = new Migrations($Database, __DIR__ . '/../../database/migrations', $lock)->up();
      }
      finally {
         // @ The runner deletes its lock but keeps the `.guard` file beside it
         foreach ([$lock, "{$lock}.guard"] as $file) {
            if (is_file($file) === true) {
               unlink($file);
            }
         }
      }
      yield assert(assertion: $applied !== [], description: 'the migrations apply');
   }
);
```
On SQLite, go on in the same case: `$Database->map(Note::class)`, `save()` a model, assert `->entity->id` is set. MySQL/PostgreSQL models: assert `Model::reflect(Order::class)` (`->table`, `->columns`, `->relations`), as the Polls cookbook does.

## Checks

On top of the Definition of done in the skill's `SKILL.md`:
- [ ] `bootgly project <Name> migrate status` shows `Local only 0`; `seed run --dry-run` prints the expected SQL.

## Go deeper
- Guides: https://docs.bootgly.com/guide/database-dbal/overview.md (config scope, drivers, the `Database` resource) · https://docs.bootgly.com/guide/database-migrations/overview.md · https://docs.bootgly.com/manual/ADI/Databases/SQL/Schema/Blueprint/overview.md (every column option) · https://docs.bootgly.com/guide/database-seeders/overview.md · https://docs.bootgly.com/guide/database-orm/overview.md · https://docs.bootgly.com/guide/database-queries/overview.md · https://docs.bootgly.com/guide/database-transactions/overview.md
- Cookbooks: https://docs.bootgly.com/cookbook/web/guestbook/overview.md (SQLite) · https://docs.bootgly.com/cookbook/web/shop/overview.md (MySQL, relations, `transact()` with row locks) · https://docs.bootgly.com/cookbook/web/polls/overview.md (PostgreSQL, `RETURNING`, upsert, model tests)
