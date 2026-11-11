<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\ADI\Databases\SQL\Transaction\Events;


[$open, $close, $supported] = require __DIR__ . '/0.peer.php';


return new Test(
   description: 'SQL::transact(): the statements a PostgreSQL and a MySQL server receive, in order, on every way out',
   skip: $supported === false,
   test: function () use ($open, $close) {
      $census = static function (SQL $Database): array {
         $Pool = $Database->Pool;
         $locked = new ReflectionProperty($Pool, 'locked')->getValue($Pool);

         return [count($locked), count($Pool->busy), count($Pool->idle), count($Pool->pending)];
      };
      /**
       * Run one unit of work against a fresh peer and report what happened:
       * the result or the throwable, the transaction's final depth and
       * operation, the pool census and the statements the peer received.
       */
      $run = static function (string $driver, Closure $Work, array $errors = []) use ($open, $close, $census): array {
         [$Database, $Peer] = $open($driver, $errors);
         $Held = null;
         $result = null;
         $Caught = null;

         try {
            $result = $Database->transact(static function (Transaction $Transaction) use ($Database, $Work, &$Held): mixed {
               $Held = $Transaction;

               return $Work($Transaction, $Database);
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         return [
            'result' => $result,
            'caught' => $Caught,
            'depth' => $Held?->depth,
            'operation' => [$Held?->Operation?->SQL, $Held?->Operation?->finished, $Held?->Operation?->error],
            'census' => $census($Database),
            'wire' => $close($Peer),
         ];
      };
      $insert = "INSERT INTO marks (tag) VALUES ('a')";
      $mark = static fn (Transaction $Transaction): mixed => $Transaction->await($Transaction->query($insert));
      $Failure = new LogicException('the work failed');

      try {
         foreach (['pgsql' => '"bootgly_0"', 'mysql' => '`bootgly_0`'] as $driver => $savepoint) {
            // # Commit + result passthrough
            $state = $run($driver, static function (Transaction $Transaction) use ($mark): string {
               $mark($Transaction);

               return 'done';
            });

            yield assert(
               assertion: $state['result'] === 'done' && $state['caught'] === null
                  && $state['wire'] === ['BEGIN', $insert, 'COMMIT']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: BEGIN, the work, COMMIT — and the connection idle again, found: " . json_encode($state)
            );

            // # Rollback on throw
            $state = $run($driver, static function (Transaction $Transaction) use ($mark, $Failure): void {
               $mark($Transaction);

               throw $Failure;
            });

            yield assert(
               assertion: $state['caught'] === $Failure
                  && $state['wire'] === ['BEGIN', $insert, 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a throwing work ends in ROLLBACK and its own throwable, found: " . json_encode($state['wire'])
            );

            // # A level the work left open — the top-level COMMIT is on the wire
            $state = $run($driver, static function (Transaction $Transaction) use ($mark): void {
               $Transaction->await($Transaction->begin());
               $mark($Transaction);
            });

            yield assert(
               assertion: $state['caught'] === null
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "RELEASE SAVEPOINT {$savepoint}", 'COMMIT']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a savepoint the work left open is released AND the transaction committed, found: " . json_encode($state['wire'])
            );

            // # Nesting — a savepoint, rolled back alone when the nested work throws
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, $Failure): string {
               $mark($Transaction);

               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark, $Failure): void {
                     $mark($Transaction);

                     throw $Failure;
                  });
               }
               catch (LogicException) {
                  // ? The outer unit of work goes on.
               }

               $mark($Transaction);

               return 'outer';
            });

            yield assert(
               assertion: $state['result'] === 'outer'
                  && $state['wire'] === ['BEGIN', $insert, "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}", $insert, 'COMMIT']
                  && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested call is a savepoint of the open transaction — no second BEGIN, found: " . json_encode($state['wire'])
            );

            // # A nested work that rolled its own savepoint back and returned:
            //   the nested call sends no COMMIT of its own
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark): void {
               $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                  $mark($Transaction);
                  $Transaction->await($Transaction->rollback());
               });

               $mark($Transaction);
            });

            yield assert(
               assertion: $state['caught'] === null
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}", $insert, 'COMMIT']
                  && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested call that unwound its own level ends nothing of its caller's, found: " . json_encode($state['wire'])
            );

            // # A nested level whose ROLLBACK TO the server refuses: the level
            //   may still hold its writes, so the WHOLE transaction is ended —
            //   an outer work that swallows the nested failure commits nothing.
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, $Failure): string {
               $mark($Transaction);

               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark, $Failure): void {
                     $mark($Transaction);

                     throw $Failure;
                  });
               }
               catch (LogicException) {
                  // ? The outer unit of work tries to go on.
               }

               $mark($Transaction);

               return 'outer';
            }, ['ROLLBACK TO' => 'the savepoint does not exist']);

            yield assert(
               assertion: $state['result'] === null
                  && $state['caught']?->getMessage() === 'SQL transaction is not active.'
                  && $state['wire'] === ['BEGIN', $insert, "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}", 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a savepoint rollback the server refuses ends the whole transaction — nothing is committed, found: "
                  . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire'], $state['depth'], $state['census']])
            );

            // # A nested level whose RELEASE the server refuses: the same —
            //   the level is neither released nor rolled back for sure.
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                     $mark($Transaction);
                  });
               }
               catch (RuntimeException) {
                  // ? The outer unit of work tries to go on.
               }

               $mark($Transaction);

               return 'outer';
            }, ['RELEASE' => 'the savepoint does not exist']);

            yield assert(
               assertion: $state['result'] === null
                  && $state['caught']?->getMessage() === 'SQL transaction is not active.'
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "RELEASE SAVEPOINT {$savepoint}", 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a savepoint release the server refuses ends the whole transaction, found: "
                  . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire'], $state['depth'], $state['census']])
            );

            // # The same two refusals when the nested WORK closes its own
            //   level: whoever sent the close, the level is in doubt.
            foreach (['rollback' => 'ROLLBACK TO', 'commit' => 'RELEASE'] as $close => $statement) {
               $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, $close): string {
                  $mark($Transaction);

                  try {
                     $Database->transact(static function (Transaction $Transaction) use ($mark, $close): void {
                        $mark($Transaction);
                        $Transaction->await($Transaction->{$close}());
                     });
                  }
                  catch (RuntimeException) {
                     // ? The outer unit of work tries to go on.
                  }

                  $mark($Transaction);

                  return 'outer';
               }, [$statement => 'the savepoint does not exist']);

               yield assert(
                  assertion: $state['result'] === null
                     && $state['caught']?->getMessage() === 'SQL transaction is not active.'
                     && $state['wire'] === ['BEGIN', $insert, "SAVEPOINT {$savepoint}", $insert, "{$statement} SAVEPOINT {$savepoint}", 'ROLLBACK']
                     && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
                  description: "{$driver}: a {$statement} SAVEPOINT the nested work sent itself and the server refuses ends the whole transaction, found: "
                     . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire'], $state['depth'], $state['census']])
               );
            }

            // # The same again when the nested work keeps the refusal to
            //   itself and returns: the nested call reports the close's error
            //   instead of a success over a level in doubt.
            $refusal = null;
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, &$refusal): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                     $mark($Transaction);

                     try {
                        $Transaction->await($Transaction->rollback());
                     }
                     catch (RuntimeException) {
                        // ? Swallowed by the nested work.
                     }
                  });
               }
               catch (RuntimeException $Exception) {
                  $refusal = $Exception->getMessage();
               }

               $mark($Transaction);

               return 'outer';
            }, ['ROLLBACK TO' => 'the savepoint does not exist']);

            yield assert(
               assertion: str_contains((string) $refusal, 'the savepoint does not exist')
                  && $state['result'] === null
                  && $state['caught']?->getMessage() === 'SQL transaction is not active.'
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}", 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested work that swallows its own refused close is not reported as a success, found: "
                  . json_encode([$refusal, $state['result'], $state['caught']?->getMessage(), $state['wire']])
            );

            // # Control: the close of an INNER level the nested work opened is
            //   refused — the call's own savepoint is still there, and rolling
            //   back to it is enough: the outer transaction goes on.
            $second = str_replace('0', '1', $savepoint);
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                     $Transaction->await($Transaction->begin());
                     $mark($Transaction);
                     $Transaction->await($Transaction->commit());
                  });
               }
               catch (RuntimeException) {
                  // ? The outer unit of work goes on.
               }

               $mark($Transaction);

               return 'outer';
            }, ['RELEASE' => 'the savepoint does not exist']);

            yield assert(
               assertion: $state['result'] === 'outer' && $state['caught'] === null
                  && $state['wire'] === [
                     'BEGIN', "SAVEPOINT {$savepoint}", "SAVEPOINT {$second}", $insert,
                     "RELEASE SAVEPOINT {$second}", "ROLLBACK TO SAVEPOINT {$savepoint}", $insert, 'COMMIT',
                  ]
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a refused close above the call's own savepoint is undone by rolling back to it, found: "
                  . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire']])
            );

            // # Control: a statement that fails AFTER the nested work closed
            //   its level cleanly is an ordinary failure of the outer level.
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                     $mark($Transaction);
                     $Transaction->await($Transaction->rollback());
                     $Transaction->await($Transaction->query("UPDATE marks SET tag = 'b'"));
                  });
               }
               catch (RuntimeException) {
                  // ? The outer unit of work goes on.
               }

               $mark($Transaction);

               return 'outer';
            }, ['UPDATE' => 'a statement the server refuses']);

            yield assert(
               assertion: $state['result'] === 'outer' && $state['caught'] === null
                  && $state['wire'] === [
                     'BEGIN', "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}",
                     "UPDATE marks SET tag = 'b'", $insert, 'COMMIT',
                  ]
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a failed statement after a clean close is not a level in doubt, found: "
                  . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire']])
            );

            // # Control: a nested work that rolled its own level back — cleanly
            //   — and then threw leaves a healthy outer transaction alone.
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, $Failure): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark, $Failure): void {
                     $mark($Transaction);
                     $Transaction->await($Transaction->rollback());

                     throw $Failure;
                  });
               }
               catch (LogicException) {
                  // ? The outer unit of work goes on.
               }

               $mark($Transaction);

               return 'outer';
            });

            yield assert(
               assertion: $state['result'] === 'outer' && $state['caught'] === null
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}", $insert, 'COMMIT']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested work that closed its own level and then threw costs the outer transaction nothing, found: "
                  . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire']])
            );

            // # Control: a Commit listener that throws at the nested level —
            //   nothing was sent for that level yet, so it alone is rolled back.
            Emitter::$Instance = new Emitter;
            Emitter::$Instance->listen(Events::Commit, static function (Emission $Emission): void {
               if ($Emission->payload[0]->depth > 1) {
                  throw new RuntimeException('a broken audit listener');
               }
            });

            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                     $mark($Transaction);
                  });
               }
               catch (RuntimeException) {
                  // ? The outer unit of work goes on.
               }

               $mark($Transaction);

               return 'outer';
            });

            Emitter::$Instance = new Emitter;

            yield assert(
               assertion: $state['result'] === 'outer' && $state['caught'] === null
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "ROLLBACK TO SAVEPOINT {$savepoint}", $insert, 'COMMIT']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a Commit listener that throws at a nested level rolls that level back alone, found: "
                  . json_encode([$state['result'], $state['caught']?->getMessage(), $state['wire']])
            );

            // # A throwing work that left two levels open: one rollback per
            //   level, innermost first, each announced — never one ROLLBACK
            //   for all of them.
            $heard = 0;
            Emitter::$Instance = new Emitter;
            Emitter::$Instance->listen(Events::Rollback, static function () use (&$heard): void {
               $heard++;
            });

            $state = $run($driver, static function (Transaction $Transaction) use ($Failure): void {
               $Transaction->await($Transaction->begin());
               $Transaction->await($Transaction->begin());

               throw $Failure;
            });

            Emitter::$Instance = new Emitter;

            yield assert(
               assertion: $state['caught'] === $Failure && $heard === 3
                  && $state['wire'] === [
                     'BEGIN', "SAVEPOINT {$savepoint}", "SAVEPOINT {$second}",
                     "ROLLBACK TO SAVEPOINT {$second}", "ROLLBACK TO SAVEPOINT {$savepoint}", 'ROLLBACK',
                  ]
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: every level the work left open is rolled back on its own, found: "
                  . json_encode([$heard, $state['wire']])
            );

            // # A nested call refused at its savepoint — the outer work has a
            //   statement in flight — opened nothing: that statement stays the
            //   outer work's, which awaits it and commits.
            $ran = false;
            $refusal = null;
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($insert, &$ran, &$refusal): string {
               $Pending = $Transaction->query($insert);

               try {
                  $Database->transact(static function () use (&$ran): void {
                     $ran = true;
                  });
               }
               catch (RuntimeException $Exception) {
                  $refusal = $Exception->getMessage();
               }

               $Transaction->await($Pending);

               return 'outer';
            });

            yield assert(
               assertion: $ran === false && $refusal === 'SQL transaction operation is still active.'
                  && $state['result'] === 'outer' && $state['caught'] === null
                  && $state['wire'] === ['BEGIN', $insert, 'COMMIT']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested call that never began leaves the outer work's statement alone, found: "
                  . json_encode([$ran, $refusal, $state['result'], $state['caught']?->getMessage(), $state['wire']])
            );

            // # The same when what the outer work left in flight is the close
            //   of a level of its own: the refused nested call must not take
            //   that close for a level of ITS in doubt.
            foreach (['commit' => 'RELEASE', 'rollback' => 'ROLLBACK TO'] as $close => $statement) {
               $ran = false;
               $refusal = null;
               $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, $close, &$ran, &$refusal): string {
                  $Transaction->await($Transaction->begin());
                  $mark($Transaction);
                  $Pending = $Transaction->{$close}();

                  try {
                     $Database->transact(static function () use (&$ran): void {
                        $ran = true;
                     });
                  }
                  catch (RuntimeException $Exception) {
                     $refusal = $Exception->getMessage();
                  }

                  $Transaction->await($Pending);

                  return 'outer';
               });

               yield assert(
                  assertion: $ran === false && $refusal === 'SQL transaction operation is still active.'
                     && $state['result'] === 'outer' && $state['caught'] === null
                     && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, "{$statement} SAVEPOINT {$savepoint}", 'COMMIT']
                     && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
                  description: "{$driver}: a nested call refused while the outer work's own {$statement} is in flight ends nothing, found: "
                     . json_encode([$ran, $refusal, $state['result'], $state['caught']?->getMessage(), $state['wire']])
               );
            }

            // # A nested work that returns with the close of its level still
            //   in flight: nobody knows how it ended, so the nested call says
            //   so and the whole transaction ends.
            $refusal = null;
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($mark, &$refusal): string {
               try {
                  $Database->transact(static function (Transaction $Transaction) use ($mark): void {
                     $mark($Transaction);
                     $Transaction->rollback();
                  });
               }
               catch (RuntimeException $Exception) {
                  $refusal = $Exception->getMessage();
               }

               return 'outer';
            });

            yield assert(
               assertion: $refusal === 'SQL transaction operation is still active.'
                  && $state['result'] === null
                  && $state['caught']?->getMessage() === 'SQL transaction is not active.'
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert, 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested work that returns with its own close un-awaited is not a success, found: "
                  . json_encode([$refusal, $state['result'], $state['caught']?->getMessage(), $state['wire'], $state['census']])
            );

            // # Failed BEGIN — thrown, the work never runs, nothing to unwind
            $ran = false;
            $state = $run($driver, static function () use (&$ran): void {
               $ran = true;
            }, ['BEGIN' => 'the server refused to start a transaction']);

            yield assert(
               assertion: $ran === false
                  && $state['caught'] instanceof RuntimeException
                  && str_contains($state['caught']->getMessage(), 'the server refused to start a transaction')
                  && $state['wire'] === ['BEGIN']
                  && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a BEGIN the server refuses is thrown before the work runs and reserves nothing, found: "
                  . json_encode([$ran, $state['caught']?->getMessage(), $state['wire'], $state['census']])
            );

            // # Failed COMMIT — its error is rethrown, never a silent success
            $state = $run($driver, static function (Transaction $Transaction) use ($mark): string {
               $mark($Transaction);

               return 'never returned';
            }, ['COMMIT' => 'could not serialize access']);

            yield assert(
               assertion: $state['result'] === null
                  && $state['caught'] instanceof RuntimeException
                  && str_contains($state['caught']->getMessage(), 'could not serialize access')
                  && $state['wire'] === ['BEGIN', $insert, 'COMMIT']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a COMMIT the server refuses is thrown and the reservation released, found: "
                  . json_encode([$state['caught']?->getMessage(), $state['wire'], $state['census']])
            );

            // # A statement left un-awaited at depth 2: rollback() of that level
            //   is refused, so the whole transaction is aborted — the statement
            //   never reached the wire and the session is kept.
            $state = $run($driver, static function (Transaction $Transaction) use ($insert, $Failure): void {
               $Transaction->await($Transaction->begin());
               $Transaction->query($insert);

               throw $Failure;
            });

            yield assert(
               assertion: $state['caught'] === $Failure
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a nested level with a statement in flight still ends in the top-level ROLLBACK, found: "
                  . json_encode([$state['wire'], $state['depth'], $state['census']])
            );

            // # The same, with the statement already on the wire: it is
            //   withdrawn, which drops the session — the server rolls back.
            $state = $run($driver, static function (Transaction $Transaction, SQL $Database) use ($insert, $Failure): void {
               $Transaction->await($Transaction->begin());
               $Database->advance($Transaction->query($insert));

               throw $Failure;
            });

            yield assert(
               assertion: $state['caught'] === $Failure
                  && $state['wire'] === ['BEGIN', "SAVEPOINT {$savepoint}", $insert]
                  && $state['depth'] === 0
                  && $state['operation'][1] === true
                  && $state['census'] === [0, 0, 0, 0],
               description: "{$driver}: a statement overtaken on the wire costs the session, never the reservation, found: "
                  . json_encode([$state['wire'], $state['depth'], $state['operation'], $state['census']])
            );

            // # A statement the work returned without awaiting: the COMMIT is
            //   refused, reported, and the transaction rolled back.
            $state = $run($driver, static function (Transaction $Transaction) use ($insert): void {
               $Transaction->query($insert);
            });

            yield assert(
               assertion: $state['caught']?->getMessage() === 'SQL transaction operation is still active.'
                  && $state['wire'] === ['BEGIN', 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a work that returns with a statement in flight is not committed, found: "
                  . json_encode([$state['caught']?->getMessage(), $state['wire']])
            );

            // # A throwing Rollback listener: the ROLLBACK still reaches the server
            Emitter::$Instance = new Emitter;
            Emitter::$Instance->listen(Events::Rollback, static function (): void {
               throw new RuntimeException('a broken audit listener');
            });

            $state = $run($driver, static function (Transaction $Transaction) use ($mark, $Failure): void {
               $mark($Transaction);

               throw $Failure;
            });

            Emitter::$Instance = new Emitter;

            yield assert(
               assertion: $state['caught'] === $Failure
                  && $state['wire'] === ['BEGIN', $insert, 'ROLLBACK']
                  && $state['depth'] === 0 && $state['census'] === [0, 0, 1, 0],
               description: "{$driver}: a Rollback listener that throws cannot keep the ROLLBACK off the wire, found: "
                  . json_encode([$state['caught']?->getMessage(), $state['wire'], $state['depth'], $state['census']])
            );
         }
      }
      finally {
         Emitter::$Instance = new Emitter;
      }
   }
);
