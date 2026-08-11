<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;
use Bootgly\ADI\Databases\SQL\Operation;


return new Test(
   description: 'MySQL: prepare() derives the prepared flag from the statement cache',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'driver' => 'mysql',
         'statements' => 4,
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      // # Cache miss with a stale flag — a reused operation object arrives dirty
      $Stale = new Operation(null, 'UPDATE a SET x = ?', [1]);
      // ! Manually stained: retry() (POOL-1) also clears this, but prepare()
      //   must stay self-contained like PostgreSQL — never trust the inbound flag.
      $Stale->prepared = true;
      $MySQL->prepare($Stale);

      yield assert(
         assertion: $Stale->prepared === false,
         description: 'A cache-miss prepare() clears a stale prepared flag'
      );

      yield assert(
         assertion: ($Stale->write[4] ?? '') === Encoder::COM_STMT_PREPARE,
         description: 'A cache-miss prepare() encodes COM_STMT_PREPARE'
      );

      // @ Drive the stale command through the wire: prepare-OK must re-queue an EXECUTE.
      $MySQL->advance($Stale);
      fread($server, 8192);

      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      $prepared = "\x00" . pack('V', 77) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);

      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Stale);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, Encoder::COM_STMT_EXECUTE . pack('V', 77)),
         description: 'The prepare-OK re-queues the stale command as COM_STMT_EXECUTE'
      );

      yield assert(
         assertion: $Stale->finished === false && $Stale->prepared === true
            && array_keys($MySQL->statements) === ['UPDATE a SET x = ?'],
         description: 'The re-prepared statement is cached before the execute resolves'
      );

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Stale);

      yield assert(
         assertion: $Stale->finished && $Stale->error === null,
         description: 'The stale operation resolves through the binary protocol'
      );

      // # Cache hit still marks the operation prepared (dropped-line regression guard)
      $Hit = $MySQL->query('UPDATE a SET x = ?', [2]);

      yield assert(
         assertion: $Hit->prepared === true
            && ($Hit->write[4] ?? '') === Encoder::COM_STMT_EXECUTE
            && substr($Hit->write, 5, 4) === pack('V', 77),
         description: 'A cache hit still executes directly with the prepared flag set'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
