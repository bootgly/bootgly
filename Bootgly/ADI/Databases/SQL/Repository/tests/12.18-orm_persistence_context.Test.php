<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\PersistenceContext;


use function array_shift;
use function assert;
use function count;
use function end;
use function Key;
use DateTimeImmutable;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Awaiting;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository\LazyCollection;
use Bootgly\ADI\Databases\SQL\Repository\LazyReference;


#[Table('context_notes')]
class Note
{
   #[Key]
   public null|int $id = null;
   #[Column('owner_id')]
   public int $owner = 0;
}

#[Table('context_owners')]
class Owner
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var LazyCollection<Note> */
   #[Relation(Relations::HasMany, Note::class, 'id', 'owner_id', lazy: true)]
   public LazyCollection $Notes;
}

#[Table('context_drafts')]
class Draft
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var iterable<Note> */
   #[Relation(Relations::HasMany, Note::class, 'id', 'owner_id', name: 'notes', lazy: true)]
   public iterable $Notes;
   #[Relation(Relations::HasOne, Note::class, 'id', 'owner_id', lazy: true)]
   public null|LazyReference $Pinned = null;
}

#[Table('context_events')]
class Event
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public readonly string $amount;
   #[Column('at', nullable: true)]
   public readonly null|DateTimeImmutable $At;
}

#[Table('context_accounts')]
class Account
{
   #[Key('account_id')]
   public null|int $id = null;
   #[Column]
   public readonly string $email;
   #[Column]
   public string $name = '';

   public function __construct (string $email)
   {
      $this->email = $email;
   }
}

#[Table('context_settings')]
class Setting
{
   #[Key(generated: false)]
   public string $name = '';
   #[Column]
   public string $value = '';
}

class Bridge implements Awaiting
{
   public function await (Operation $Operation): Operation
   {
      return $Operation;
   }
}

class RecordingSQL extends SQL
{
   /** @var array<int,Result> */
   public array $Results = [];
   /** @var array<int,null|object> */
   public array $scopes = [];


   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      $this->scopes[] = $Scope;
      $Operation->resolve(array_shift($this->Results) ?? new Result('OK'));

      return $Operation;
   }
}


return new Test(
   description: 'ORM: save() and delete() register their own hydration context on every dialect',
   test: function () {
      $open = static fn (string $driver): RecordingSQL =>
         new RecordingSQL(['driver' => $driver, 'pool' => ['min' => 0, 'max' => 0]]);

      // # Bridgeless — a write names no relation, so a lazy model hydrates it
      foreach (['pgsql', 'mysql', 'sqlite'] as $driver) {
         $returning = $driver === 'pgsql';
         $Database = $open($driver);
         $Repository = $Database->map(Owner::class);

         $Owner = new Owner;
         $Owner->name = 'Ada';
         $Database->Results[] = $returning
            ? new Result('INSERT 0 1', [['id' => 3, 'name' => 'Ada']], ['id', 'name'], 1)
            : new Result('INSERT', [], [], 1, 3);
         $Mapped = $Repository->hydrate($Repository->save($Owner));

         yield assert(
            assertion: $Mapped->entity === $Owner && $Owner->id === 3,
            description: "{$driver}: hydrating a generated-key INSERT keys the entity passed to save()"
         );

         $Owner->name = 'Ada Lovelace';
         $Database->Results[] = $returning
            ? new Result('UPDATE 1', [['id' => 3, 'name' => 'Ada Lovelace']], ['id', 'name'], 1)
            : new Result('UPDATE 1', [], [], 1);
         $Mapped = $Repository->hydrate($Repository->save($Owner));

         yield assert(
            assertion: $Mapped->entity === ($returning ? $Owner : null),
            description: "{$driver}: hydrating an UPDATE needs no await bridge"
         );

         $Database->Results[] = new Result('DELETE 1', [], [], 1);
         $Mapped = $Repository->hydrate($Repository->delete($Owner));

         yield assert(
            assertion: $Mapped->entities === [],
            description: "{$driver}: hydrating a DELETE needs no await bridge"
         );

         // ? A foreign result keeps the guard: its lazy relations would need a bridge
         $message = null;
         try {
            $Repository->hydrate(new Result('SELECT 1', [['id' => 3, 'name' => 'Ada']], ['id', 'name']));
         }
         catch (RuntimeException $Exception) {
            $message = $Exception->getMessage();
         }

         yield assert(
            assertion: $message === 'ORM lazy loading requires an await bridge.',
            description: "{$driver}: a raw result of a lazy model still requires an await bridge"
         );
      }

      // # Bridged — the saved entity gets lazy wrappers, loaded through the save() scope
      foreach (['pgsql', 'mysql'] as $driver) {
         $Database = $open($driver);
         $Default = new stdClass;
         $Scope = new stdClass;
         $Repository = $Database->map(Owner::class, $Default, new Bridge);

         $Owner = new Owner;
         $Owner->name = 'Grace';
         $Database->Results[] = $driver === 'pgsql'
            ? new Result('INSERT 0 1', [['id' => 9, 'name' => 'Grace']], ['id', 'name'], 1)
            : new Result('INSERT', [], [], 1, 9);
         $Mapped = $Repository->hydrate($Repository->save($Owner, $Scope));

         yield assert(
            assertion: $Mapped->entity === $Owner && isSet($Owner->Notes) && $Owner->Notes instanceof LazyCollection,
            description: "{$driver}: a bridged save installs lazy wrappers on the entity passed to save()"
         );

         $Database->Results[] = new Result('SELECT 1', [['id' => 1, 'owner_id' => 9]], ['id', 'owner_id']);
         $notes = count($Owner->Notes);

         yield assert(
            assertion: $notes === 1 && end($Database->scopes) === $Scope,
            description: "{$driver}: the lazy relation of a saved entity loads through the save() scope"
         );

         // ? A relation the caller already filled is never replaced by a wrapper
         $Child = new Note;
         $Draft = new Draft;
         $Draft->name = 'Draft';
         $Draft->Notes = [$Child];
         $Database->Results[] = $driver === 'pgsql'
            ? new Result('INSERT 0 1', [['id' => 4, 'name' => 'Draft']], ['id', 'name'], 1)
            : new Result('INSERT', [], [], 1, 4);
         $Drafts = $Database->map(Draft::class, Awaiting: new Bridge);
         $Mapped = $Drafts->hydrate($Drafts->save($Draft));

         yield assert(
            assertion: $Mapped->entity === $Draft && $Draft->id === 4 && $Draft->Notes === [$Child]
               && $Draft->Pinned instanceof LazyReference,
            description: "{$driver}: a bridged save keeps the relation value the caller set and wraps a null one"
         );

         // ? Saving again keeps it too
         $Draft->name = 'Draft 2';
         $Database->Results[] = $driver === 'pgsql'
            ? new Result('UPDATE 1', [['id' => 4, 'name' => 'Draft 2']], ['id', 'name'], 1)
            : new Result('UPDATE 1', [], [], 1);
         $Drafts->hydrate($Drafts->save($Draft));

         yield assert(
            assertion: $Draft->Notes === [$Child],
            description: "{$driver}: a bridged UPDATE keeps the relation value the caller set on the saved entity"
         );
      }

      // # RETURNING under a renamed key column, into a readonly property already set
      $Database = $open('pgsql');
      $Accounts = $Database->map(Account::class);
      $Account = new Account('ada@example.test');
      $Account->name = 'Ada';
      $Database->Results[] = new Result(
         'INSERT 0 1',
         [['account_id' => 5, 'email' => 'ada@example.test', 'name' => 'Ada']],
         ['account_id', 'email', 'name'],
         1
      );
      $Mapped = $Accounts->hydrate($Accounts->save($Account));

      yield assert(
         assertion: $Mapped->entity === $Account && $Account->id === 5,
         description: 'pgsql: the RETURNING row keys the saved entity through its column name, keeping an equal readonly value'
      );

      // ? A RETURNING value the readonly property cannot take hydrates into a new entity
      $Other = new Account('grace@example.test');
      $Other->name = 'Grace';
      $Database->Results[] = new Result(
         'INSERT 0 1',
         [['account_id' => 6, 'email' => 'GRACE@example.test', 'name' => 'Grace Hopper']],
         ['account_id', 'email', 'name'],
         1
      );
      $Mapped = $Accounts->hydrate($Accounts->save($Other));
      $Stored = $Mapped->entity;

      yield assert(
         assertion: $Stored instanceof Account && $Stored !== $Other && $Stored->id === 6
            && $Stored->email === 'GRACE@example.test'
            && $Other->id === null && $Other->name === 'Grace'
            && $Accounts->Identity->fetch(Account::class, 6) === $Stored,
         description: 'pgsql: a readonly mismatch leaves the saved entity untouched and keys the returned row'
      );

      // # Rehydrating a readonly entity: equal values pass, a different one is refused
      $Database = $open('pgsql');
      $Events = $Database->map(Event::class);
      $row = ['id' => 7, 'amount' => '10.5', 'at' => new DateTimeImmutable('2026-10-31 10:00:00')];
      $Hydrate = static fn (array $row): null|object => $Events->hydrate(new Result('SELECT 1', [$row], ['id', 'amount', 'at']))->entity;
      $First = $Hydrate($row);
      $row['at'] = new DateTimeImmutable('2026-10-31 13:00:00+03:00');
      $Again = $Hydrate($row);
      $Partial = $Hydrate(['id' => 7, 'amount' => '10.5']);

      yield assert(
         assertion: $First instanceof Event && $Again === $First && $Partial === $First
            && $First->amount === '10.5' && $First->At == new DateTimeImmutable('2026-10-31 10:00:00'),
         description: 'A readonly entity rehydrates with an equal value, an equal instant or the column omitted'
      );

      $message = null;
      try {
         $Hydrate(['id' => 7, 'amount' => '10.50', 'at' => null]);
      }
      catch (RuntimeException $Exception) {
         $message = $Exception->getMessage();
      }

      yield assert(
         assertion: $message === 'ORM cannot overwrite a readonly property: ' . Event::class . '::$amount'
            && $First instanceof Event && $First->amount === '10.5' && $First->At instanceof DateTimeImmutable,
         description: 'A different readonly value is refused before any column of the row is written'
      );

      // # An application-assigned key is never backfilled from Result->inserted
      $Database = $open('sqlite');
      $Settings = $Database->map(Setting::class);
      $Setting = new Setting;
      $Setting->name = 'theme';
      $Setting->value = 'dark';
      $Database->Results[] = new Result('INSERT', [], [], 1, 1);
      $Mapped = $Settings->hydrate($Settings->save($Setting));

      yield assert(
         assertion: $Mapped->entities === [] && $Setting->name === 'theme',
         description: 'sqlite: the rowid of an INSERT never overwrites an application-assigned key'
      );
   }
);
