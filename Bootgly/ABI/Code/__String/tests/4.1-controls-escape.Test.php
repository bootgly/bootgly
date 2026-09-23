<?php

use Bootgly\ABI\Code\__String\Controls;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Controls::escape() makes every control character visible text a terminal cannot act on',
   test: function () {
      // # Every C0 byte becomes the escape json_encode() writes for it
      $mismatches = [];
      for ($byte = 0; $byte <= 0x1F; $byte++) {
         $character = chr($byte);
         $expected = substr((string) json_encode($character), 1, -1);
         if (Controls::escape($character) !== $expected) {
            $mismatches[] = $byte;
         }
      }
      yield assert(
         assertion: $mismatches === [],
         description: 'C0 → the json_encode() escape (\b \t \n \f \r, else \u00xx); mismatched bytes: ' . json_encode($mismatches)
      );

      // # DEL and the C1 code points, which json_encode() leaves raw
      yield assert(
         assertion: Controls::escape("a\x7Fb") === 'a\u007fb'
            && Controls::escape("\xC2\x80|\xC2\x9B|\xC2\x9D|\xC2\x9F") === '\u0080|\u009b|\u009d|\u009f',
         description: 'DEL → \u007f and C1 (UTF-8 C2 80–C2 9F) → \u0080–\u009f'
      );

      // # Sequences lose their introducer, never the text after it
      $sequences = [
         "OSC-52 (BEL)" => ["x\e]52;c;UFdO\x07y", 'x\u001b]52;c;UFdO\u0007y'],
         "OSC-52 (ST)" => ["x\e]52;c;UFdO\e\\y", 'x\u001b]52;c;UFdO\u001b\y'],
         'DCS' => ["x\eP1\$qm\e\\y", 'x\u001bP1$qm\u001b\y'],
         'APC' => ["x\e_apc\e\\y", 'x\u001b_apc\u001b\y'],
         'CSI erase' => ["x\e[2Jy", 'x\u001b[2Jy'],
         'C1 CSI' => ["x\xC2\x9B2Jy", 'x\u009b2Jy'],
         'C1 OSC' => ["x\xC2\x9D0;t\xC2\x9Cy", 'x\u009d0;t\u009cy'],
         'unterminated OSC' => ["x\e]0;title", 'x\u001b]0;title'],
      ];
      $failed = [];
      foreach ($sequences as $name => [$input, $expected]) {
         if (Controls::escape($input) !== $expected) {
            $failed[] = $name;
         }
      }
      yield assert(
         assertion: $failed === [],
         description: 'OSC, DCS, APC, CSI (7-bit and C1) are escaped in place; failed: ' . implode(', ', $failed)
      );

      // # $keep leaves the named C0 characters alone — only those
      yield assert(
         assertion: Controls::escape("a\tb\nc\rd\e", "\t\n") === "a\tb\nc\\rd\\u001b",
         description: '$keep keeps TAB and LF raw while CR and ESC are still escaped'
      );

      // # $SGR keeps complete colour/style sequences — nothing else
      $styled = "\e[31mred\e[0m \e[1;38;5;150mbold\e[0m";
      yield assert(
         assertion: Controls::escape($styled, SGR: true) === $styled
            && Controls::escape($styled) === '\u001b[31mred\u001b[0m \u001b[1;38;5;150mbold\u001b[0m'
            && Controls::escape("\e[2J\e[>4;2m\e[38:2::255:0:0m\e[m", SGR: true) === '\u001b[2J\u001b[>4;2m\u001b[38:2::255:0:0m' . "\e[m",
         description: 'SGR: true keeps `ESC [ 0-9 ; m`; erase, private-marker and colon sequences are escaped'
      );

      // # UTF-8 text and invalid bytes
      $text = "Ação ś 日本 🚀"; // `ś` is C5 9B — a 0x9B continuation byte that is NOT C1
      yield assert(
         assertion: Controls::escape($text) === $text,
         description: 'UTF-8 text passes byte-identical, continuation bytes 0x80–0x9F included'
      );
      yield assert(
         assertion: Controls::escape("a\xFF\x9Bb\xC3") === "a\xFF\x9Bb\xC3",
         description: 'invalid UTF-8 never fails the call: stray bytes are left as they are'
      );

      // # Properties the sinks rely on
      $hostile = "a\e]52;c;x\x07\xC2\x9B\x7F\x00@.;\r\n";
      $once = Controls::escape($hostile);
      yield assert(
         assertion: Controls::escape($once) === $once
            && preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $once) === 0
            && substr_count($once, '@') === substr_count($hostile, '@'),
         description: 'idempotent, no raw control left, and no `@` added (escapes never form markup)'
      );

      $data = ['k' => "v\xC2\x9B\x7F\e\n", "\xC2\x9Dkey" => 'ś'];
      $JSON = Controls::escape((string) json_encode($data, JSON_UNESCAPED_UNICODE));
      yield assert(
         assertion: json_decode($JSON, true) === $data && preg_match('/\xC2[\x80-\x9F]|\x7F/', $JSON) === 0,
         description: 'applied after json_encode(), the JSON stays valid and lossless'
      );
   }
);
