<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_keys;
use function chr;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;
use Bootgly\ADI\Databases\SQL\Operation;


return new Specification(
   description: 'MySQL: statement cache reuses ids and evicts by LRU with COM_STMT_CLOSE',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'driver' => 'mysql',
         'statements' => 2,
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      // ! Full prepare dance for an UPDATE (1 parameter, 0 columns) + OK
      $dance = static function (Operation $Operation, int $statement) use ($MySQL, $server, $eof, $ok, $definition): void {
         $MySQL->advance($Operation);
         fread($server, 8192);

         $prepared = "\x00" . pack('V', $statement) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
         fwrite($server, $MySQL->Encoder->frame($prepared, 1));
         fwrite($server, $MySQL->Encoder->frame($definition, 2));
         fwrite($server, $MySQL->Encoder->frame($eof, 3));
         $MySQL->advance($Operation);
         fread($server, 8192);

         fwrite($server, $MySQL->Encoder->frame($ok, 1));
         $MySQL->advance($Operation);
      };

      $first = 'UPDATE a SET x = ?';
      $second = 'UPDATE b SET x = ?';
      $third = 'UPDATE c SET x = ?';

      // # Miss → prepare → cached
      $One = $MySQL->query($first, [1]);
      $dance($One, 11);

      yield assert(
         assertion: $One->finished && $One->error === null
            && array_keys($MySQL->statements) === [$first],
         description: 'The first parameterized command prepares and caches its statement'
      );

      // # Hit → COM_STMT_EXECUTE directly, no COM_STMT_PREPARE
      $Reused = $MySQL->query($first, [2]);

      yield assert(
         assertion: $Reused->prepared && $Reused->write[4] === "\x17"
            && substr($Reused->write, 5, 4) === pack('V', 11),
         description: 'A cache hit executes directly with the cached statement id'
      );

      $MySQL->advance($Reused);
      fread($server, 8192);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Reused);

      yield assert(
         assertion: $Reused->finished && $Reused->error === null,
         description: 'The reused statement resolves through the binary protocol'
      );

      // # Fill the cache, touch the first entry, then overflow
      $Two = $MySQL->query($second, [3]);
      $dance($Two, 22);

      $Touched = $MySQL->query($first, [4]);
      $MySQL->advance($Touched);
      fread($server, 8192);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Touched);

      yield assert(
         assertion: array_keys($MySQL->statements) === [$second, $first],
         description: 'Cache hits touch the LRU order'
      );

      // @ Overflow — the LRU entry (second) is evicted with COM_STMT_CLOSE
      $Three = $MySQL->query($third, [5]);
      $MySQL->advance($Three);
      fread($server, 8192);

      $prepared = "\x00" . pack('V', 33) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Three);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: substr($wire, 4, 5) === "\x19" . pack('V', 22)
            && str_contains(substr($wire, 9), "\x17" . pack('V', 33)),
         description: 'The eviction rides a COM_STMT_CLOSE ahead of the new EXECUTE'
      );

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Three);

      yield assert(
         assertion: $Three->finished
            && array_keys($MySQL->statements) === [$first, $third],
         description: 'The cache keeps the touched entry and the new statement'
      );

      fclose($server);
      $Connection->disconnect();

      // # statements => 0 — prepare still runs but nothing stays cached
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'driver' => 'mysql',
         'statements' => 0,
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $Uncached = new MySQL($Config, $Connection);

      $Alone = $Uncached->query('UPDATE d SET x = ?', [6]);
      $Uncached->advance($Alone);
      fread($server, 8192);

      $prepared = "\x00" . pack('V', 44) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      fwrite($server, $Uncached->Encoder->frame($prepared, 1));
      fwrite($server, $Uncached->Encoder->frame($definition, 2));
      fwrite($server, $Uncached->Encoder->frame($eof, 3));
      $Uncached->advance($Alone);
      fread($server, 8192);
      fwrite($server, $Uncached->Encoder->frame($ok, 1));
      $Uncached->advance($Alone);

      yield assert(
         assertion: $Alone->finished && $Alone->error === null
            && $Uncached->statements === [],
         description: 'statements => 0 prepares per command and caches nothing'
      );

      // @ The after-use COM_STMT_CLOSE rides ahead of the next command
      $Next = $Uncached->query('UPDATE e SET x = 1');
      $Uncached->advance($Next);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: substr($wire, 4, 5) === "\x19" . pack('V', 44)
            && str_contains($wire, "\x03UPDATE e SET x = 1"),
         description: 'Disabled caching closes the server statement right after its command'
      );

      fwrite($server, $Uncached->Encoder->frame($ok, 1));
      $Uncached->advance($Next);

      fclose($server);
      $Connection->disconnect();
   }
);
