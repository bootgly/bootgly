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
      $cede = $slice(TCPServer::class, 'cede');
      $entered = strpos($cede, 'chdir(');
      $pinned = strpos($cede, "lstat('.')");
      $compared = strpos($cede, "['ino']");
      $handed = strpos($cede, "\$this->hand('.'");
      $fresh = strpos($cede, "readdir(");
      $walked = str_contains($cede, 'scandir(') || str_contains($cede, '$this->hand($')
         || str_contains($cede, 'lstat($') || str_contains($cede, 'lchown($')
         || str_contains($store, 'opendir(') || str_contains($store, 'scandir(');

      yield assert(
         assertion: in_array('Bootgly\\WPI\\Endpoints\\Demotable', $Trait->getTraitNames(), true)
            && $store !== '' && str_contains($store, '$this->cede(')
            && str_contains($store, '$this->hand($directory') === false,
         description: 'Demotable::store() hands storage/logs over only through cede() — never by its pathname'
      );

      yield assert(
         assertion: $cede !== ''
            && $entered !== false && $pinned !== false && $compared !== false && $handed !== false
            && $fresh !== false
            && $entered < $pinned && $pinned < $compared && $compared < $fresh && $fresh < $handed
            && $walked === false
            && str_contains($store, '$created === false && (int) $entry[\'uid\'] !== $UID')
            && str_contains($store, 'if ($UID !== 0) { FileHandler::guard($UID); }'),
         description: 'Demotable::cede() enters the directory, pins `.` by dev/ino, proves a fresh one is still empty (entry by entry) and hands `.` over — '
            . 'it touches nothing by name under it, and store() gives away only a directory this launch created or already the runtime identity\'s, '
            . 'guarding the sink against that identity unless it is root (chdir at ' . var_export($entered, true) . ', lstat at '
            . var_export($pinned, true) . ', readdir at ' . var_export($fresh, true) . ', hand at ' . var_export($handed, true) . ')'
      );
   }
);
