<?php

use Bootgly\ACI\Tests\Suite\Test;


// ! The driver prefers ext-redis whenever it is loaded, which makes the native RESP path
//   unreachable in-process. Clearing the ini scan directory usually drops the extension;
//   probe a child to find out whether this machine can reach the path at all.
$PHP = PHP_BINARY;
$probe = @shell_exec(
   'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP)
      . ' -r ' . escapeshellarg('echo extension_loaded("redis") ? "1" : "0";') . ' 2>/dev/null'
);
$native = trim((string) $probe) === '0';

return new Test(
   description: 'Queues(Redis): a failed command never lets reserve() lose the job it removed',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('shell_exec') === false
      || function_exists('pcntl_fork') === false
      || $native === false,
   test: function () use ($PHP) {
      // ! A scripted stub Redis, driven in a child process without ext-redis
      $script = __DIR__ . '/redis-desync.php';
      $output = @shell_exec(
         'PHP_INI_SCAN_DIR= ' . escapeshellarg($PHP)
            . ' -r ' . escapeshellarg('require $_SERVER["argv"][1] ?? "";')
            . ' ' . escapeshellarg($script) . ' 2>/dev/null'
      );
      $observed = json_decode(trim((string) $output), true);

      // ? The child refused the run (it still had ext-redis, or no pcntl) — nothing to
      //   assert, and reporting a failure would only be reporting the environment
      if (is_array($observed) === true && isset($observed['skip']) === true) {
         yield assert(
            assertion: true,
            description: 'Stub server not driven: ' . (string) $observed['skip']
         );

         return;
      }

      yield assert(
         assertion: is_array($observed)
            && isset($observed['loss'], $observed['stall'], $observed['surplus'], $observed['control']),
         description: 'The stub-server probe produced no readable result: '
            . var_export($output, true)
      );

      if (is_array($observed) === false || isset($observed['loss'], $observed['stall']) === false) {
         return;
      }

      // @ One slow first reply with 'timeout' => 0.5 — a value the config API accepts.
      //   The seconds-only stream_set_timeout() used to collapse it to zero, and the
      //   abandoned reply became the next command's answer, so reserve() read the ZREM
      //   reply as its candidate list: the job was removed and never delivered (QUEUE-1)
      $loss = $observed['loss'];
      $delivered = $loss['first']['job'] === true || $loss['second']['job'] === true;
      $removals = substr_count($loss['served'], 'ZREM');

      yield assert(
         assertion: $loss['first']['throw'] === null,
         description: 'A sub-second timeout must not abort a reply that arrives in time: '
            . var_export($loss['first'], true)
      );
      yield assert(
         assertion: $delivered === true,
         description: 'The job the server handed over must reach the worker: '
            . var_export($loss, true)
      );
      yield assert(
         assertion: $removals === 0 || $delivered === true,
         description: "The server removed {$removals} job(s) from the ready set that no "
            . 'worker received: ' . var_export($loss, true)
      );

      // @ A reply that stops mid-frame must take the connection with it, or the next
      //   command reads the fragment as its own answer
      $stall = $observed['stall'];
      yield assert(
         assertion: $stall['stalled']['throw'] !== null,
         description: 'A mid-frame stall must fail loudly: ' . var_export($stall['stalled'], true)
      );
      yield assert(
         assertion: $stall['next']['value'] === 7,
         description: 'The command after a stall must reconnect and read its own reply: '
            . var_export($stall['next'], true)
      );

      // @ More replies than commands means the stream is already offset
      $surplus = $observed['surplus'];
      yield assert(
         assertion: $surplus['surplus']['throw'] !== null,
         description: 'A surplus reply must be reported, not discarded: '
            . var_export($surplus['surplus'], true)
      );
      yield assert(
         assertion: $surplus['after']['value'] === 7,
         description: 'The connection must be usable again after a surplus reply: '
            . var_export($surplus['after'], true)
      );

      // @ Ordinary traffic is untouched
      $control = $observed['control'];
      yield assert(
         assertion: $control['enqueued']['value'] === true
            && $control['counted']['value'] === 1
            && $control['reserved']['job'] === true
            && $control['completed']['value'] === true,
         description: 'Ordinary operations must still round-trip: ' . var_export($control, true)
      );
   }
);
