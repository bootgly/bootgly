<?php

namespace Bootgly\API\Security\Tests\SecurityStoreUnrecordedFailure;


use function array_map;
use function assert;
use function json_encode;
use ReflectionClass;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


/**
 * SQL seam that distinguishes an unrecorded await failure from an Operation
 * that already carries the driver's failure.
 */
final class StoreFailureSQL extends SQLDatabase
{
   /** Failure emitted only by await(), never copied onto the Operation. */
   public const string UNRECORDED = 'Injected database readiness failure.';
   /** Failure recorded on the Operation before the store sees it. */
   public const string RECORDED = 'Injected recorded database failure.';

   /** Number of calls that reached await(). */
   public int $awaits = 0;
   /** @var array<int,null|string> Operation errors observed on entry to await(). */
   public array $awaitedErrors = [];
   /** @var array<int,Operation> Operations returned by query(). */
   public array $Operations = [];


   /**
    * Build a connection-free SQL facade in recorded or unrecorded mode.
    */
   public function __construct (
      private bool $recorded,
      private bool $unfinished = false,
      private bool $failed = false,
      private bool $delayed = false
   )
   {
      parent::__construct([
         'driver' => 'pgsql',
         'pool' => ['min' => 0, 'max' => 0],
      ]);
   }

   /**
    * Return a synthetic Operation without consulting a driver or connection.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (
      string|Builder|Query $query,
      array $parameters = [],
      null|object $Scope = null
   ): Operation
   {
      $Operation = new Operation(null, 'SELECT 1');

      if ($this->recorded && $this->delayed === false) {
         $Operation->fail(self::RECORDED);
      }
      elseif ($this->failed) {
         // ! An enum state is not a recorded failure. Only error carries the
         //   cause that the stores may convert into their fail-closed result.
         $Operation->state = OperationStates::Failed;
      }

      $this->Operations[] = $Operation;

      return $Operation;
   }

   /**
    * Apply the configured await outcome to the supplied Operation.
    */
   public function await (Operation $Operation): Operation
   {
      $this->awaits++;
      $this->awaitedErrors[] = $Operation->error;

      if ($this->unfinished) {
         return $Operation;
      }
      if ($this->recorded && $this->delayed) {
         $Operation->fail(self::RECORDED);

         throw new RuntimeException(self::RECORDED);
      }

      throw new RuntimeException(self::UNRECORDED);
   }
}


return new Test(
   description: 'Security stores propagate unrecorded database await failures',
   test: function () {
      /** @var Password $Password */
      $Password = (new ReflectionClass(Password::class))->newInstanceWithoutConstructor();

      /**
       * Invoke one public store method without letting one failure prevent the
       * other stores from exercising the same private execute() contract.
       *
       * @return array{exception:null|string,value:mixed}
       */
      $probe = static function (callable $call): array {
         try {
            return [
               'exception' => null,
               'value' => $call(),
            ];
         }
         catch (Throwable $Throwable) {
            return [
               'exception' => $Throwable::class . ': ' . $Throwable->getMessage(),
               'value' => null,
            ];
         }
      };

      // @ Control: a driver-recorded error stays on the Operation. The stores
      //   keep their existing fail-closed public results and never call await().
      $Recorded = new StoreFailureSQL(recorded: true);
      $RecordedUsers = new Users($Recorded, $Password);
      $RecordedTokens = new Tokens($Recorded);
      $RecordedTrust = new Trust($Recorded);

      $recorded = [
         'users' => $probe(static fn (): mixed => $RecordedUsers->fetch('user@example.test')),
         'tokens' => $probe(static fn (): mixed => $RecordedTokens->revoke('7', Purposes::Recovery)),
         'trust' => $probe(static fn (): mixed => $RecordedTrust->revoke('7')),
      ];
      $recordedErrors = array_map(
         static fn (Operation $Operation): null|string => $Operation->error,
         $Recorded->Operations
      );

      // @ Control the catch branch too: a failure recorded while await() is
      //   running keeps the existing fail-closed store results.
      $RecordedAwait = new StoreFailureSQL(recorded: true, delayed: true);
      $RecordedAwaitUsers = new Users($RecordedAwait, $Password);
      $RecordedAwaitTokens = new Tokens($RecordedAwait);
      $RecordedAwaitTrust = new Trust($RecordedAwait);
      $recordedAwait = [
         'users' => $probe(static fn (): mixed => $RecordedAwaitUsers->fetch('user@example.test')),
         'tokens' => $probe(static fn (): mixed => $RecordedAwaitTokens->revoke('7', Purposes::Recovery)),
         'trust' => $probe(static fn (): mixed => $RecordedAwaitTrust->revoke('7')),
      ];

      // ! Vulnerable path: await() throws while Operation::error remains null.
      //   Every public store must propagate that RuntimeException instead of
      //   converting it into "not found" or "zero rows affected".
      $Unrecorded = new StoreFailureSQL(recorded: false);
      $UnrecordedUsers = new Users($Unrecorded, $Password);
      $UnrecordedTokens = new Tokens($Unrecorded);
      $UnrecordedTrust = new Trust($Unrecorded);

      $unrecorded = [
         'users' => $probe(static fn (): mixed => $UnrecordedUsers->fetch('user@example.test')),
         'tokens' => $probe(static fn (): mixed => $UnrecordedTokens->revoke('7', Purposes::Recovery)),
         'trust' => $probe(static fn (): mixed => $UnrecordedTrust->revoke('7')),
      ];

      // ! A bridge returning the same unfinished, error-less Operation is the
      //   other way a store could manufacture a credential/token verdict.
      $Unfinished = new StoreFailureSQL(recorded: false, unfinished: true);
      $UnfinishedUsers = new Users($Unfinished, $Password);
      $UnfinishedTokens = new Tokens($Unfinished);
      $UnfinishedTrust = new Trust($Unfinished);
      $unfinished = [
         'users' => $probe(static fn (): mixed => $UnfinishedUsers->fetch('user@example.test')),
         'tokens' => $probe(static fn (): mixed => $UnfinishedTokens->revoke('7', Purposes::Recovery)),
         'trust' => $probe(static fn (): mixed => $UnfinishedTrust->revoke('7')),
      ];

      // ! State alone cannot authorize swallowing the exception: a malformed
      //   bridge may label the operation Failed without recording any cause.
      $StateOnly = new StoreFailureSQL(recorded: false, failed: true);
      $StateOnlyUsers = new Users($StateOnly, $Password);
      $StateOnlyTokens = new Tokens($StateOnly);
      $StateOnlyTrust = new Trust($StateOnly);
      $stateOnly = [
         'users' => $probe(static fn (): mixed => $StateOnlyUsers->fetch('user@example.test')),
         'tokens' => $probe(static fn (): mixed => $StateOnlyTokens->revoke('7', Purposes::Recovery)),
         'trust' => $probe(static fn (): mixed => $StateOnlyTrust->revoke('7')),
      ];

      yield assert(
         assertion: $recorded === [
            'users' => ['exception' => null, 'value' => null],
            'tokens' => ['exception' => null, 'value' => null],
            'trust' => ['exception' => null, 'value' => null],
         ]
            && $Recorded->awaits === 0
            && $recordedErrors === [
               StoreFailureSQL::RECORDED,
               StoreFailureSQL::RECORDED,
               StoreFailureSQL::RECORDED,
            ],
         description: 'Already-recorded Operation errors retain the stores\' fail-closed results; '
            . json_encode([
               'results' => $recorded,
               'awaits' => $Recorded->awaits,
               'errors' => $recordedErrors,
            ])
      );

      yield assert(
         assertion: $recordedAwait === [
            'users' => ['exception' => null, 'value' => null],
            'tokens' => ['exception' => null, 'value' => null],
            'trust' => ['exception' => null, 'value' => null],
         ]
            && $RecordedAwait->awaits === 3
            && $RecordedAwait->awaitedErrors === [null, null, null],
         description: 'Failures recorded during await retain the stores\' fail-closed results; '
            . json_encode([
               'results' => $recordedAwait,
               'awaits' => $RecordedAwait->awaits,
               'errors_before_throw' => $RecordedAwait->awaitedErrors,
            ])
      );

      $expected = RuntimeException::class . ': ' . StoreFailureSQL::UNRECORDED;

      yield assert(
         assertion: $unrecorded === [
            'users' => ['exception' => $expected, 'value' => null],
            'tokens' => ['exception' => $expected, 'value' => null],
            'trust' => ['exception' => $expected, 'value' => null],
         ]
            && $Unrecorded->awaits === 3
            && $Unrecorded->awaitedErrors === [null, null, null],
         description: 'USR-3/TOK-1 CONFIRMED: Users, Tokens and Trust swallowed an unrecorded '
            . 'database await failure; ' . json_encode([
               'results' => $unrecorded,
               'awaits' => $Unrecorded->awaits,
               'errors_at_throw' => $Unrecorded->awaitedErrors,
            ])
      );

      $unfinishedFailure = RuntimeException::class . ': Database operation did not finish.';

      yield assert(
         assertion: $unfinished === [
            'users' => ['exception' => $unfinishedFailure, 'value' => null],
            'tokens' => ['exception' => $unfinishedFailure, 'value' => null],
            'trust' => ['exception' => $unfinishedFailure, 'value' => null],
         ]
            && $Unfinished->awaits === 3
            && $Unfinished->awaitedErrors === [null, null, null],
         description: 'An await bridge cannot turn an unfinished error-less Operation into a store result; '
            . json_encode([
               'results' => $unfinished,
               'awaits' => $Unfinished->awaits,
               'errors_at_return' => $Unfinished->awaitedErrors,
            ])
      );

      yield assert(
         assertion: $stateOnly === [
            'users' => ['exception' => $expected, 'value' => null],
            'tokens' => ['exception' => $expected, 'value' => null],
            'trust' => ['exception' => $expected, 'value' => null],
         ]
            && $StateOnly->awaits === 3
            && $StateOnly->awaitedErrors === [null, null, null],
         description: 'A Failed enum without Operation->error cannot hide an await exception; '
            . json_encode([
               'results' => $stateOnly,
               'awaits' => $StateOnly->awaits,
               'errors_at_throw' => $StateOnly->awaitedErrors,
            ])
      );
   }
);
