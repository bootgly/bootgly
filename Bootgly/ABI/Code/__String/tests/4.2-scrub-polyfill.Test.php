<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__String;


use const BOOTGLY_ROOT_DIR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const PHP_BINARY;
use function array_diff;
use function array_keys;
use function assert;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chr;
use function count;
use function fclose;
use function fopen;
use function function_exists;
use function fwrite;
use function hex2bin;
use function hrtime;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_scrub;
use function mb_substitute_character;
use function ord;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function realpath;
use function rewind;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function strlen;
use function substr;
use function unlink;
use function usleep;
use ReflectionFunction;
use RuntimeException;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * Without ext-mbstring, `mb_scrub()` is the polyfill `Bootgly/ABI/autoboot.php`
 * defines — and it writes what ext-mbstring writes.
 *
 * A child `php -d disable_functions=mb_scrub` boots Bootgly, so every call it
 * makes reaches the polyfill; this process holds the expectations (and
 * ext-mbstring, when it has it):
 * - the child's `mb_scrub` is not internal and is defined in `ABI/autoboot.php`;
 * - a pinned table — truncated, overlong, surrogate, out-of-range, stray and
 *   mixed inputs, one `?` per maximal ill-formed subpart — is what ext-mbstring
 *   writes here, and what the polyfill writes;
 * - on every string of 1 to 4 bytes over the 25 bytes where UTF-8 changes
 *   meaning (406,900 strings), the polyfill writes what ext-mbstring writes;
 * - every output is well-formed UTF-8, and well-formed UTF-8 — the first and
 *   last code point of every lead-byte class — comes back byte-identical, alone
 *   and around a stray byte;
 * - another encoding gets its input back as is, while no encoding, `null` and
 *   the `utf8`/`utf-8`/`UTF8` aliases scrub as `UTF-8`.
 */
return new Test(
   description: 'the mb_scrub() polyfill writes what ext-mbstring writes: one `?` per maximal ill-formed subpart, well-formed UTF-8 untouched, another encoding returned as is',
   test: function () {
      // ? Nothing to start the child with
      if (function_exists('proc_open') === false || PHP_BINARY === '') {
         yield (new Assertion(description: 'mb_scrub() polyfill legs: no proc_open() or PHP binary here'))->skip();

         return;
      }

      // ! What ext-mbstring's mb_scrub($input, 'UTF-8') writes — label => [input, output]
      $table = [
         'lone 3-byte lead' => ["\xE2", '?'],
         'truncated 4-byte, 3 of 4' => ["\xF0\x9F\x98", '?'],
         'truncated 4-byte, 2 of 4' => ["\xF0\x9F", '?'],
         'truncated 3-byte between ASCII' => ["a\xE2\x82b", 'a?b'],
         'lone 2-byte lead' => ["\xC2", '?'],
         'surrogate prefix' => ["\xED\xA0", '??'],
         'prefix above U+10FFFF' => ["\xF4\x90", '??'],
         'ASCII' => ['ok', 'ok'],
         '2-byte character' => ["caf\xC3\xA9", "caf\xC3\xA9"],
         'stray 0x9B' => ["x\x9B2J", 'x?2J'],
         'FF, then a lone lead' => ["\xFF\xD8", '??'],
         'C1 control (well-formed)' => ["c1\xC2\x9Bz", "c1\xC2\x9Bz"],
         'truncated at the end' => ["trunc\xE2\x82", 'trunc?'],
         'overlong 2-byte' => ["over\xC0\xAF", 'over??'],
         'surrogate U+D800' => ["sur\xED\xA0\x80", 'sur???'],
         'mixed' => ["mix\xC3\xA9\x9B\xF0\x9F\x98\x80", "mix\xC3\xA9?\xF0\x9F\x98\x80"],
         'above U+10FFFF' => ["\xF4\x90\x80\x80", '????'],
         '3-byte character' => ["e\xE2\x82\xACuro", "e\xE2\x82\xACuro"],
         'ASCII with NUL, TAB, DEL and ?' => ["plain ASCII ?!\t\x00\x7F", "plain ASCII ?!\t\x00\x7F"],
         '4-byte character' => ["\xF0\x9F\x9A\x80", "\xF0\x9F\x9A\x80"],
         'truncated 4-byte before a whole one' => ["\xF0\x9F\x9A\xF0\x9F\x9A\x80", "?\xF0\x9F\x9A\x80"],
         'truncated E0, 2 of 3' => ["\xE0\xA0", '?'],
         'overlong 3-byte' => ["\xE0\x80\x80", '???'],
         'truncated ED, 2 of 3' => ["\xED\x9F", '?'],
         'truncated EF, 2 of 3' => ["\xEF\xBF", '?'],
         'truncated F1, 3 of 4' => ["\xF1\x80\x80", '?'],
         'truncated F3, 3 of 4' => ["\xF3\xBF\xBF", '?'],
         'truncated F4, 3 of 4' => ["\xF4\x8F\xBF", '?'],
         'overlong 4-byte' => ["\xF0\x80\x80\x80", '????'],
         'lead above F4' => ["\xF5\x80\x80\x80", '????'],
         'lone continuations' => ["\x80\xBF", '??'],
         'truncated lead before a whole 3-byte' => ["\xE2\xE2\x82\xAC", "?\xE2\x82\xAC"],
         'lone lead before a whole 2-byte' => ["\xC2\xC2\xA9", "?\xC2\xA9"],
      ];
      $rows = count($table);
      // ! The first and last code point of every UTF-8 lead-byte class, plus `?` and a C1 control
      $boundaries = "?\x00\x7F\u{80}\u{7FF}\u{800}\u{FFF}\u{1000}\u{CFFF}\u{D000}\u{D7FF}\u{E000}\u{FFFF}\u{10000}\u{3FFFF}\u{40000}\u{FFFFF}\u{100000}\u{10FFFF}\u{9B}";
      // ! Well-formed ISO-8859-1, ill-formed UTF-8 — and what ext-mbstring writes for it as UTF-8
      $latin = "caf\xE9 na\xEFve \x80\xFF";
      $scrubbed = 'caf? na?ve ??';

      // ! Every call the child makes — label => [input, encoding (false: none given), output]
      $calls = [];
      foreach ($table as $label => [$input, $output]) {
         $calls[$label] = [$input, 'UTF-8', $output];
      }
      $calls['boundaries'] = [$boundaries, 'UTF-8', $boundaries];
      $calls['boundaries around a stray byte'] = ["{$boundaries}\xFF{$boundaries}", 'UTF-8', "{$boundaries}?{$boundaries}"];
      $calls['as ISO-8859-1'] = [$latin, 'ISO-8859-1', $latin];
      $calls['as UTF-8'] = [$latin, 'UTF-8', $scrubbed];
      $calls['as utf8'] = [$latin, 'utf8', $scrubbed];
      $calls['as utf-8'] = [$latin, 'utf-8', $scrubbed];
      $calls['as UTF8'] = [$latin, 'UTF8', $scrubbed];
      $calls['as null'] = [$latin, null, $scrubbed];
      $calls['with no encoding'] = [$latin, false, $scrubbed];
      $wire = [];
      foreach ($calls as [$input, $encoding]) {
         $wire[] = [bin2hex($input), $encoding];
      }

      // ! Every string of 1 to 4 bytes over the first and last byte of each UTF-8 class — ASCII,
      //   continuations 80-8F/90-9F/A0-BF, C0-C1, C2-DF, E0, E1-EC, ED, EE-EF, F0, F1-F3, F4, F5-FF —
      //   as length-prefixed records: every lead, prefix and interruption ext-mbstring tells apart
      $alphabet = "\x00\x41\x7F\x80\x8F\x90\x9F\xA0\xBF\xC0\xC1\xC2\xDF\xE0\xE1\xEC\xED\xEE\xEF\xF0\xF1\xF3\xF4\xF5\xFF";
      $corpus = '';
      $records = 0;
      $Enumerate = function (string $prefix, int $depth) use (&$Enumerate, &$corpus, &$records, $alphabet): void {
         $bytes = strlen($alphabet);
         // @@
         for ($index = 0; $index < $bytes; $index++) {
            $input = "{$prefix}{$alphabet[$index]}";
            $length = chr(strlen($input));
            $corpus .= "{$length}{$input}";
            $records++;

            if ($depth > 1) {
               $Enumerate($input, $depth - 1);
            }
         }
      };
      $Enumerate('', 4);

      // ! The child: Bootgly boots without ext-mbstring's mb_scrub, so every call reaches the
      //   function `ABI/autoboot.php` defines in its place; it reports and scrubs, nothing more
      $script = <<<'PHP'
      $_SERVER['SCRIPT_FILENAME'] = '';
      require $_SERVER['argv'][1];

      $request = json_decode((string) stream_get_contents(STDIN), true);
      $report = ['exists' => function_exists('mb_scrub'), 'internal' => null, 'file' => null, 'calls' => [], 'corpus' => ''];
      if ($report['exists'] === true) {
         $Function = new ReflectionFunction('mb_scrub');
         $report['internal'] = $Function->isInternal();
         $report['file'] = $Function->getFileName();

         foreach ($request['calls'] as [$input, $encoding]) {
            $input = (string) hex2bin($input);
            $output = $encoding === false ? mb_scrub($input) : mb_scrub($input, $encoding);
            $report['calls'][] = bin2hex($output);
         }

         $corpus = (string) base64_decode($request['corpus']);
         $outputs = '';
         for ($offset = 0, $end = strlen($corpus); $offset < $end; $offset += 1 + $length) {
            $length = ord($corpus[$offset]);
            $output = mb_scrub(substr($corpus, $offset + 1, $length), 'UTF-8');
            $width = chr(strlen($output));
            $outputs .= "{$width}{$output}";
         }
         $report['corpus'] = base64_encode($outputs);
      }

      echo json_encode($report, JSON_INVALID_UTF8_SUBSTITUTE);
      PHP;

      // ! Erase a tree without following links
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff(scandir($target) ?: [], ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      // ! Bytes as a description shows them
      $Hex = static fn (null|string $bytes): string => $bytes === null ? 'nothing' : bin2hex($bytes);

      // @ Run the child — its request (stdin) and its output (stdout, stderr) are files in one
      //   private directory, opened here before it starts: the child and this process only use
      //   the open handles, so a sweep that unlinks the directory meanwhile changes nothing
      $root = BOOTGLY_ROOT_DIR;
      $directory = Temporaries::reserve('scrub-polyfill');
      $streams = [];
      try {
         foreach (['stdin', 'stdout', 'stderr'] as $name) {
            $stream = fopen("{$directory}/{$name}", 'w+');
            // ?
            if ($stream === false) {
               throw new RuntimeException("The mb_scrub() polyfill probe could not open its {$name} file.");
            }
            $streams[$name] = $stream;
         }
         $payload = (string) json_encode(['calls' => $wire, 'corpus' => base64_encode($corpus)]);
         // ?
         if (fwrite($streams['stdin'], $payload) !== strlen($payload)) {
            throw new RuntimeException('The mb_scrub() polyfill probe could not write its request.');
         }
         rewind($streams['stdin']);

         $process = proc_open(
            [
               PHP_BINARY,
               '-d', 'disable_functions=mb_scrub',
               '-d', 'opcache.jit=0',
               '-d', 'display_errors=stderr',
               '-r', $script,
               '--', "{$root}autoboot.php",
            ],
            [
               0 => $streams['stdin'],
               1 => $streams['stdout'],
               2 => $streams['stderr'],
            ],
            $pipes
         );
         // ? A child that never started would leave every leg below without an output to judge
         if (is_resource($process) === false) {
            throw new RuntimeException('The mb_scrub() polyfill probe could not start its child process.');
         }

         // @@ Wait for it to exit — a child that hangs is killed, never waited on forever
         $deadline = hrtime(true) + 120_000_000_000;
         $status = proc_get_status($process);
         while ($status['running'] === true && hrtime(true) < $deadline) {
            usleep(20_000);
            $status = proc_get_status($process);
         }
         $expired = $status['running'] === true;
         if ($expired === true) {
            proc_terminate($process, 9);
         }
         $exit = $expired === true ? -1 : $status['exitcode'];
         proc_close($process);

         rewind($streams['stdout']);
         rewind($streams['stderr']);
         $stdout = (string) stream_get_contents($streams['stdout']);
         $stderr = (string) stream_get_contents($streams['stderr']);
      }
      finally {
         foreach ($streams as $stream) {
            fclose($stream);
         }
         $Erase($directory);
      }

      // ! What the child reported, label by label
      $report = json_decode($stdout, true);
      $report = is_array($report) ? $report : [];
      $results = is_array($report['calls'] ?? null) ? $report['calls'] : [];
      $outputs = [];
      $index = 0;
      foreach (array_keys($calls) as $label) {
         $result = $results[$index] ?? null;
         $index++;
         $outputs[$label] = is_string($result) && preg_match('/\A(?:[0-9a-f]{2})*\z/', $result) === 1
            ? (string) hex2bin($result)
            : null;
      }
      $answered = count($results);
      $asked = count($calls);
      $excerpt = json_encode(substr("{$stderr}{$stdout}", 0, 300), JSON_INVALID_UTF8_SUBSTITUTE);
      $child = "exit {$exit}, {$answered} of {$asked} calls answered, output {$excerpt}";
      // ! Where the polyfill lives
      $file = realpath("{$root}Bootgly/ABI/autoboot.php");
      $defined = is_string($report['file'] ?? null) ? $report['file'] : '(none)';
      // ! ext-mbstring's own mb_scrub(), when this process has it
      $native = function_exists('mb_scrub') && (new ReflectionFunction('mb_scrub'))->isInternal();
      // ! ext-mbstring writes `?` only under its default substitute character — php.ini or an
      //   earlier case may have changed it: pin it for the references, restore it at the end
      $substitute = $native === true ? mb_substitute_character() : null;
      if ($native === true) {
         mb_substitute_character(0x3F);
      }

      try {
         // # The child runs the polyfill
         yield assert(
            assertion: ($report['exists'] ?? null) === true
               && ($report['internal'] ?? null) === false
               && $file !== false
               && realpath($defined) === $file,
            description: "with ext-mbstring's mb_scrub() disabled, the child's mb_scrub() is not internal and is defined in Bootgly/ABI/autoboot.php — defined in: {$defined}; {$child}"
         );

         // # The pinned outputs are ext-mbstring's
         if ($native === true) {
            $disagreements = [];
            foreach ($calls as $label => [$input, $encoding, $output]) {
               // ? Only an explicit encoding: no encoding (and `null`) follow this process' internal one
               if (is_string($encoding) === false) {
                  continue;
               }

               $reference = mb_scrub($input, $encoding);
               if ($reference !== $output) {
                  $disagreements[] = "{$label} ({$Hex($input)}): ext-mbstring {$Hex($reference)}, pinned {$Hex($output)}";
               }
            }
            $conflicts = implode('; ', $disagreements);
            yield assert(
               assertion: $disagreements === [],
               description: "ext-mbstring writes every pinned output (the table, the boundaries, the encodings) — disagreements: {$conflicts}"
            );
         }

         // # One `?` per maximal ill-formed subpart — the pinned table
         $mismatches = [];
         foreach ($table as $label => [$input, $output]) {
            $written = $outputs[$label] ?? null;
            if ($written !== $output) {
               $mismatches[] = "{$label} ({$Hex($input)}): {$Hex($written)}, expected {$Hex($output)}";
            }
         }
         $misses = implode('; ', $mismatches);
         yield assert(
            assertion: $mismatches === [],
            description: "the polyfill writes the pinned output for each of the {$rows} table inputs — mismatches: {$misses}"
         );

         // ---

         // @@ Walk the corpus and the child's outputs in step — counting, keeping the first samples
         $answers = is_string($report['corpus'] ?? null) ? (string) base64_decode($report['corpus'], true) : '';
         $size = strlen($answers);
         $end = strlen($corpus);
         $offset = 0;
         $cursor = 0;
         $walked = 0;
         $malformed = 0;
         $diverged = 0;
         $samples = ['malformed' => [], 'diverged' => []];
         while ($offset < $end && $cursor < $size) {
            $length = ord($corpus[$offset]);
            $input = substr($corpus, $offset + 1, $length);
            $offset += 1 + $length;
            $width = ord($answers[$cursor]);
            $output = substr($answers, $cursor + 1, $width);
            $cursor += 1 + $width;
            $walked++;

            if (preg_match('//u', $output) !== 1 && $malformed++ < 5) {
               $samples['malformed'][] = "{$Hex($input)} → {$Hex($output)}";
            }
            if ($native === true) {
               $reference = mb_scrub($input, 'UTF-8');
               if ($reference !== $output && $diverged++ < 5) {
                  $samples['diverged'][] = "{$Hex($input)}: polyfill {$Hex($output)}, ext-mbstring {$Hex($reference)}";
               }
            }
         }
         $whole = $walked === $records && $offset === $end && $cursor === $size;
         $coverage = "{$walked} of {$records} strings answered";

         // # The polyfill writes what ext-mbstring writes — every string of 1 to 4 boundary bytes
         if ($native === true) {
            $examples = implode('; ', $samples['diverged']);
            yield assert(
               assertion: $whole === true && $diverged === 0,
               description: "the polyfill writes what ext-mbstring writes on every string of 1 to 4 bytes over 25 boundary bytes — {$coverage}, {$diverged} differ: {$examples}"
            );
         }

         // # Every output is well-formed UTF-8
         $unscrubbed = [];
         foreach ($calls as $label => [, $encoding]) {
            // ? Another encoding is returned as is — its output is not UTF-8
            if ($encoding === 'ISO-8859-1') {
               continue;
            }

            $output = $outputs[$label] ?? null;
            if ($output === null || preg_match('//u', $output) !== 1) {
               $unscrubbed[] = "{$label}: {$Hex($output)}";
            }
         }
         $examples = implode('; ', $samples['malformed']);
         $offenders = implode('; ', $unscrubbed);
         yield assert(
            assertion: $whole === true && $malformed === 0 && $unscrubbed === [],
            description: "every UTF-8 output is well-formed UTF-8 — {$coverage}; ill-formed outputs: {$malformed} in the corpus ({$examples}), calls: {$offenders}"
         );

         // # Well-formed UTF-8 comes back byte-identical — alone and around a stray byte
         $alone = $outputs['boundaries'] ?? null;
         $around = $outputs['boundaries around a stray byte'] ?? null;
         yield assert(
            assertion: preg_match('//u', $boundaries) === 1
               && $alone === $boundaries
               && $around === "{$boundaries}?{$boundaries}",
            description: "well-formed UTF-8 (the first and last code point of every lead-byte class) comes back byte-identical alone and around a stray byte — alone: {$Hex($alone)}; around: {$Hex($around)}"
         );

         // # Another encoding: the input comes back as is
         $asLatin = $outputs['as ISO-8859-1'] ?? null;
         $asUTF8 = $outputs['as UTF-8'] ?? null;
         yield assert(
            assertion: $asLatin === $latin && $asUTF8 === $scrubbed,
            description: "mb_scrub(\$text, 'ISO-8859-1') returns the text as is, while the same bytes as UTF-8 are scrubbed — ISO-8859-1: {$Hex($asLatin)}; UTF-8: {$Hex($asUTF8)}"
         );

         // # No encoding, `null` and the UTF-8 aliases scrub as UTF-8
         $aliases = [];
         foreach (['with no encoding', 'as null', 'as utf8', 'as utf-8', 'as UTF8'] as $label) {
            $output = $outputs[$label] ?? null;
            if ($output !== $scrubbed) {
               $aliases[] = "{$label}: {$Hex($output)}";
            }
         }
         $deviations = implode('; ', $aliases);
         yield assert(
            assertion: $aliases === [],
            description: "with no encoding, `null`, `utf8`, `utf-8` and `UTF8`, the polyfill scrubs as UTF-8 — differ: {$deviations}"
         );

         // ? No ext-mbstring here: the pinned outputs and the corpus had nothing to be compared with
         //   (skipped last — a skip's description would otherwise label the assertion after it)
         if ($native === false) {
            yield (new Assertion(description: 'ext-mbstring parity (the pinned outputs, the 1-to-4-byte corpus): this process has no internal mb_scrub()'))->skip();
         }
      }
      finally {
         if ($substitute !== null) {
            mb_substitute_character($substitute);
         }
      }
   }
);
