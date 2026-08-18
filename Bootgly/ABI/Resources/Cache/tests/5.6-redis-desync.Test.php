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
   description: 'Cache(Redis): a failed command is raised, and never answers the next one',
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
         assertion: is_array($observed) && isset($observed['leak'], $observed['control']),
         description: 'The stub-server probe produced no readable result: '
            . var_export($output, true)
      );

      if (is_array($observed) === false || isset($observed['leak']) === false) {
         return;
      }

      // @ An honest server answering 100 ms late, with 'timeout' => 0.5 — a value the
      //   config API accepts. The seconds-only stream_set_timeout() used to collapse it
      //   to zero, and the abandoned reply became the next command's answer (CACHE-8)
      yield assert(
         assertion: $observed['leak']['alice']['value'] === 'ALICE-TOKEN',
         description: 'A sub-second timeout must not abort a reply that arrives in time: '
            . var_export($observed['leak']['alice'], true)
      );
      yield assert(
         assertion: $observed['leak']['bob']['value'] !== 'ALICE-TOKEN',
         description: "fetch() returned another key's cached value: "
            . var_export($observed['leak']['bob'], true)
      );
      yield assert(
         assertion: $observed['leak']['bob']['value'] === 'BOB-TOKEN',
         description: 'The second fetch() must return its own value: '
            . var_export($observed['leak']['bob'], true)
      );

      // @ A reply that stops mid-frame poisons the Decoder unless the connection goes
      yield assert(
         assertion: $observed['stall']['stalled']['throw'] !== null,
         description: 'A mid-frame stall must fail loudly: '
            . var_export($observed['stall']['stalled'], true)
      );
      yield assert(
         assertion: $observed['stall']['next']['value'] === 'BOB-TOKEN',
         description: 'The command after a stall must reconnect and read its own reply: '
            . var_export($observed['stall']['next'], true)
      );

      // @ More replies than commands means the stream is already offset
      yield assert(
         assertion: $observed['surplus']['surplus']['throw'] !== null,
         description: 'A surplus reply must be reported, not discarded: '
            . var_export($observed['surplus']['surplus'], true)
      );
      yield assert(
         assertion: $observed['surplus']['after']['value'] === 'AFTER',
         description: 'The connection must be usable again after a surplus reply: '
            . var_export($observed['surplus']['after'], true)
      );

      // @ Ordinary traffic is untouched
      $control = $observed['control'];
      yield assert(
         assertion: $control['store']['value'] === true
            && $control['fetch']['value'] === 'v'
            && $control['increment']['value'] === 5
            && $control['delete']['value'] === true,
         description: 'Ordinary operations must still round-trip: ' . var_export($control, true)
      );

      // @ The Decoder returns `-`/`!` replies as RuntimeException VALUES, never thrown, so
      //   a rejected handshake used to leave a reusable, unauthenticated socket and every
      //   later command degraded to a silent miss (CACHE-10)
      yield assert(
         assertion: $observed['refused']['store']['throw'] !== null,
         description: 'A rejected AUTH must fail the command, not answer it silently: '
            . var_export($observed['refused']['store'], true)
      );
      yield assert(
         assertion: $observed['refused']['fetch']['throw'] !== null,
         description: 'A command after a rejected AUTH must not report a cache miss: '
            . var_export($observed['refused']['fetch'], true)
      );

      // @ A rejected SELECT is worse than a miss — it reports SUCCESS on database 0
      yield assert(
         assertion: $observed['database']['store']['throw'] !== null,
         description: 'A rejected SELECT must fail, not silently use another database: '
            . var_export($observed['database']['store'], true)
      );

      // @ An error frame is complete, so the connection must be reported AND kept
      yield assert(
         assertion: $observed['errored']['errored']['throw'] !== null,
         description: 'An error reply must be raised, not returned as a miss: '
            . var_export($observed['errored']['errored'], true)
      );
      yield assert(
         assertion: $observed['errored']['after']['value'] === 'AFTER',
         description: 'An error reply must NOT drop the connection — the frame was aligned: '
            . var_export($observed['errored']['after'], true)
      );

      // @ A healthy handshake still passes through
      yield assert(
         assertion: $observed['handshake']['fetch']['value'] === 'v',
         description: 'An accepted AUTH/SELECT must connect normally: '
            . var_export($observed['handshake'], true)
      );
   }
);
