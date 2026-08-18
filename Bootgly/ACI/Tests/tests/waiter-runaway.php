<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/*
 * Drives the RunTimeout waiter against callables that fail, and prints one JSON
 * line holding both the verdict each assertion produced and the PID of every
 * process still executing this script afterwards.
 *
 * The waiter runs the callable in a forked child. A Throwable escaping that
 * child does NOT fail the assertion — it makes the child continue running the
 * rest of the process while the parent reports PASSED (TEXPF-1). So the two
 * things worth observing are the verdict and the process count, and they are
 * observed together here.
 *
 * It runs in its own PHP process on purpose: reproducing the defect means
 * letting a runaway child exist, and confined here it can only duplicate this
 * script instead of the whole test run.
 *
 * Nothing here is a test; the assertions live in
 * `1.7.2-Advanced_API-expectations-waiters-runaway.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../autoboot.php';

use Bootgly\ACI\Tests\Assertion;


$directory = $_SERVER['argv'][2] ?? '';
$witness = "{$directory}/witness";

$origin = posix_getpid();

/**
 * Run one waited callable and report what the assertion decided.
 *
 * @param array<int,mixed> $arguments
 *
 * @return array<string,string>
 */
$probe = static function (Closure $Callable, int $timeout, array $arguments = []): array {
   try {
      new Assertion(description: 'probe')
         ->expect($Callable)
         ->to->call(...$arguments)
         ->to->wait($timeout)
         ->assert();

      return ['verdict' => 'passed', 'reason' => ''];
   }
   catch (AssertionError $Error) {
      return ['verdict' => 'failed', 'reason' => $Error->getMessage()];
   }
};

// @@ The two ways a waited callable can die without ever running out of time.
//    The budgets are deliberately huge: since TESTS-WAIT the timeout is real,
//    and a tight one would let the parent SIGKILL the child before it reports
//    what it threw — turning these probes into timeout tests instead.
$verdicts = [];
// # A Throwable from the callable
$verdicts['throwing'] = $probe(
   static function (): void {
      throw new RuntimeException('boom');
   },
   10_000_000
);
// # Too few arguments — the same escape through a different catch
$verdicts['argcount'] = $probe(
   static fn (int $needed): int => $needed,
   10_000_000
);

// @@ Controls — ordinary waiting must be untouched
// # A callable that returns well inside its budget
$verdicts['control'] = $probe(
   static function (): void {
      usleep(10_000);
   },
   10_000_000
);
// # Arguments configured through call() still reach the callable
$verdicts['arguments'] = $probe(
   static function (int $a, int $b): void {
      if ($a + $b !== 3) {
         throw new RuntimeException('arguments did not arrive');
      }
   },
   10_000_000,
   [1, 2]
);

// ---

// @ Every process that reaches this line leaves a mark; the origin must be alone
file_put_contents($witness, posix_getpid() . "\n", FILE_APPEND);

// ? A runaway child reports nothing — the witness line above is its whole story
if (posix_getpid() !== $origin) {
   exit(0);
}

// ! Give any runaway the time it needs to reach the witness line
usleep(500_000);

$witnesses = array_values(array_filter(
   explode("\n", (string) @file_get_contents($witness))
));

echo json_encode([
   'origin'    => $origin,
   'verdicts'  => $verdicts,
   'witnesses' => $witnesses,
]), "\n";
