<?php

use Bootgly\ACI\Tests\Suite\Test;


// ! The driver prefers ext-redis whenever it is loaded, which a plain socket stub cannot
//   answer. Clearing the ini scan directory drops the extension — but it also drops what
//   that directory configured: where the base ini disables functions and the scan dir is
//   what re-enables them, pcntl_fork goes away with it, and the stub forks.
//   Probe the requirements through the very command shape the stub will be driven with,
//   restoring only what this machine actually lost.
$PHP = PHP_BINARY;
$command = 'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP);
$inspect = ' -r ' . escapeshellarg(
   'echo extension_loaded("redis") ? "R" : "", function_exists("pcntl_fork") ? "F" : "";'
) . ' 2>/dev/null';

// @ Weakest shape first — a stronger one only earns its place when the probe still fails
$options = '';
$native = false;
foreach ([
   '',
   ' -d ' . escapeshellarg('disable_functions='),
   ' -d ' . escapeshellarg('disable_functions=') . ' -d ' . escapeshellarg('extension=pcntl')
] as $attempt) {
   if (trim((string) @shell_exec($command . $attempt . $inspect)) !== 'F') {
      continue;
   }

   $options = $attempt;
   $native = true;

   break;
}

return new Test(
   description: 'Cache(Redis): a hostile endpoint cannot inject an object the app never declared',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('shell_exec') === false
      || function_exists('pcntl_fork') === false
      || $native === false,
   test: function () use ($command, $options) {
      // ! A hostile stub Redis, driven in a child process without ext-redis
      $script = __DIR__ . '/redis-injection.php';
      $output = @shell_exec(
         $command . $options
            . ' -r ' . escapeshellarg('require $_SERVER["argv"][1] ?? "";')
            . ' ' . escapeshellarg($script) . ' 2>/dev/null'
      );
      $observed = json_decode(trim((string) $output), true);

      // ? The top-level probe already decided this machine can reach the native
      //   path, so a child that bails anyway is a broken harness, not a skipped
      //   environment. Reporting it as a pass would be a green suite covering
      //   nothing — fail loudly instead.
      if (is_array($observed) === true && isset($observed['skip']) === true) {
         yield assert(
            assertion: false,
            description: 'The probe said the native path was reachable, but the stub run bailed: '
               . (string) $observed['skip']
         );

         return;
      }

      yield assert(
         assertion: is_array($observed) && isset($observed['refused'], $observed['deep'], $observed['declared']),
         description: 'The stub-server probe produced no readable result: '
            . var_export($output, true)
      );

      if (is_array($observed) === false || isset($observed['refused']) === false) {
         return;
      }

      // @ There is no record wrapper on this path — the blob IS the value — so unpack()
      //   used to hand the caller whatever unserialize() built. The question is not what
      //   fetch() returned but whether the class was constructed at all: a gadget whose
      //   destructor already ran is not defended by rejecting the value afterwards.
      yield assert(
         assertion: $observed['refused']['constructed'] === 0
            && $observed['refused']['victim'] === true,
         description: 'An undeclared class is never constructed on the way out of Redis: '
            . var_export($observed['refused'], true)
      );

      // @ Refusing to CONSTRUCT the class is only half the contract. A driver
      //   that answers with the inert placeholder instead has moved the problem:
      //   the caller sees a hit, never recomputes, and the placeholder raises on
      //   first use. The documented answer is a miss.
      yield assert(
         assertion: $observed['refused']['returned'] === 'null',
         description: 'A value this driver cannot hand back is a miss, not a placeholder: '
            . var_export($observed['refused']['returned'], true)
      );

      // @ unserialize() downgrades a refused class wherever it sits, so the same
      //   answer has to hold one level down inside an array
      yield assert(
         assertion: $observed['deep']['returned'] === 'null'
            && $observed['deep']['inner'] === 'NULL',
         description: 'An undeclared class nested inside the value is a miss too: '
            . var_export($observed['deep'], true)
      );

      yield assert(
         assertion: $observed['declared']['class'] === 'RedisGadget',
         description: 'A declared class still round-trips through Redis: '
            . var_export($observed['declared'], true)
      );

      // @ A stored `false` and unserialize()'s failure signal are the same value, so the
      //   miss guard has to tell them apart by payload
      yield assert(
         assertion: $observed['control'] === 'ALICE' && $observed['negative'] === false,
         description: 'A scalar and a stored false still round-trip: '
            . var_export([$observed['control'], $observed['negative']], true)
      );
   }
);
