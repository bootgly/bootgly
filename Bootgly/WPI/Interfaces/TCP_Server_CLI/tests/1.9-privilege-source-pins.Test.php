<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const PREG_OFFSET_CAPTURE;
use function assert;
use function in_array;
use function php_strip_whitespace;
use function preg_match;
use function str_contains;
use function strpos;
use function substr;
use function var_export;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;


/**
 * Two properties of the privilege handoff that only the source can pin.
 *
 * A chmod on a pathname follows a link planted there — the round that
 * chmod()ed the file sink's temporary handed a root chmod primitive to the
 * runtime identity; and cede() must walk a directory BEFORE it changes hands,
 * or the walk runs inside a directory whose owner can rename its entries.
 * Both are races no assertion can reproduce on demand, so the comment-free
 * source of the methods is what gets pinned.
 */
return new Test(
   description: 'File::open() never chmods a pathname and store() cedes a directory before handing it over',
   test: function () {
      $slice = static function (string $class, string $method): string {
         $Method = new ReflectionMethod($class, $method);
         $stripped = (string) php_strip_whitespace((string) $Method->getFileName());
         $from = strpos($stripped, "function {$method} (");
         $body = $from === false ? '' : substr($stripped, $from);
         $next = preg_match('/\b(public|protected|private) (static )?function /', $body, $match, PREG_OFFSET_CAPTURE, 1) === 1
            ? (int) $match[0][1]
            : null;

         return $next === null ? $body : substr($body, 0, $next);
      };

      $open = $slice(File::class, 'open');
      $write = $slice(File::class, 'write');

      yield assert(
         assertion: $open !== '' && $write !== ''
            && str_contains($open, 'chmod(') === false
            && str_contains($write, 'chmod(') === false,
         description: 'File::open() and File::write() carry no chmod() call'
      );

      $Trait = new ReflectionClass(TCPServer::class);
      $store = $slice(TCPServer::class, 'store');
      $provision = $slice(TCPServer::class, 'provision');
      $decide = $slice(TCPServer::class, 'decide');
      $cede = $slice(TCPServer::class, 'cede');
      $entered = strpos($cede, 'chdir(');
      $pinned = strpos($cede, "lstat('.')");
      $compared = strpos($cede, "['ino']");
      $handed = strpos($cede, "\$this->hand('.'");
      $fresh = strpos($cede, "readdir(");
      $walked = str_contains($cede, 'scandir(') || str_contains($cede, '$this->hand($')
         || str_contains($cede, 'lstat($') || str_contains($cede, 'lchown($')
         || str_contains($store, 'opendir(') || str_contains($store, 'scandir(')
         || str_contains($decide, 'opendir(') || str_contains($decide, 'scandir(')
         || str_contains($provision, 'opendir(') || str_contains($provision, 'scandir(');

      yield assert(
         assertion: in_array('Bootgly\\WPI\\Endpoints\\Demotable', $Trait->getTraitNames(), true)
            && $store !== '' && str_contains($store, '$this->decide(\'logs\'')
            && $provision !== '' && str_contains($provision, '$this->decide(')
            && $decide !== '' && str_contains($decide, '$this->cede(')
            && str_contains($store . $provision . $decide, '$this->hand(') === false
            && str_contains($store . $provision, '$this->cede(') === false,
         description: 'Demotable::store() (storage/logs) and provision() (the node\'s worker directories) hand a directory over only through decide() and cede() — never by its pathname'
      );

      yield assert(
         assertion: $cede !== ''
            && $entered !== false && $pinned !== false && $compared !== false && $handed !== false
            && $fresh !== false
            && $entered < $pinned && $pinned < $compared && $compared < $fresh && $fresh < $handed
            && $walked === false
            && strpos($decide, 'if ($created === false) {') !== false
            && str_contains($decide, '(int) $entry[\'uid\'] === $UID')
            && strpos($decide, 'if ($created === false) {') < (int) strpos($decide, '$this->cede(')
            && str_contains($store, 'if ($UID !== 0) { FileHandler::guard($UID); }'),
         description: 'Demotable::cede() enters the directory, pins `.` by dev/ino, proves a fresh one is still empty (entry by entry) and hands `.` over — '
            . 'it touches nothing by name under it, and decide() gives away only a directory this launch created — one the runtime identity already owns is used as is, never chowned — '
            . 'guarding the sink against that identity unless it is root (chdir at ' . var_export($entered, true) . ', lstat at '
            . var_export($pinned, true) . ', readdir at ' . var_export($fresh, true) . ', hand at ' . var_export($handed, true) . ')'
      );

      // @ The demoted master's wait for its workers dispatches no signal: a
      //   worker that died is peeked at (WNOWAIT), its SIGCHLD left where it
      //   was handled before — inside the startup barrier or the supervision loop
      $wait = $slice(TCPServer::class, 'wait');
      yield assert(
         assertion: $wait !== ''
            && str_contains($wait, 'pcntl_signal_dispatch(') === false
            && str_contains($wait, 'pcntl_waitid(') === true
            && str_contains($wait, 'WNOWAIT') === true
            && str_contains($wait, 'posix_kill($PID, 0)') === true,
         description: 'TCP_Server_CLI::wait() probes each worker with kill(pid, 0), peeks a dead one with waitid(WNOWAIT) and dispatches no signal'
      );

      // @ A refused launch says why even with the display muted (the live suites mute theirs)
      $start = $slice(TCPServer::class, 'start');
      $refused = strpos($start, '$refusal = $this->provision(static::DIRECTORIES);');
      $muted = strpos($start, 'if (Display::$segments === Display::NONE) { fwrite(STDERR, "Server not started: {$refusal}\\n"); }');
      $exited = strpos($start, 'exit(1);');
      yield assert(
         assertion: $refused !== false && $muted !== false && $exited !== false && $refused < $muted && $muted < $exited,
         description: 'TCP_Server_CLI::start() writes the provisioning refusal to STDERR when the display is muted, before it exits'
      );
   }
);
