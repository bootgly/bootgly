<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_WORKING_DIR;
use const PHP_BINARY;
use function assert;
use function chmod;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function fileowner;
use function fileperms;
use function function_exists;
use function getenv;
use function implode;
use function is_array;
use function is_executable;
use function is_file;
use function is_link;
use function is_string;
use function posix_geteuid;
use function posix_getpwnam;
use function readlink;
use function str_contains;
use function symlink;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;


/** @var array<string,int|string>|false $User */
$User = function_exists('posix_getpwnam')
   ? posix_getpwnam('nobody')
   : false;

return new Test(
   description: 'Setup keeps PHP unprivileged and delegates only the fixed installation operation',
   skip: getenv('BOOTGLY_SETUP_PRIVILEGE_AUDIT') !== '1'
      || function_exists('posix_geteuid') === false
      || posix_geteuid() !== 0
      || is_array($User) === false
      || is_executable('/usr/bin/setpriv') === false
      || is_executable('/usr/bin/sudo') === false
      || is_executable('/usr/bin/install') === false,

   test: function () use ($User) {
      if (is_array($User) === false) {
         return;
      }

      $UID = (int) $User['uid'];
      $GID = (int) $User['gid'];
      $installPath = '/usr/local/bin/bootgly';
      $originalLink = is_link($installPath) ? readlink($installPath) : false;
      $originalContent = is_link($installPath) === false && is_file($installPath)
         ? file_get_contents($installPath)
         : false;
      $originalMode = is_file($installPath) ? fileperms($installPath) & 0777 : 0755;

      $restore = static function () use (
         $installPath,
         $originalLink,
         $originalContent,
         $originalMode
      ): void {
         if (is_file($installPath) || is_link($installPath)) {
            @unlink($installPath);
         }
         if (is_string($originalLink)) {
            symlink($originalLink, $installPath);
         }
         elseif (is_string($originalContent)) {
            file_put_contents($installPath, $originalContent);
            chmod($installPath, $originalMode);
         }
      };

      try {
         if (is_file($installPath) || is_link($installPath)) {
            unlink($installPath);
         }

         // ! Prove the harness grants only the exact install argv. A
         //   sudo configuration failure must not look like an H1 regression.
         $fixtureOutput = [];
         $fixtureStatus = -1;
         exec(
            '/usr/bin/setpriv --reuid=' . escapeshellarg((string) $UID)
               . ' --regid=' . escapeshellarg((string) $GID)
               . ' --clear-groups /usr/bin/sudo -n -l -- /usr/bin/install '
               . '-m 0755 /dev/stdin /usr/local/bin/bootgly 2>&1',
            $fixtureOutput,
            $fixtureStatus
         );

         yield assert(
            assertion: $fixtureStatus === 0,
            description: 'H1 fixture: nobody must have passwordless sudo access only to the exact install argv; '
               . 'status=' . $fixtureStatus . '; output=' . implode('|', $fixtureOutput)
         );
         if ($fixtureStatus !== 0) {
            return;
         }

         // @ A caller that already is root must be refused by SetupCommand
         //   before composing or installing anything. The canonical installer
         //   is stricter and never starts this PHP entrypoint as root.
         $rootOutput = [];
         $rootStatus = -1;
         exec(
            'BOOTGLY_JIT=0 ' . escapeshellarg(PHP_BINARY) . ' '
               . escapeshellarg(BOOTGLY_WORKING_DIR . 'bootgly') . ' setup 2>&1',
            $rootOutput,
            $rootStatus
         );
         $rootText = implode('|', $rootOutput);

         yield assert(
            assertion: $rootStatus !== 0
               && str_contains($rootText, 'Setup must run as an ordinary user.'),
            description: $rootStatus === 0
               ? 'CONFIRMED H1: SetupCommand still composes/installs while running at EUID 0; '
                  . 'status=' . $rootStatus . '; output=' . $rootText
               : 'H1 secure setup: SetupCommand refused compose/install at EUID 0; '
                  . 'status=' . $rootStatus . '; output=' . $rootText
         );

         // @ Execute the canonical setup entrypoint as the ordinary user.
         $output = [];
         $status = -1;
         exec(
            '/usr/bin/setpriv --reuid=' . escapeshellarg((string) $UID)
               . ' --regid=' . escapeshellarg((string) $GID)
               . ' --clear-groups env HOME=/tmp '
               . 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin '
               . 'BOOTGLY_JIT=0 '
               . escapeshellarg(PHP_BINARY) . ' '
               . escapeshellarg(BOOTGLY_WORKING_DIR . 'bootgly') . ' setup 2>&1',
            $output,
            $status
         );

         $wrapper = is_file($installPath) ? file_get_contents($installPath) : false;
         $secure = $status === 0
            && is_link($installPath) === false
            && is_string($wrapper)
            && fileowner($installPath) === 0
            && (fileperms($installPath) & 0777) === 0755
            && str_contains($wrapper, 'Refusing to run the Bootgly global wrapper as root.')
            && str_contains(
               $wrapper,
               'SCRIPT=' . escapeshellarg(BOOTGLY_WORKING_DIR . 'bootgly')
            );

         yield assert(
            assertion: $secure,
            description: $status !== 0
               ? 'CONFIRMED H1: setup still requires the whole PHP/Bootgly process to run as root; '
                  . 'status=' . $status . '; output=' . implode('|', $output)
               : 'H1 secure setup: PHP stayed unprivileged and only /usr/bin/install was delegated; '
                  . 'status=' . $status . '; output=' . implode('|', $output)
         );
      }
      finally {
         $restore();
      }
   }
);
