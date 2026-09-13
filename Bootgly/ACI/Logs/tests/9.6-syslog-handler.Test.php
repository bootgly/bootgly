<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Logs\Handlers\Syslog;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The Syslog handler never follows the terminal and sends one whole line.
 *
 * A detached daemon runs with `Display::NONE`; a formatter that followed that
 * mask would send empty entries. The handler formats with its own mask and
 * flattens a multi-line message into one entry, or the system logger would
 * split it and lose the severity/channel prefix on the continuation lines.
 */
return new Test(
   description: 'Syslog handler sends one complete entry per record under Display::NONE',
   test: function () {
      $canary = 'SYSLOG-CANARY-' . bin2hex(random_bytes(4));
      $since = '@' . (time() - 1);
      $segments = Display::$segments;

      try {
         Display::$segments = Display::NONE;
         $Handler = new Syslog('bootgly-test');
         $sent = $Handler->handle(new Record(Levels::Notice, 'Suite', "$canary first line\nsecond line"));
      }
      finally {
         Display::$segments = $segments;
      }

      yield assert(
         assertion: $sent === true,
         description: 'the handler reports the record as written'
      );

      // ? Read back where a journal is readable; labelled otherwise
      // ? Readability by exit status, never by content: an empty last entry is
      //   exactly what an unflattened record leaves behind
      $readable = trim((string) shell_exec('command -v journalctl 2>/dev/null')) !== ''
         && trim((string) shell_exec('journalctl -n 0 >/dev/null 2>&1; echo $?')) === '0';
      if ($readable === false) {
         yield assert(assertion: true, description: 'journal not readable here — the entry shape is not checked');

         return;
      }
      $deadline = microtime(true) + 5.0;
      $entries = [];
      do {
         $journal = (string) shell_exec('journalctl -t bootgly-test --since ' . escapeshellarg($since) . ' --no-pager -o cat 2>/dev/null');
         $entries = array_values(array_filter(explode("\n", $journal), static fn (string $line): bool => str_contains($line, $canary)));
         if ($entries === []) {
            usleep(250000);
         }
      }
      while ($entries === [] && microtime(true) < $deadline);

      yield assert(
         assertion: count($entries) === 1
            && str_contains($entries[0], 'Suite.NOTICE')
            && str_contains($entries[0], "$canary first line second line"),
         description: 'one journal entry carries the channel, the severity and the whole flattened message (' . implode(' | ', $entries) . ')'
      );
   }
);
