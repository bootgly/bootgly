<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON;
use Bootgly\ACI\Logs\Formatters\Line;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * M9 — no part of a record can drive the operator's terminal. A message, a channel or a context
 * value may carry OSC-52 (clipboard write), DCS, APC, a CSI erase or their C1 forms; the Line
 * formatter (stdout, syslog, `bootgly logs`) escapes them visibly and keeps only tabs, line
 * feeds and complete SGR in the message; the JSON formatter escapes what json_encode() leaves
 * raw (DEL, C1) without losing a byte.
 */
return new Test(
   description: 'Log formatters escape every terminal control sequence a record carries',
   test: function () {
      // ! A record carrying every sequence family, in every field
      $message = "A\e]52;c;UFdO\x07B\eP1\$qm\e\\C\e_apc\e\\D\e[2JE\xC2\x9B2JF\xC2\x9D0;t\xC2\x9CG\x7FH"
         . "\e[31mRED\e[0m\tI\nJ\rK";
      $channel = "ch\e]0;x\x07\xC2\x9Bn\e[31mR\nL";
      $context = ['k' => "v\xC2\x9B\x7F\e\n"];
      $Record = new Record(Levels::Error, $channel, $message, $context);

      // ! Anything a terminal acts on, other than a complete SGR, a TAB or a LF
      $live = static fn (string $bytes): bool => preg_match('/\e(?!\[[0-9;]*m)|\xC2[\x80-\x9F]|[\x00-\x08\x0B-\x1A\x1C-\x1F\x7F]/', $bytes) === 1;

      // # Line — message
      $Line = new Line(Display::MESSAGE);
      $rendered = $Line->format($Record);
      yield assert(
         assertion: $live($rendered) === false
            && str_contains($rendered, 'A\u001b]52;c;UFdO\u0007B\u001bP1$qm\u001b\C\u001b_apc\u001b\D\u001b[2JE\u009b2JF\u009d0;t\u009cG\u007fH'),
         description: 'Line message: OSC, DCS, APC, CSI, C1 and DEL are escaped in place, nothing is dropped'
      );
      yield assert(
         assertion: str_contains($rendered, "\e[31mRED\e[0m\tI\nJ" . '\rK'),
         description: 'Line message: complete SGR, tabs and line feeds are kept; CR is escaped'
      );

      // # Line — channel
      $origin = (new Line(Display::CHANNEL | Display::SEVERITY))->format($Record);
      yield assert(
         assertion: $live($origin) === false && str_starts_with($origin, 'ch\u001b]0;x\u0007\u009bn\u001b[31mR\nL.'),
         description: 'Line channel: escaped, with no line feed or SGR allowance'
      );

      // # Line — inline context
      $inline = (new Line(Display::CONTEXT))->format($Record);
      $JSON = (string) preg_replace('/^ \e\[[0-9;]*m|\e\[0m\n$/', '', $inline);
      yield assert(
         assertion: $live(rtrim($inline, "\n")) === false && json_decode($JSON, true) === $context,
         description: 'Line context: DEL and C1 escaped, and the inline JSON still decodes to the context'
      );

      // # JSON — the file sink and the live tap
      $document = (new JSON)->format($Record);
      $decoded = json_decode($document, true);
      yield assert(
         assertion: $live(rtrim($document, "\n")) === false
            && is_array($decoded)
            && $decoded['channel'] === $channel
            && $decoded['context'] === $context
            && str_contains((string) $decoded['message'], "E\xC2\x9B2JF\xC2\x9D0;t\xC2\x9CG\x7FH"),
         description: 'JSON: no raw DEL/C1 in the document, and decoding restores every character'
      );
   }
);
