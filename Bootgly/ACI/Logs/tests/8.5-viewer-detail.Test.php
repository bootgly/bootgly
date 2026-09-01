<?php

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Formatters\JSON as JSONFormatter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\Terminal\Input;
use Bootgly\CLI\Terminal\Output;
use Bootgly\CLI\UI\Components\Logs as Viewer;


/**
 * BG-25 — the detail view shows the WHOLE record: every row is folded to the
 * terminal width (re-folded on every render), `context`/`extra` print one key
 * per row hanging under their label, the header counts the folded rows and
 * vertical scroll (PgUp/PgDn, Home/End) reaches every one of them. Only the
 * list truncates (one row per record, by design).
 *
 * Geometry: 80×12 → pane of 10 rows (bar + 10 + footer = 12 frame rows).
 * Fixture A's context pretty-prints to 11 rows (`{`, 9 keys, `}`), so the
 * detail is 15 rows: header, blank, message, blank, 11 context rows. Context
 * key rows are indented 9 (the hang) + 4 (JSON) = 13 columns.
 */
return new Test(
   description: 'Live log viewer detail view folds every row to the width, pretty-prints context/extra and scrolls to every row',
   test: function () {
      $geometry = [Terminal::$columns, Terminal::$lines, Terminal::$width, Terminal::$height];
      $savedQualifier = Record::$qualifier;
      $ANSI = __String::ANSI_ESCAPE_SEQUENCE_REGEX;

      $resize = static function (int $width, int $height): void {
         Terminal::$columns = $width;
         Terminal::$lines = $height;
         Terminal::$width = $width;
         Terminal::$height = $height;
      };
      // ! Feed one record, pause, expand
      $open = static function (Record $Record): Viewer {
         $Viewer = new Viewer(new Input, new Output('php://memory'));
         $Viewer->feed((new JSONFormatter)->format($Record));
         $Viewer->control(' ');
         $Viewer->control("\n");
         return $Viewer;
      };
      // ! One frame: [raw bytes, stripped rows]
      $frame = static function (Viewer $Viewer) use ($ANSI): array {
         $Viewer->Output = new Output('php://memory');
         $Viewer->render();
         rewind($Viewer->Output->stream);
         $raw = (string) stream_get_contents($Viewer->Output->stream);
         return [$raw, explode("\n", (string) preg_replace($ANSI, '', $raw))];
      };
      $widest = static function (array $rows): int {
         $max = 0;
         foreach ($rows as $row) {
            $max = max($max, mb_strwidth($row));
         }
         return $max;
      };
      $squash = static fn (string $text): string => str_replace(' ', '', $text);

      $context = [
         'method' => 'GET', 'URI' => '/api/health', 'protocol' => 'HTTP/2', 'code' => 200,
         'ms' => 0, 'bytes' => 73, 'peer' => '203.0.113.7',
         'id' => '5f1c2a9e8b7d4c3a9f0e1d2c3b4a5968', 'deferred' => false,
      ];
      $compact = $squash((string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

      try {
         Record::$qualifier = '';
         $resize(80, 12);

         // # L13 (guard): the LIST still truncates — one row per record, context never inlined
         $List = new Viewer(new Input, new Output('php://memory'));
         $A = new Record(Levels::Info, 'Demo.App', 'GET /api/health → 200 in 0ms', $context);
         $List->feed((new JSONFormatter)->format($A));
         [, $rows] = $frame($List);
         $listed = array_values(array_filter($rows, static fn (string $row): bool => str_contains($row, 'GET /api/health')));
         yield assert(
            assertion: count($listed) === 1 && str_contains(implode("\n", $rows), '"method"') === false
               && str_contains($listed[0], date('Y-m-d H:i:s', (int) $A->timestamp) . ' | INFO      | Demo.App: GET /api/health'),
            description: 'list mode keeps one row per record — date time | level | channel: message (the context stays out of the list)'
         );

         // # L1/L2/L3: the whole context is present, hung under its label, one key per row
         $Viewer = $open($A);
         [$raw, $rows] = $frame($Viewer);
         $first = $rows;
         yield assert(
            assertion: count($rows) === 12 && $widest($rows) <= 80,
            description: 'the detail frame is exactly bar + pane + footer and no row exceeds the width — got ' . count($rows) . ' rows, widest ' . $widest($rows)
         );
         yield assert(
            assertion: str_contains($rows[0], '1–10 of 15 lines'),
            description: 'the bar counts the folded rows and shows the visible range — got: ' . trim($rows[0])
         );
         yield assert(
            assertion: in_array('context: {', $rows, true)
               && preg_grep('/^ {13}"method": "GET",$/', $rows) !== []
               && str_contains(implode("\n", $rows), '"deferred"') === false,
            description: 'context opens hung under its label, one key per row, and the tail is below the fold'
         );

         // # L4: vertical scroll reaches the tail — PgDn, PgUp, End, Home
         $Viewer->control("\e[6~");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: str_contains($rows[0], '6–15 of 15 lines')
               && preg_grep('/^ {13}"deferred": false$/', $rows) !== []
               && preg_grep('/^ {13}"peer": "203\.0\.113\.7",$/', $rows) !== []
               && in_array('         }', $rows, true),
            description: 'PgDn shows the second page: peer, id, deferred and the closing brace'
         );
         yield assert(
            // ? page 1 = rows 0-9, page 2 = rows 5-14: join page 1 with the unseen tail of page 2
            assertion: str_contains($squash(implode('', array_slice($first, 1, 10)) . implode('', array_slice($rows, 6, 5))), $compact),
            description: 'every character of the context is reachable across the pages (nothing is cut)'
         );
         $Viewer->control("\e[5~");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: str_contains($rows[0], '1–10 of 15 lines') && str_contains($rows[3], 'GET /api/health'),
            description: 'PgUp returns to the first page'
         );
         $Viewer->control("\e[4~");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: preg_grep('/^ {13}"deferred": false$/', $rows) !== [] && str_contains($rows[11], 'Home/End'),
            description: 'End jumps to the tail and the footer names the keys'
         );
         $Viewer->control("\e[1~");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: str_contains($rows[3], 'GET /api/health'),
            description: 'Home jumps back to the top'
         );
         $Viewer->control("\e[F");
         $Viewer->control("\e[6~");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: preg_grep('/^ {13}"deferred": false$/', $rows) !== [] && str_contains($rows[0], '6–15 of 15 lines'),
            description: 'End then PgDn without a render in between stays clamped to the last page'
         );

         // # L8: SGR hygiene — the label closes its colour before the JSON; continuation rows carry no escapes
         $lines = explode("\n", $raw);
         $label = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'context: ')));
         $index = array_search($label[0] ?? '', $lines, true);
         $next = $lines[(int) $index + 1] ?? '';
         yield assert(
            assertion: $label !== [] && preg_match('/\e\[90mcontext: \e\[0m\{/', $label[0]) === 1
               && substr_count($next, "\e[") === 1 && str_ends_with($next, "\e[K"),
            description: 'the dim label is reset before the brace and a key row carries no escape but its clear-to-EOL'
         );

         // # L5: a long single-line message folds at word boundaries; a space-free one is hard-split
         $B = new Record(Levels::Error, 'Demo.App', str_repeat('word ', 40) . 'TAIL_OF_MESSAGE', ['k' => 'v']);
         [, $rows] = $frame($open($B));
         $message = [];
         for ($index = 3; $index < 11 && $rows[$index] !== ''; $index++) {
            $message[] = $rows[$index];
         }
         $whole = array_filter($message, static fn (string $row): bool => preg_match('/^(?:word|TAIL_OF_MESSAGE)(?: (?:word|TAIL_OF_MESSAGE))*$/', trim($row)) === 1);
         yield assert(
            assertion: count($message) >= 3 && count($whole) === count($message)
               && str_contains(implode("\n", $message), 'TAIL_OF_MESSAGE') && $widest($rows) <= 80,
            description: 'a 215-char message folds into whole-word rows and its tail is visible — got ' . count($message) . ' rows'
         );
         $C = new Record(Levels::Error, 'Demo.App', str_repeat('x', 200));
         [, $rows] = $frame($open($C));
         yield assert(
            assertion: substr_count(implode('', $rows), 'x') === 200 && $widest($rows) <= 80,
            description: 'a 200-char token is hard-split across rows without losing a character'
         );

         // # L6: the fold follows the CURRENT width on every render
         $Viewer = $open($A);
         $frame($Viewer);
         $resize(40, 40);
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: $widest($rows) <= 40 && str_contains($squash(implode('', $rows)), $context['id']),
            description: 'after a resize to 40 columns every row fits and the 32-char id is whole across the fold'
         );

         // # L7: too narrow to hang — the label takes its own row, the JSON keeps the full width
         $resize(8, 200);
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: $widest($rows) <= 8 && in_array('context:', $rows, true)
               && str_contains($squash(implode('', $rows)), $compact),
            description: 'at 8 columns the label stands alone and the JSON is still complete'
         );

         // # L9: blank message rows survive the fold
         $resize(80, 12);
         $D = new Record(Levels::Warning, 'Demo.App', "boom\n\nafter");
         [, $rows] = $frame($open($D));
         yield assert(
            assertion: $rows[2] === '' && $rows[3] === 'boom' && $rows[4] === '' && $rows[5] === 'after',
            description: 'an empty line inside the message stays an empty row'
         );

         // # L10: wide characters count two columns — 20 per row at width 40
         $resize(40, 40);
         $E = new Record(Levels::Info, 'Demo.App', str_repeat('日', 100));
         [, $rows] = $frame($open($E));
         $cjk = array_filter($rows, static fn (string $row): bool => str_contains($row, '日'));
         yield assert(
            assertion: mb_substr_count(implode('', $rows), '日') === 100 && count($cjk) === 5 && $widest($rows) <= 40,
            description: 'a 100-ideograph message folds into 5 physical rows of 20 (wide chars = 2 columns)'
         );

         // # L11: nested context pretty-prints one item per row
         $resize(80, 40);
         $F = new Record(Levels::Info, 'Demo.App', 'nested', ['user' => ['id' => 1, 'roles' => ['admin', 'ops']]]);
         [, $rows] = $frame($open($F));
         $trimmed = array_map('trim', $rows);
         yield assert(
            assertion: in_array('"roles": [', $trimmed, true) && in_array('"admin",', $trimmed, true) && in_array('"ops"', $trimmed, true),
            description: 'nested arrays open on their own row with one item per row'
         );

         // # L12: extra hangs under its own label; no context section when it is empty
         $G = new Record(Levels::Info, 'Demo.App', 'extra only');
         $G->extra = ['trace' => 'abc'];
         [, $rows] = $frame($open($G));
         yield assert(
            assertion: preg_grep('/^context:/', $rows) === []
               && in_array('extra:   {', $rows, true)
               && preg_grep('/^ {13}"trace": "abc"$/', $rows) !== [],
            description: 'extra prints hung under its 9-column label and an empty context prints nothing'
         );

         // # L14: the header row folds too (a long channel wraps instead of being cut)
         $resize(80, 12);
         $H = new Record(Levels::Info, str_repeat('Demo.Channel.', 8), 'GET /api/health → 200 in 0ms', $context);
         [$raw, $rows] = $frame($open($H));
         $lines = explode("\n", $raw);
         $header = (int) array_search('', array_slice($rows, 1), true); // header rows before the blank separator
         yield assert(
            assertion: $header >= 2
               && substr_count(implode('', array_slice($rows, 1, $header)), 'Demo.Channel.') === 8
               && str_contains($rows[0], 'of ' . (14 + $header) . ' lines')
               && str_starts_with($lines[$header], "\e[94m"),
            description: 'a 104-column channel folds the header (nothing cut), the count follows and the colour reopens on the continuation — got ' . $header . ' header rows'
         );

         // # L15: the header shows the record instance when the writer claimed one
         Record::$qualifier = '8443';
         $I = new Record(Levels::Info, 'Demo.App', 'stamped', ['k' => 'v']);
         Record::$qualifier = '';
         [, $rows] = $frame($open($I));
         [, $bare] = $frame($open($A));
         yield assert(
            assertion: str_contains($rows[1], '8443') && str_contains($bare[1], '8443') === false
               && preg_match('/ {3,}/', trim($bare[1])) === 0,
            description: 'the header carries the instance qualifier — and no empty token when there is none'
         );

         // # L16: tabs and control bytes never reach the frame (a tab would carry the terminal past the width)
         $resize(80, 12);
         $T = new Record(Levels::Info, "Demo\tApp", "\t\tX" . str_repeat('y', 60) . "\x07boom \ec after");
         [$raw, $rows] = $frame($open($T));
         yield assert(
            assertion: str_contains($raw, "\t") === false && str_contains($raw, "\x07") === false
               && preg_match('/\e[^\[]/', $raw) === 0
               && str_starts_with($rows[3], str_repeat(' ', 16) . 'X') && $widest($rows) <= 80
               && str_contains($rows[1], 'Demo    App'),
            description: 'tabs expand to the next 8-column stop and other control bytes are dropped before the fold'
         );

         // # L17: paging across three pages (PgDn steps by the pane height)
         $wide = [];
         for ($index = 1; $index <= 25; $index++) {
            $wide["key$index"] = $index;
         }
         $Viewer = $open(new Record(Levels::Info, 'Demo.App', 'paged', $wide));
         $Viewer->control("\e[6~");
         $Viewer->control("\e[6~");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: str_contains($rows[0], '21–30 of 31 lines') && preg_grep('/^ {13}"key17": 17,$/', $rows) !== [],
            description: 'two PgDn land on the third page of a 31-row detail'
         );

         // # L18: a width that cannot host the hang — label alone, JSON rows keep their own indent
         $resize(14, 60);
         [, $rows] = $frame($open($A));
         yield assert(
            assertion: $widest($rows) <= 14 && in_array('context:', $rows, true) && preg_grep('/^    "ms": 0,$/', $rows) !== [],
            description: 'at 14 columns the label stands alone and the JSON keeps the full width'
         );

         // # L19: a detail that exactly fills the pane shows a plain count
         $resize(80, 12);
         $Fit = new Record(Levels::Info, 'Demo.App', 'fits', ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
         [, $rows] = $frame($open($Fit));
         yield assert(
            assertion: str_contains($rows[0], '10 lines') && str_contains($rows[0], ' of ') === false,
            description: 'a detail that fits the pane counts its rows without a range'
         );

         // # Esc leaves the detail and the list renders again
         $Viewer = $open($A);
         $Viewer->control("\e");
         [, $rows] = $frame($Viewer);
         yield assert(
            assertion: $Viewer->Detail === null && count(array_filter($rows, static fn (string $row): bool => str_contains($row, 'GET /api/health'))) === 1,
            description: 'Esc closes the detail view and the list shows one row per record again'
         );
      }
      finally {
         [Terminal::$columns, Terminal::$lines, Terminal::$width, Terminal::$height] = $geometry;
         Record::$qualifier = $savedQualifier;
      }
   }
);
