<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


/**
 * bytea decodes from either `bytea_output` format: `hex` (`\x` + two digits a
 * byte, the server default) and `escape` (`\\` for a backslash, `\ooo` octal
 * for any other byte outside printable ASCII, the rest as is) — so a server,
 * database or role configured with `bytea_output = escape`, which the driver
 * no longer pins, still returns the same bytes.
 */
return new Test(
   description: 'Database: PostgreSQL bytea text values decode from both hex and escape output',
   test: function () {
      // ! Every byte, plus the sequences the two formats could confuse.
      $all = '';
      for ($byte = 0; $byte < 256; $byte++) {
         $all .= chr($byte);
      }
      $escape = static function (string $binary): string {
         $text = '';

         foreach (str_split($binary) as $char) {
            $byte = ord($char);
            $text .= match (true) {
               $char === '\\' => '\\\\',
               $byte < 0x20 || $byte > 0x7E => sprintf('\\%03o', $byte),
               default => $char,
            };
         }

         return $text;
      };
      $values = [
         'every byte' => $all,
         'backslash then x41' => '\\x41',
         'octal-looking text' => "\\001\x012",
         'NUL then digits' => "\x00123",
         'printable only' => 'Hello, bytea!',
         'empty' => '',
      ];
      $cells = [];
      foreach ($values as $label => $binary) {
         $cells["hex: {$label}"] = [$binary === '' ? '\\x' : '\\x' . bin2hex($binary), $binary];
         $cells["escape: {$label}"] = [$escape($binary), $binary];
      }

      // @ Feed one DataRow of bytea (OID 17) columns.
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT bytea');
      $Database->advance($Operation);
      fread($server, 8192);

      $columns = pack('n', count($cells));
      $row = pack('n', count($cells));
      $index = 0;
      foreach ($cells as [$text]) {
         $columns .= "c{$index}\0" . pack('N', 0) . pack('n', 0) . pack('N', 17) . pack('n', 0xFFFF) . pack('N', 0xFFFFFFFF) . pack('n', 0);
         $row .= pack('N', strlen($text)) . $text;
         $index++;
      }
      $command = "SELECT 1\0";
      fwrite(
         $server,
         'T' . pack('N', strlen($columns) + 4) . $columns
         . 'D' . pack('N', strlen($row) + 4) . $row
         . 'C' . pack('N', strlen($command) + 4) . $command
         . 'Z' . pack('N', 5) . 'I'
      );
      $Database->advance($Operation);
      $Row = $Operation->Result?->row ?? [];

      // :
      $index = 0;
      foreach ($cells as $label => [$text, $binary]) {
         $decoded = $Row["c{$index}"] ?? null;
         $hex = is_string($decoded) ? bin2hex(substr($decoded, 0, 24)) : 'not a string';

         yield assert(
            assertion: $decoded === $binary,
            description: "bytea {$label} decodes to its bytes — {$hex}"
         );
         $index++;
      }

      fclose($server);
      $Database->Connection->disconnect();
   }
);
