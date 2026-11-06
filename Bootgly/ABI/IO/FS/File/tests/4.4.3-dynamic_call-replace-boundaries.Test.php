<?php

use function Bootgly\ABI\remove_recursively;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'replace() replaces links instead of following them, and respects the directory, the jail and local paths',
   test: function () {
      // ! The entries of a directory, hidden ones included
      $entries = static function (string $directory): array {
         clearstatcache(true);
         $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
         sort($entries);

         return $entries;
      };

      $root = Temporaries::reserve('file-replace-boundaries');
      try {
         // @ A symlink at the target is replaced by the new file — its target is never written
         $victim = "{$root}/victim.txt";
         file_put_contents($victim, 'VICTIM');
         $link = "{$root}/config.txt";
         symlink($victim, $link);
         $replaced = new File($link)->replace('CONFIG');
         clearstatcache(true);
         yield assert(
            assertion: $replaced === true && is_link($link) === false && file_get_contents($link) === 'CONFIG',
            description: 'Invalid File #1.1 replace(): the symlink at the target was not replaced by the new file!'
         );
         yield assert(
            assertion: file_get_contents($victim) === 'VICTIM',
            description: 'Invalid File #1.2 replace(): wrote through the symlink at the target!'
         );
         // @ A dangling symlink is replaced too — nothing is created at what it points to
         $dangling = "{$root}/dangling.txt";
         symlink("{$root}/nowhere.txt", $dangling);
         $replaced = new File($dangling)->replace('HERE');
         clearstatcache(true);
         yield assert(
            assertion: $replaced === true && is_link($dangling) === false
               && file_get_contents($dangling) === 'HERE' && file_exists("{$root}/nowhere.txt") === false,
            description: 'Invalid File #1.3 replace(): followed a dangling symlink at the target!'
         );

         // @ A read-only directory — false, the target untouched, no temporary
         $locked = "{$root}/locked";
         mkdir($locked);
         file_put_contents("{$locked}/data.txt", 'OLD');
         $superuser = posix_geteuid() === 0;
         if ($superuser) {
            // ! The superuser ignores directory permissions: the probe runs as `nobody`
            chown($root, 65534);
            chown($locked, 65534);
            chown("{$locked}/data.txt", 65534);
         }
         chmod($locked, 0555);
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         $PID = pcntl_fork();
         if ($PID === 0) {
            fclose($Pair[0]);
            if ($superuser) {
               posix_setgid(65534);
               posix_setuid(65534);
            }
            try {
               $result = new File("{$locked}/data.txt")->replace('NEW') ? 'replaced' : 'refused';
            }
            catch (Throwable) {
               $result = 'error';
            }
            fwrite($Pair[1], $result);
            fclose($Pair[1]);
            posix_kill(posix_getpid(), SIGKILL);
         }
         fclose($Pair[1]);
         $result = (string) stream_get_contents($Pair[0]);
         fclose($Pair[0]);
         pcntl_waitpid($PID, $status);
         chmod($locked, 0755);
         yield assert(
            assertion: $result === 'refused',
            description: "Invalid File #2.1 replace(): did not refuse a read-only directory ({$result})!"
         );
         yield assert(
            assertion: file_get_contents("{$locked}/data.txt") === 'OLD' && $entries($locked) === ['data.txt'],
            description: 'Invalid File #2.2 replace(): a refused replace changed the read-only directory!'
         );

         // @ A missing directory — nothing is created
         $missing = new File("{$root}/absent/data.txt")->replace('NEW');
         yield assert(
            assertion: $missing === false && file_exists("{$root}/absent") === false,
            description: 'Invalid File #3.1 replace(): replaced into a directory that does not exist!'
         );

         // @ The jail
         $jail = "{$root}/jail";
         $outside = "{$root}/outside";
         mkdir($jail);
         mkdir($outside);
         file_put_contents("{$outside}/secret.txt", 'SECRET');

         // # Denied — a target outside the jail
         $escaped = new File("{$outside}/secret.txt", base: $jail)->replace('PWNED');
         yield assert(
            assertion: $escaped === false && file_get_contents("{$outside}/secret.txt") === 'SECRET',
            description: 'Invalid File #4.1 replace(): replaced a file outside the base jail!'
         );
         // # Denied — a new file outside the jail
         $created = new File("{$outside}/new.txt", base: $jail)->replace('PWNED');
         yield assert(
            assertion: $created === false && file_exists("{$outside}/new.txt") === false,
            description: 'Invalid File #4.2 replace(): created a file outside the base jail!'
         );
         // # Denied — a symlinked directory inside the jail leads out of it
         symlink($outside, "{$jail}/door");
         $through = new File("{$jail}/door/secret.txt", base: $jail)->replace('PWNED');
         yield assert(
            assertion: $through === false && file_get_contents("{$outside}/secret.txt") === 'SECRET',
            description: 'Invalid File #4.3 replace(): replaced through a symlinked directory out of the base jail!'
         );
         yield assert(
            assertion: $entries($outside) === ['secret.txt'],
            description: 'Invalid File #4.4 replace(): left a temporary outside the base jail!'
         );
         // # Denied — a traversal out of the jail
         $traversal = new File("{$jail}/../outside/secret.txt", base: $jail)->replace('PWNED');
         yield assert(
            assertion: $traversal === false && file_get_contents("{$outside}/secret.txt") === 'SECRET',
            description: 'Invalid File #4.5 replace(): a traversal replaced a file outside the base jail!'
         );
         // # Allowed — a file in the jail and in a directory below it
         mkdir("{$jail}/sub");
         $inside = new File("{$jail}/inside.txt", base: $jail)->replace('IN');
         $below = new File("{$jail}/sub/below.txt", base: $jail)->replace('BELOW');
         yield assert(
            assertion: $inside === true && $below === true
               && file_get_contents("{$jail}/inside.txt") === 'IN'
               && file_get_contents("{$jail}/sub/below.txt") === 'BELOW',
            description: 'Invalid File #4.6 replace(): denied a file inside the base jail!'
         );
         // # Allowed — a symlink in the jail pointing out is replaced, its target never written
         symlink("{$outside}/secret.txt", "{$jail}/pointer.txt");
         $pointer = new File("{$jail}/pointer.txt", base: $jail)->replace('LOCAL');
         clearstatcache(true);
         yield assert(
            assertion: $pointer === true && is_link("{$jail}/pointer.txt") === false
               && file_get_contents("{$outside}/secret.txt") === 'SECRET',
            description: 'Invalid File #4.7 replace(): a symlink in the jail was followed out of it!'
         );

         // @ Local paths only — stream wrappers and NUL bytes are refused
         $wrappers = new File('php://memory')->replace('DATA') === false
            && new File("file://{$root}/wrapped.txt")->replace('DATA') === false
            && new File("{$root}/nul\0.txt")->replace('DATA') === false
            && new File("{$root}\0/nul.txt")->replace('DATA') === false;
         yield assert(
            assertion: $wrappers && file_exists("{$root}/wrapped.txt") === false,
            description: 'Invalid File #5.1 replace(): accepted a stream wrapper or a NUL byte!'
         );
         // # Refused even where the wrapper spelling resolves as a local path: from a working
         //   directory holding `file:` and `php:` directories, `file:///<root>/w.txt` and
         //   `php://temp` name real local directories — the scheme is refused on the raw path
         $cwd = (string) getcwd();
         $local = "{$root}/cwd";
         mkdir("{$local}/file:{$root}", 0700, true);
         mkdir("{$local}/php:", 0700);
         chdir($local);
         try {
            $schemes = new File("file://{$root}/w.txt")->replace('DATA') === false
               && new File('php://temp')->replace('DATA') === false;
         }
         finally {
            chdir($cwd);
         }
         yield assert(
            assertion: $schemes
               && file_exists("{$local}/file:{$root}/w.txt") === false
               && file_exists("{$local}/php:/temp") === false
               && file_exists("{$root}/w.txt") === false,
            description: 'Invalid File #5.2 replace(): a `file://` or `php://` path was written as a local path!'
         );

         // @ The exact entry named — never the normalized nor the converted path
         // # A backslash in the name is a byte here: normalized, `..\\victim\\x` climbs out
         mkdir("{$root}/tree");
         mkdir("{$root}/victim");
         file_put_contents("{$root}/victim/target.php", 'VICTIM');
         $backslash = new File("{$root}/tree/..\\victim\\target.php")->replace('PWNED');
         yield assert(
            assertion: $backslash === false
               && file_get_contents("{$root}/victim/target.php") === 'VICTIM'
               && $entries("{$root}/tree") === [] && $entries("{$root}/victim") === ['target.php'],
            description: 'Invalid File #7.1 replace(): a backslash name was written outside its directory!'
         );
         // # The same climb inside a jail never reaches the jail's neighbour either
         $jailed = new File("{$jail}/..\\outside\\secret.txt", base: $jail)->replace('PWNED');
         yield assert(
            assertion: $jailed === false && file_get_contents("{$outside}/secret.txt") === 'SECRET',
            description: 'Invalid File #7.2 replace(): a backslash name escaped the base jail!'
         );
         // # A missing name is created as named — never as the `.php` or `/index.php` a read converts to
         $converted = "{$root}/converted";
         mkdir($converted);
         file_put_contents("{$converted}/snap.php", 'CODE');
         mkdir("{$converted}/page");
         file_put_contents("{$converted}/page/index.php", 'INDEX');
         $Snap = new File("{$converted}/snap");
         $read = $Snap->file;
         $snap = $Snap->replace('DATA');
         $page = new File("{$converted}/page/")->replace('DATA');
         yield assert(
            assertion: $snap === true
               && file_get_contents("{$converted}/snap") === 'DATA'
               && file_get_contents("{$converted}/snap.php") === 'CODE'
               && $read === "{$converted}/snap.php" && $Snap->file === "{$converted}/snap",
            description: "Invalid File #7.3 replace(): a missing name landed on its `.php` sibling, or the File still names it ({$read})!"
         );
         yield assert(
            assertion: $page === false && file_get_contents("{$converted}/page/index.php") === 'INDEX'
               && $entries("{$converted}/page") === ['index.php'],
            description: 'Invalid File #7.4 replace(): a directory path landed on its `index.php`!'
         );
         // # A traversal resolves as the kernel resolves it: `link/..` is the link target's parent
         mkdir("{$root}/deep/inner", 0700, true);
         symlink("{$root}/deep/inner", "{$root}/hop");
         $physical = new File("{$root}/hop/../landed.txt")->replace('PHYSICAL');
         yield assert(
            assertion: $physical === true
               && file_get_contents("{$root}/deep/landed.txt") === 'PHYSICAL'
               && file_exists("{$root}/landed.txt") === false,
            description: 'Invalid File #7.5 replace(): `link/..` was folded lexically instead of resolved!'
         );

         // @ A new file is adopted as the File's target
         $New = new File("{$root}/adopted.txt");
         $adopted = $New->replace('ADOPTED');
         yield assert(
            assertion: $adopted === true && $New->file === "{$root}/adopted.txt" && $New->exists === true,
            description: 'Invalid File #6.1 replace(): the new file was not adopted as the target!'
         );
         // @ Nothing cached from the replaced file survives the replace
         $cached = "{$root}/cached.txt";
         file_put_contents($cached, "OLD plain text\n");
         $Cached = new File($cached);
         $before = ['contents' => $Cached->contents, 'format' => $Cached->MIME?->format];
         $replaced = $Cached->replace("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 0600);
         $after = ['contents' => $Cached->contents, 'format' => $Cached->MIME?->format];
         yield assert(
            assertion: $replaced === true && $before['contents'] === "OLD plain text\n"
               && $after['contents'] === "GIF89a\x01\x00\x01\x00\x00\x00\x00;",
            description: 'Invalid File #6.2 replace(): the cached contents still describe the replaced file!'
         );
         yield assert(
            assertion: $before['format'] === 'text' && $after['format'] === 'image',
            description: 'Invalid File #6.3 replace(): the cached MIME still describes the replaced file ('
               . json_encode([$before['format'], $after['format']]) . ')!'
         );
         // # The access flags — the superuser reads and writes whatever the mode says
         $flags = [$Cached->readable, $Cached->writable];
         $Cached->replace('LOCKED', 0200);
         $locked = [$Cached->readable, $Cached->writable];
         chmod($cached, 0600);
         yield assert(
            assertion: posix_geteuid() === 0 || ($flags === [true, true] && $locked === [false, true]),
            description: 'Invalid File #6.4 replace(): the cached access flags still describe the replaced file ('
               . json_encode([$flags, $locked]) . ')!'
         );
      }
      finally {
         @chmod("{$root}/locked", 0755);
         @unlink("{$root}/hop");
         remove_recursively($root);
      }
   }
);
