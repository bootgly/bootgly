<?php

namespace Bootgly\ACI\VCS;

use function array_diff;
use function assert;
use function bin2hex;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function putenv;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\VCS;

/**
 * What every git child is started with — proven through a fake `git` binary
 * that records its arguments and its environment: the network deadline on
 * every transfer (`fetch`, `ls-remote`, `submodule update`), the prompts
 * disabled, one language, the `GIT_DIR` family scrubbed and the user's own
 * configuration channel kept.
 */

return new Test(
   description: 'every git child runs under the deadline, without prompts, with the location scrubbed and the configuration kept',
   test: function () {
      $base = sys_get_temp_dir() . '/bootgly-vcs-env-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         // ! A `git` that writes its argv and its environment, and says nothing else
         $log = "{$base}/calls.log";
         $fake = "{$base}/git";
         file_put_contents($fake, "#!/bin/sh\nprintf 'ARGS:%s\\n' \"$*\" >> \"{$log}\"\nenv | sort >> \"{$log}\"\nprintf 'END\\n' >> \"{$log}\"\nexit 0\n");
         chmod($fake, 0755);
         mkdir("{$base}/tree", 0775, true);

         putenv("GIT_DIR={$base}/elsewhere");
         putenv("GIT_CONFIG_GLOBAL={$base}/mine.gitconfig");
         putenv('GIT_ASKPASS=/usr/bin/some-dialog');
         putenv('SSH_ASKPASS=/usr/bin/some-dialog');
         try {
            $VCS = new VCS("{$base}/tree", $fake);
            $VCS->Git->fetch('origin');
            $VCS->Tags->probe('origin');
            $VCS->Submodules->update();
         }
         finally {
            putenv('GIT_DIR');
            putenv('GIT_CONFIG_GLOBAL');
            putenv('GIT_ASKPASS');
            putenv('SSH_ASKPASS');
         }
         $calls = (string) file_get_contents($log);

         yield assert(
            assertion: str_contains($calls, 'ARGS:-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 fetch --no-recurse-submodules origin +refs/tags/*:refs/tags/*')
               && str_contains($calls, 'ARGS:-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 ls-remote --tags --refs -- origin')
               && str_contains($calls, 'ARGS:-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 submodule update'),
            description: 'fetch, ls-remote and submodule update all run under the low-speed deadline'
         );
         yield assert(
            assertion: str_contains($calls, "\nGIT_TERMINAL_PROMPT=0\n") && str_contains($calls, "\nGIT_ASKPASS=") === false
               && str_contains($calls, "\nSSH_ASKPASS=") === false && str_contains($calls, "\nSSH_ASKPASS_REQUIRE=never\n")
               && str_contains($calls, "\nLC_ALL=C\n"),
            description: 'no prompt can hang a headless run — the terminal prompt is off, the git and ssh askpass helpers leave the environment, ssh is told never to ask — and messages come in one language'
         );
         yield assert(
            assertion: str_contains($calls, "\nGIT_DIR=") === false && str_contains($calls, "\nGIT_CONFIG_GLOBAL={$base}/mine.gitconfig\n"),
            description: 'the location family (GIT_DIR) is scrubbed while the user\'s configuration channel (GIT_CONFIG_GLOBAL) reaches the child'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
