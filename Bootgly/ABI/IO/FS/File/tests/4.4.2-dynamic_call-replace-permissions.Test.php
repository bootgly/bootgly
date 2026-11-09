<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'replace() gives the new file its permissions before the first byte, whatever the umask',
   test: function () {
      // ! The permission bits of an entry
      $mode = static function (string $path): int {
         clearstatcache(true);

         return (int) fileperms($path) & 0777;
      };
      // ! The temporary a writer killed after its first 4 KiB leaves behind, under a umask
      $interrupt = static function (string $path, int $umask, int $permissions) use ($mode): int {
         $PID = pcntl_fork();
         if ($PID === 0) {
            umask($umask);
            posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 4096, 4096);
            new File($path)->replace(str_repeat('x', 65_536), $permissions);
            // ? Not killed: the limit never applied
            posix_kill(posix_getpid(), SIGKILL);
         }
         pcntl_waitpid($PID, $status);

         // @ The one hidden partial temporary, measured and removed
         $directory = dirname($path);
         $observed = -1;
         foreach (glob("{$directory}/.*.tmp") ?: [] as $temporary) {
            if (filesize($temporary) === 4096 && pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGXFSZ) {
               $observed = $mode($temporary);
            }
            unlink($temporary);
         }

         return $observed;
      };

      $root = Temporaries::reserve('file-replace-permissions');
      $umask = umask();
      try {
         $path = "{$root}/secret.key";

         // @ The default — private (0600), even under umask 0
         umask(0);
         $replaced = new File($path)->replace('KEY');
         yield assert(
            assertion: $replaced === true && $mode($path) === 0600,
            description: 'Invalid File #1.1 replace(): the default permissions are not 0600 under umask 0!'
         );
         new File($path)->replace('KEY', 0640);
         yield assert(
            assertion: $mode($path) === 0640,
            description: 'Invalid File #1.2 replace(): 0640 was not applied under umask 0!'
         );

         // @ A restrictive umask never narrows the requested permissions
         umask(0077);
         new File($path)->replace('KEY', 0644);
         yield assert(
            assertion: $mode($path) === 0644,
            description: 'Invalid File #2.1 replace(): 0644 was narrowed by umask 077!'
         );
         // # The window's umask only ever adds bits to the process one: the bits the process
         //   umask removes, and the execute bits `fopen()` never creates, come from the
         //   pinned chmod() — the requested mode lands, and the process umask is restored
         umask(0022);
         $modes = [];
         foreach ([0666, 0755, 0640] as $permissions) {
            $modes[] = new File($path)->replace('KEY', $permissions) === true ? $mode($path) : -1;
         }
         $restored = umask();
         yield assert(
            assertion: $modes === [0666, 0755, 0640] && $restored === 0022,
            description: 'Invalid File #2.2 replace(): the requested mode was narrowed under umask 022, or the umask was not restored ('
               . json_encode(['modes' => array_map('decoct', $modes), 'umask' => decoct($restored)]) . ')!'
         );
         new File($path)->replace('KEY', 0644);
         umask($umask);

         // @ Before the first byte — a writer killed mid-write left a temporary that already has them
         $loose = $interrupt($path, 0, 0600);
         $octal = decoct($loose);
         yield assert(
            assertion: $loose === 0600,
            description: "Invalid File #3.1 replace(): the temporary held bytes without 0600 under umask 0 ({$octal})!"
         );
         $tight = $interrupt($path, 0077, 0644);
         $octal = decoct($tight);
         yield assert(
            assertion: $tight === 0644,
            description: "Invalid File #3.2 replace(): the temporary held bytes without 0644 under umask 077 ({$octal})!"
         );
         $wide = $interrupt($path, 0022, 0666);
         $octal = decoct($wide);
         yield assert(
            assertion: $wide === 0666,
            description: "Invalid File #3.4 replace(): the temporary held bytes without 0666 under umask 022 ({$octal})!"
         );
         yield assert(
            assertion: file_get_contents($path) === 'KEY' && $mode($path) === 0644,
            description: 'Invalid File #3.3 replace(): an interrupted replace changed the target!'
         );

         // @ A default ACL on the directory overrides the umask — the mode is still the one asked for
         $shared = "{$root}/shared";
         mkdir($shared);
         $Setfacl = @proc_open(
            ['setfacl', '-d', '-m', 'u::rwx,g::rwx,o::rwx', $shared],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
         );
         $ACL = is_resource($Setfacl) && proc_close($Setfacl) === 0;
         if ($ACL === false) {
            yield (new Assertion(description: 'Default ACL leg: no setfacl here, or the temporary filesystem refuses ACLs'))->skip();
         }
         else {
            // ! What the ACL gives a new file — the leg proves nothing if it gives the mode asked for
            umask(0077);
            touch("{$shared}/probe");
            $given = $mode("{$shared}/probe");
            unlink("{$shared}/probe");
            $modes = [];
            foreach ([0600, 0640, 0644] as $permissions) {
               $modes[] = new File("{$shared}/acl.key")->replace('KEY', $permissions) === true
                  ? $mode("{$shared}/acl.key")
                  : -1;
            }
            umask($umask);
            yield assert(
               assertion: $given === 0666 && $modes === [0600, 0640, 0644]
                  && glob("{$shared}/.*.tmp") === [],
               description: 'Invalid File #5.1 replace(): a default ACL changed the requested permissions ('
                  . json_encode(['given' => decoct($given), 'modes' => array_map('decoct', $modes)]) . ')!'
            );
         }

         // @ Only permission bits — setuid, setgid, sticky and negatives are refused
         $refused = new File($path)->replace('NEW', 04755) === false
            && new File($path)->replace('NEW', 01777) === false
            && new File($path)->replace('NEW', -1) === false;
         yield assert(
            assertion: $refused && file_get_contents($path) === 'KEY',
            description: 'Invalid File #4.1 replace(): accepted bits beyond 0777!'
         );
      }
      finally {
         umask($umask);
         remove_recursively($root);
      }
   }
);
