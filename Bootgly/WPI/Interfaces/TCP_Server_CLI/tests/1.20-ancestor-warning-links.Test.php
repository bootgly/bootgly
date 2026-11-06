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


use function array_diff;
use function assert;
use function chmod;
use function is_dir;
use function is_link;
use function mkdir;
use function posix_geteuid;
use function realpath;
use function rmdir;
use function scandir;
use function symlink;
use function unlink;
use function var_export;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;


/**
 * SRV-4: the ancestor warning of a worker cache tree checks where a link leads.
 *
 * `Demotable::inspect(links: true)` — the cache trees, whose storage root
 * Config::locate() resolves — walks through a link of root's or the runtime
 * identity's, and then holds its target's chain to the same rule: a target
 * under a directory others may write is reported (the workers' caches move to
 * a private temporary directory there), a safe one is not. The session store
 * (`links: false`) still reports the link itself.
 */
return new Test(
   description: 'SRV-4: the cache-tree ancestor warning walks through a trusted link and checks where it leads',
   test: function () {
      // !
      $UID = posix_geteuid();
      $base = (string) realpath(Temporaries::reserve('ancestor-links'));
      chmod($base, 0o700);
      $Server = new ReflectionClass(TCPServer::class)->newInstanceWithoutConstructor();
      $Inspect = new ReflectionMethod(TCPServer::class, 'inspect');
      $inspect = static fn (string $path, bool $links): mixed => $Inspect->invoke($Server, $path, $UID, $links);
      $purge = static function (string $path) use (&$purge): void {
         if (is_dir($path) && is_link($path) === false) {
            foreach (array_diff((array) @scandir($path), ['.', '..']) as $entry) {
               $purge("{$path}/{$entry}");
            }
            @rmdir($path);

            return;
         }
         @unlink($path);
      };

      try {
         mkdir("{$base}/safe/storage", 0o700, true);
         mkdir("{$base}/shared/storage", 0o700, true);
         chmod("{$base}/shared", 0o775);
         symlink("{$base}/safe/storage", "{$base}/to-safe");
         symlink("{$base}/shared/storage", "{$base}/to-shared");

         // @
         $safe = $inspect("{$base}/to-safe/cache-{$UID}", true);
         $shared = $inspect("{$base}/to-shared/cache-{$UID}", true);
         $session = $inspect("{$base}/to-safe/sessions", false);

         yield assert(
            assertion: $safe === null,
            description: 'a cache tree behind a link of its own to a safe target is not reported (' . var_export($safe, true) . ')'
         );
         yield assert(
            assertion: $shared === ["{$base}/shared", 'mode'],
            description: 'a cache tree behind a link of its own to a target under a group-writable directory reports that '
               . 'directory (' . var_export($shared, true) . ')'
         );
         yield assert(
            assertion: $session === ["{$base}/to-safe", 'link'],
            description: 'the session store still reports the link itself (' . var_export($session, true) . ')'
         );
      }
      finally {
         @chmod("{$base}/shared", 0o700);
         $purge($base);
      }
   }
);
