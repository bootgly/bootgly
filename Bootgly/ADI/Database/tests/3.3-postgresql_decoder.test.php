<?php

use function count;
use function pack;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL\Decoder;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL\Message;


return new Specification(
   description: 'Database: PostgreSQL decoder reads fragmented backend messages',
   test: function () {
      $Decoder = new Decoder;

      $readyLength = pack('N', 5);
      $ready = "Z{$readyLength}I";

      yield assert(
         assertion: $Decoder->decode(substr($ready, 0, 3)) === [],
         description: 'Decoder keeps incomplete message buffered'
      );

      $Messages = $Decoder->decode(substr($ready, 3));
      $Message = $Messages[0] ?? null;

      yield assert(
         assertion: $Message instanceof Message && $Message->type === 'Z' && $Message->fields['status'] === 'I',
         description: 'Decoder emits ReadyForQuery after remaining bytes arrive'
      );

      $errorPayload = "SERROR\0Mboom\0\0";
      $errorLength = pack('N', strlen($errorPayload) + 4);
      $error = "E{$errorLength}{$errorPayload}";
      $Messages = $Decoder->decode($error);
      $Message = $Messages[0] ?? null;

      yield assert(
         assertion: $Message instanceof Message && $Message->fields['severity'] === 'ERROR' && $Message->fields['message'] === 'boom',
         description: 'Decoder maps ErrorResponse fields'
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
      $batch = "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}";
      $Messages = $Decoder->decode($batch);

      yield assert(
         assertion: count($Messages) === 2,
         description: 'Decoder emits batched RowDescription and DataRow messages'
      );

      yield assert(
         assertion: ($Messages[0]->fields['columns'][0]['name'] ?? null) === 'value',
         description: 'Decoder reads RowDescription column metadata'
      );

      yield assert(
         assertion: ($Messages[1]->fields['values'][0] ?? null) === '42',
         description: 'Decoder reads DataRow values'
      );

      $keyPayload = pack('N', 123) . pack('N', 456);
      $keyLength = pack('N', strlen($keyPayload) + 4);
      $statusPayload = "server_version\0" . "16.4\0";
      $statusLength = pack('N', strlen($statusPayload) + 4);
      $noticePayload = "SNOTICE\0Mhello\0\0";
      $noticeLength = pack('N', strlen($noticePayload) + 4);
      $notificationPayload = pack('N', 789) . "events\0payload\0";
      $notificationLength = pack('N', strlen($notificationPayload) + 4);
      $Messages = $Decoder->decode("K{$keyLength}{$keyPayload}S{$statusLength}{$statusPayload}N{$noticeLength}{$noticePayload}A{$notificationLength}{$notificationPayload}");

      yield assert(
         assertion: ($Messages[0]->fields['process'] ?? null) === 123
            && ($Messages[0]->fields['secret'] ?? null) === 456,
         description: 'Decoder reads BackendKeyData'
      );

      yield assert(
         assertion: ($Messages[1]->fields['name'] ?? null) === 'server_version'
            && ($Messages[1]->fields['value'] ?? null) === '16.4',
         description: 'Decoder reads ParameterStatus'
      );

      yield assert(
         assertion: ($Messages[2]->fields['notice']['message'] ?? null) === 'hello'
            && ($Messages[3]->fields['channel'] ?? null) === 'events'
            && ($Messages[3]->fields['payload'] ?? null) === 'payload',
         description: 'Decoder reads NoticeResponse and NotificationResponse'
      );
   }
);
