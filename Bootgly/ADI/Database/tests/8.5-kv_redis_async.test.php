<?php

use function fclose;
use function fsockopen;
use function getenv;
use function is_resource;
use function uniqid;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\KV;


// ! Probe a reachable Redis server
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : '127.0.0.1';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 6379;
$Probe = @fsockopen($host, $port, $errno, $error, 0.2);
$reachable = is_resource($Probe);
if ($reachable === true) {
   fclose($Probe);
}


return new Specification(
   description: 'KV(Redis async): SET/GET/INCRBY over the non-blocking DBAL pool (requires a reachable Redis)',
   skip: $reachable === false,
   test: function () use ($host, $port) {
      $KV = new KV(['driver' => 'redis', 'host' => $host, 'port' => $port, 'timeout' => 2.0]);

      $key = 'bootgly:kv:async:' . uniqid();
      $counter = 'bootgly:kv:async:c:' . uniqid();

      $Set = $KV->await($KV->command('SET', [$key, 'value']));
      yield assert(
         assertion: $Set->response === 'OK',
         description: 'SET resolves with +OK'
      );

      $Get = $KV->await($KV->command('GET', [$key]));
      yield assert(
         assertion: $Get->response === 'value',
         description: 'GET resolves with the stored bulk string'
      );

      $Incr = $KV->await($KV->command('INCRBY', [$counter, 5]));
      yield assert(
         assertion: $Incr->response === 5,
         description: 'INCRBY resolves with an integer reply'
      );

      $KV->await($KV->command('DEL', [$key]));
      $KV->await($KV->command('DEL', [$counter]));

      $Missing = $KV->await($KV->command('GET', [$key]));
      yield assert(
         assertion: $Missing->response === null,
         description: 'GET of a deleted key resolves to null'
      );

      // # Per-connection pipelining (pool max=1 → every command shares one socket)
      $Pipelined = new KV([
         'driver' => 'redis', 'host' => $host, 'port' => $port,
         'timeout' => 2.0, 'pool' => ['max' => 1],
      ]);

      $prefix = 'bootgly:kv:pipe:' . uniqid();

      // @ Issue a batch, flush every write, then await — replies resolve FIFO
      $Sets = [];
      for ($i = 0; $i < 8; $i++) {
         $Sets[] = $Pipelined->command('SET', ["$prefix:$i", "v$i"]);
      }
      foreach ($Sets as $Op) {
         $Pipelined->advance($Op);
      }
      foreach ($Sets as $Op) {
         $Pipelined->await($Op);
      }

      $Gets = [];
      for ($i = 0; $i < 8; $i++) {
         $Gets[] = $Pipelined->command('GET', ["$prefix:$i"]);
      }
      foreach ($Gets as $Op) {
         $Pipelined->advance($Op);
      }
      foreach ($Gets as $Op) {
         $Pipelined->await($Op);
      }

      $correlated = true;
      foreach ($Gets as $i => $Op) {
         if ($Op->response !== "v$i") {
            $correlated = false;
         }
      }
      yield assert(
         assertion: $correlated === true,
         description: 'Pipelined replies resolve their own operations FIFO on one socket'
      );

      // @ An error reply mid-pipeline fails only its own operation
      $Mixed = [
         $Pipelined->command('SET', ["$prefix:e", '1']),
         $Pipelined->command('INCRBY', ["$prefix:e", 'NaN']),
         $Pipelined->command('GET', ["$prefix:e"]),
      ];
      foreach ($Mixed as $Op) {
         $Pipelined->advance($Op);
      }
      $Pipelined->await($Mixed[0]);
      $failed = false;
      try {
         $Pipelined->await($Mixed[1]);
      }
      catch (Throwable) {
         $failed = true;
      }
      $Pipelined->await($Mixed[2]);
      yield assert(
         assertion: $failed === true && $Mixed[2]->response === '1',
         description: 'Mid-pipeline error fails only its operation; later replies still correlate'
      );

      for ($i = 0; $i < 8; $i++) {
         $Pipelined->await($Pipelined->command('DEL', ["$prefix:$i"]));
      }
      $Pipelined->await($Pipelined->command('DEL', ["$prefix:e"]));
   }
);
