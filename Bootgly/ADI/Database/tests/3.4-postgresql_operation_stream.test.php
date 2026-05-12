<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\OperationStates;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Specification(
   description: 'Database: PostgreSQL operation advances over non-blocking stream',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL;
      $Database->Connection->attach($client);

      $Operation = $Database->query('SELECT 1 AS value');
      $Operation = $Database->advance($Operation);

      $queryLength = pack('N', strlen('SELECT 1 AS value') + 5);
      $queryExpected = "Q{$queryLength}SELECT 1 AS value\0";

      yield assert(
         assertion: fread($server, 8192) === $queryExpected,
         description: 'Operation writes PostgreSQL Simple Query to attached stream'
      );

      yield assert(
         assertion: $Operation->state === OperationStates::Reading && $Operation->Readiness instanceof Readiness,
         description: 'Operation waits for backend read readiness after query write'
      );

      yield assert(
         assertion: $Operation->Readiness instanceof Readiness && $Operation->Readiness->deadline === $Operation->deadline,
         description: 'Operation readiness carries the database timeout deadline'
      );

      $columnCount = pack('n', 1);
      $columnTable = pack('N', 0);
      $columnAttribute = pack('n', 0);
      $columnType = pack('N', 23);
      $columnSize = pack('n', 4);
      $columnModifier = pack('N', 0xFFFFFFFF);
      $columnFormat = pack('n', 0);
      $columnPayload = "{$columnCount}value\0{$columnTable}{$columnAttribute}{$columnType}{$columnSize}{$columnModifier}{$columnFormat}";
      $columnLength = pack('N', strlen($columnPayload) + 4);
      $rowCount = pack('n', 1);
      $rowValueLength = pack('N', 2);
      $rowPayload = "{$rowCount}{$rowValueLength}42";
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $keyPayload = pack('N', 123) . pack('N', 456);
      $keyLength = pack('N', strlen($keyPayload) + 4);
      $statusPayload = "server_version\0" . "16.4\0";
      $statusLength = pack('N', strlen($statusPayload) + 4);
      $noticePayload = "SNOTICE\0Mhello\0\0";
      $noticeLength = pack('N', strlen($noticePayload) + 4);
      $notificationPayload = pack('N', 789) . "events\0payload\0";
      $notificationLength = pack('N', strlen($notificationPayload) + 4);
      $readyLength = pack('N', 5);
      $backend = "K{$keyLength}{$keyPayload}S{$statusLength}{$statusPayload}N{$noticeLength}{$noticePayload}A{$notificationLength}{$notificationPayload}T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I";

      fwrite($server, $backend);

      $Operation = $Database->advance($Operation);
      $Result = $Operation->Result;
      $Driver = $Operation->Protocol;

      yield assert(
         assertion: $Operation->finished && $Result !== null,
         description: 'Operation resolves after ReadyForQuery'
      );

      yield assert(
         assertion: $Result !== null && $Result->rows === [['value' => 42]],
         description: 'Operation result contains typed decoded named row'
      );

      yield assert(
         assertion: $Result !== null && $Result->status === 'SELECT 1' && $Result->affected === 1,
         description: 'Operation result contains command status and affected count'
      );

      yield assert(
         assertion: $Driver instanceof PostgreSQL
            && $Driver->backendProcess === 123
            && $Driver->backendSecret === 456
            && ($Driver->parameters['server_version'] ?? null) === '16.4',
         description: 'Driver stores backend key and parameter status metadata'
      );

      yield assert(
         assertion: $Driver instanceof PostgreSQL
            && ($Driver->notices[0]['message'] ?? null) === 'hello'
            && ($Driver->notifications[0]['channel'] ?? null) === 'events'
            && ($Driver->notifications[0]['payload'] ?? null) === 'payload',
         description: 'Driver stores notice and notification metadata'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
