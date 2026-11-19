<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\URI\Tests;


use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use function assert;
use function hrtime;
use function ini_get;
use function ini_set;
use function json_encode;
use function sprintf;
use function str_contains;
use function str_repeat;
use function strlen;

use Bootgly\ABI\Data\URI;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * `URI::redact()` drops the userinfo of every URL in a text — a value, a log
 * line, a git message — up to the last `@` before the next `/`, behind any
 * extra slash but in `file://`; it errs on the side of dropping too much (an
 * `@` in a query or a fragment before the first `/`), never too little, and
 * runs no regular expression: a hostile line costs one linear pass, and a
 * PCRE limit cannot make it hand a text back unredacted.
 */

return new Test(
   description: 'URI::redact() drops the userinfo of every URL in a text, linearly and without PCRE',
   test: function () {
      // ! A mismatch as the failure message shows it
      $Show = static fn (string $value): string => (string) json_encode(
         $value,
         JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
      );
      $Compare = static function (array $rows) use ($Show): string {
         $mismatches = '';
         foreach ($rows as $input => $expected) {
            $input = (string) $input;
            $redacted = URI::redact($input);
            if ($redacted !== $expected) {
               $mismatches .= sprintf(' | %s → %s (expected %s)', $Show($input), $Show($redacted), $Show($expected));
            }
         }

         return $mismatches;
      };

      // # One URL
      $mismatches = $Compare([
         'https://user:SYNTHPW@host.invalid/o/r' => 'https://host.invalid/o/r',
         'https://SYNTHTOK@host.invalid/o/r.git' => 'https://host.invalid/o/r.git',
         'HTTPS://SYNTHTOK@host.invalid/x' => 'HTTPS://host.invalid/x',
         'redis://:SYNTHPW@127.0.0.1:6379/0' => 'redis://127.0.0.1:6379/0',
         'postgres://app:SYNTH%40PW@db.invalid/app' => 'postgres://db.invalid/app',
         'https://SYNTHTOK@host.invalid' => 'https://host.invalid',
         'https://@host.invalid/x' => 'https://host.invalid/x',
         // ! The userinfo runs to the LAST `@` before the next `/`
         'https://u@SYNTHTOK:pw@host.invalid/x' => 'https://host.invalid/x',
         "https://u:SYNTH\nPW@host.invalid/x" => 'https://host.invalid/x',
         // ! Any scheme — even one no parser would accept
         '0x://SYNTHTOK@h/x' => '0x://h/x',
         // ! An SSH login goes too: a lone name may be a token
         'ssh://git@host.invalid/o/r' => 'ssh://host.invalid/o/r',
         // ! Slashes past the second one: curl reads the userinfo after them
         'https:///SYNTHTOK@host.invalid/x' => 'https:///host.invalid/x',
         // ! Nothing after the first `/` is userinfo
         'https://host.invalid/o/r@v1' => 'https://host.invalid/o/r@v1',
         'https://host.invalid/o/user:pw@x/r' => 'https://host.invalid/o/user:pw@x/r',
         // ! No `://`, no URL — an scp-like address is not one it can see
         'user:SYNTHPW@host.invalid:o/r' => 'user:SYNTHPW@host.invalid:o/r',
         'git@host.invalid:o/r.git' => 'git@host.invalid:o/r.git',
         'mailto:someone@host.invalid' => 'mailto:someone@host.invalid',
         '' => '',
         '@' => '@',
         '://' => '://',
         '://@' => '://',
      ]);
      yield assert(
         assertion: $mismatches === '',
         description: "One URL loses its userinfo up to the last `@` before the next `/`, whatever the scheme, behind extra slashes too; paths and scp-like values are untouched{$mismatches}"
      );

      // # Several URLs, prose and messages
      $mismatches = $Compare([
         'https://a@h1 https://b@h2/x' => 'https://h1 https://h2/x',
         'https://h/p/https://SYNTHTOK@x/' => 'https://h/p/https://x/',
         'x://a@b://c@d/' => 'x://b://d/',
         'see https://SYNTHTOK@host.invalid/x and more' => 'see https://host.invalid/x and more',
         "fatal: could not read Password for 'http://SYNTHTOK@127.0.0.1:18533': terminal prompts disabled"
            => "fatal: could not read Password for 'http://127.0.0.1:18533': terminal prompts disabled",
         "Submodule 'sub' (https://SYNTHTOK@127.0.0.1:1/s.git) registered for path 'sub'"
            => "Submodule 'sub' (https://127.0.0.1:1/s.git) registered for path 'sub'",
         ' * [new tag]         v1@2       -> v1@2' => ' * [new tag]         v1@2       -> v1@2',
         'From https://host.invalid/o/r' => 'From https://host.invalid/o/r',
      ]);
      yield assert(
         assertion: $mismatches === '',
         description: "Every URL in a text loses its userinfo — nested, side by side, inside a message — and a text with none comes back as is{$mismatches}"
      );

      // # `file://`
      $mismatches = $Compare([
         // ! Past its second slash, a path: an `@` there stays
         'file:///a@b/x' => 'file:///a@b/x',
         'FILE:///a@b/x' => 'FILE:///a@b/x',
         'file:///tmp/a@b' => 'file:///tmp/a@b',
         // ! ...while the next URL is still read
         'file:///a@b https://SYNTHTOK@h/x' => 'file:///a@b https://h/x',
         // ! A `file://` userinfo goes, leaving the empty authority
         'file://u:SYNTHPW@/tmp/x' => 'file:///tmp/x',
         'file://SYNTHTOK@/x' => 'file:///x',
         // ! A scheme that only ends in `file` is not `file`
         'xfile:///SYNTHTOK@h/x' => 'xfile:///h/x',
      ]);
      yield assert(
         assertion: $mismatches === '',
         description: "`file://` past its second slash is a path and keeps its `@`; its own userinfo goes; `xfile:///` is another scheme{$mismatches}"
      );

      // # Over-redaction is the safe side
      $mismatches = $Compare([
         // ! An `@` in a query or a fragment before the first `/` takes the text before it
         'https://h?a@b/x' => 'https://b/x',
         'https://h#a@b/x' => 'https://b/x',
         'https://SYNTHTOK?@host.invalid/x' => 'https://host.invalid/x',
      ]);
      yield assert(
         assertion: $mismatches === '',
         description: "An `@` before the first `/` goes even in a query or a fragment — dropping too much, never too little{$mismatches}"
      );

      // # No PCRE: a starved regex engine changes nothing
      $JIT = ini_get('pcre.jit');
      $limit = ini_get('pcre.backtrack_limit');
      $set = ini_set('pcre.jit', '0') !== false && ini_set('pcre.backtrack_limit', '100') !== false;
      try {
         $line = str_repeat('x', 4096) . ' https://SYNTHTOK@host.invalid/x';
         $redacted = URI::redact($line);
         $malformed = URI::redact('https://[::1@SYNTHTOK@h]:x:y/z');
      }
      finally {
         ini_set('pcre.jit', (string) $JIT);
         ini_set('pcre.backtrack_limit', (string) $limit);
      }
      yield assert(
         assertion: $set === true
            && str_contains($redacted, 'SYNTHTOK') === false
            && $redacted === str_repeat('x', 4096) . ' https://host.invalid/x'
            && $malformed === 'https://h]:x:y/z',
         description: 'With pcre.jit off and a backtrack limit of 100, a long line and a malformed authority still come back redacted'
      );

      // # Linear: a hostile 64 KiB text costs milliseconds
      $texts = [
         'one authority, 32768 `@`' => 'https://' . str_repeat('a@', 32768),
         '13107 openings' => str_repeat('a://@', 13107),
         'interleaved' => str_repeat('x://a b@c:', 6553),
      ];
      foreach ($texts as $shape => $text) {
         $started = hrtime(true);
         $redacted = URI::redact($text);
         $elapsed = (hrtime(true) - $started) / 1e6;

         yield assert(
            assertion: str_contains($redacted, '@') === false && $elapsed < 250,
            description: sprintf('%s (%d bytes) redacted in %.1f ms', $shape, strlen($text), $elapsed)
         );
      }
   }
);
