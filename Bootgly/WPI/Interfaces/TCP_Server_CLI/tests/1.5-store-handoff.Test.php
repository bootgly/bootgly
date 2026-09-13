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


use const STREAM_SOCK_DGRAM;
use function assert;
use function class_exists;
use function decoct;
use function fclose;
use function file_exists;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_link;
use function lstat;
use function mkdir;
use function posix_getgid;
use function posix_getuid;
use function rmdir;
use function stream_socket_server;
use function symlink;
use function unlink;

use Bootgly\ABI\IO\IPC\Pipe as IPCPipe;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Tap;


if (! class_exists('TCPServerCLIHandoffProbe', false)) {
   class TCPServerCLIHandoffProbe extends TCPServer
   {
      public function hand (string $path, int $UID, int $GID, int $kind): bool
      {
         return parent::hand($path, $UID, $GID, $kind);
      }
   }
}


/**
 * The ownership handoff a root launch performs never follows a link.
 *
 * `chown()` follows a symbolic link, so a link the runtime identity left at
 * `storage/logs` (a directory it owns since the first boot) would hand it the
 * TARGET on the next root restart. `hand()` reads the inode with `lstat`,
 * requires it to be of the expected kind, acts on the link itself, and proves
 * the same inode came out the other side. Run as the current user — a chown to
 * one's own identity is allowed — so the MECHANISM is pinned here, and the
 * root-only consequence is proven in a container.
 */
return new Test(
   description: 'The root handoff of storage/logs and the tap socket never follows a link',
   test: function () {
      $base = Temporaries::reserve('tcp-handoff');
      $UID = posix_getuid();
      $GID = posix_getgid();
      $Probe = new TCPServerCLIHandoffProbe(Modes::Test);

      $real = "$base/real";
      $linked = "$base/linked";
      $file = "$base/file.txt";
      $socket = "$base/tap.sock";
      $Server = null;

      try {
         mkdir($real, 0o775);
         symlink($real, $linked);
         file_put_contents($file, '');

         // @@ A) A real directory is handed over
         yield assert(
            assertion: $Probe->hand($real, $UID, $GID, 0040000) === true,
            description: 'a plain directory is handed to the runtime identity'
         );

         // @@ B) A link where the directory should be is refused, and stays a link
         $before = lstat($real);
         $refused = $Probe->hand($linked, $UID, $GID, 0040000);
         $after = lstat($real);

         yield assert(
            assertion: $refused === false
               && is_link($linked) === true
               && $before !== false && $after !== false
               && $before['ino'] === $after['ino'],
            description: 'a symbolic link at the directory pathname is refused, never followed'
         );

         // @@ C) The wrong kind of inode is refused — a file where a directory
         //       should be, a directory where a socket should be
         yield assert(
            assertion: $Probe->hand($file, $UID, $GID, 0040000) === false
               && $Probe->hand($real, $UID, $GID, 0140000) === false
               && $Probe->hand("$base/absent", $UID, $GID, 0040000) === false,
            description: 'a regular file, a directory of the wrong kind and an absent path are all refused'
         );

         // @@ D) The tap socket — the other pathname a root boot hands over
         $Server = stream_socket_server("unix://$socket");

         yield assert(
            assertion: $Server !== false
               && $Probe->hand($socket, $UID, $GID, 0140000) === true,
            description: 'a unix socket is handed over when a socket is what is expected'
         );

         // @@ F) The tap socket is owner-only from creation — no chmod on its
         //       pathname is needed, and none must be
         $Pipe = new IPCPipe(STREAM_SOCK_DGRAM);
         $Pipe->open();
         $Tap = new Tap("$base/live.sock", $Pipe);
         $opened = $Tap->open();
         $perms = @fileperms("$base/live.sock");

         yield assert(
            assertion: $opened === true && $perms !== false && ($perms & 0o777) === 0o600,
            description: 'the tap socket is created owner-only (mode ' . ($perms === false ? 'none' : decoct($perms & 0o777)) . ')'
         );
         $Tap->close();
         $Pipe->close();

         // @@ E) An ownership change that did not happen is reported, never
         //       assumed: to a foreign identity, as an unprivileged user
         if ($UID !== 0) {
            $owner = lstat($real);
            $denied = $Probe->hand($real, 0, 0, 0040000);
            $still = lstat($real);

            yield assert(
               assertion: $denied === false
                  && $owner !== false && $still !== false
                  && $still['uid'] === $owner['uid']
                  && $still['gid'] === $owner['gid'],
               description: 'a handoff the kernel refuses returns false and leaves the owner as found'
            );
         }
      }
      finally {
         if ($Server !== null && $Server !== false) {
            fclose($Server);
         }
         foreach ([$linked, $file, $socket, "$base/live.sock"] as $entry) {
            if (is_link($entry) === true || file_exists($entry) === true) {
               unlink($entry);
            }
         }
         if (is_dir($real) === true) {
            rmdir($real);
         }
         rmdir($base);
      }
   }
);
