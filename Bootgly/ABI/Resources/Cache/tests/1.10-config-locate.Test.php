<?php

use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * SRV-4: `Config::locate()` keeps a process in a cache directory it can trust.
 *
 * A candidate is trusted when it is a plain directory of this process's own
 * that nobody else may write, under ancestors only root or this process can
 * change — created owner-only when missing inside a parent of its own. In
 * order: `storage/cache`, `storage/cache-<euid>`, then a private
 * `bootgly-<euid>/<app>` in the system temporary directory, one per storage
 * root. The configured root is checked as written before it is resolved, and
 * nothing is created under a chain that would be refused. Another owner's
 * directory is pinned by the root legs of `TCP_Server_CLI/tests/1.19`.
 */
return new Test(
   description: 'SRV-4: Config::locate() keeps a process in a cache directory it can trust',
   test: function () {
      $EUID = posix_geteuid();
      $base = (string) realpath(Temporaries::reserve('cache-locate'));
      // ! Owner-only whatever the umask: a group-writable root is trusted by nothing
      chmod($base, 0o700);
      $cache = "{$base}/cache";
      $own = "{$base}/cache-{$EUID}";
      $system = (string) realpath(sys_get_temp_dir());
      $temporary = "{$system}/bootgly-{$EUID}/" . substr(hash('sha256', $base), 0, 16);
      $mode = static fn (string $path): int => is_array($state = @lstat($path)) ? (int) $state['mode'] & 0o777 : -1;
      // ! Whatever locate() hands out is a plain directory of this process's own
      //   that nobody else may write
      $owns = static fn (string $path): bool => is_array($state = @lstat($path))
         && ((int) $state['mode'] & 0o170000) === 0o040000
         && (int) $state['uid'] === $EUID
         && ((int) $state['mode'] & 0o022) === 0;
      $clean = static function () use ($base): void {
         foreach (array_diff((array) @scandir($base), ['.', '..']) as $entry) {
            $path = "{$base}/{$entry}";
            is_dir($path) && is_link($path) === false ? @rmdir($path) : @unlink($path);
         }
      };

      try {
         // @ Missing inside a storage root of its own: created owner-only
         $located = Config::locate("{$base}/");
         yield assert(
            assertion: $located === $cache && $mode($cache) === 0o700 && $owns($located),
            description: "a missing storage/cache is created owner-only and used ({$located}, " . decoct($mode($cache)) . ')'
         );
         $clean();

         // @ A plain directory of its own, writable by nobody else
         mkdir($cache, 0o700);
         $located = Config::locate("{$base}/");
         yield assert(
            assertion: $located === $cache,
            description: "storage/cache of this process's own is used ({$located})"
         );

         // @ The same, group-writable: not trusted
         chmod($cache, 0o775);
         $located = Config::locate("{$base}/");
         yield assert(
            assertion: $located === $own && $mode($own) === 0o700 && $owns($located),
            description: "a group-writable storage/cache is not trusted: cache-<euid> is created owner-only ({$located})"
         );
         $clean();

         // @ A link — whatever it points at
         mkdir("{$base}/elsewhere", 0o700);
         symlink("{$base}/elsewhere", $cache);
         $located = Config::locate("{$base}/");
         yield assert(
            assertion: $located === $own,
            description: "a link at storage/cache is never followed ({$located})"
         );
         $clean();

         // @ Not a directory
         file_put_contents($cache, '');
         $located = Config::locate("{$base}/");
         yield assert(
            assertion: $located === $own,
            description: "a file at storage/cache sends the process to its own cache-<euid> ({$located})"
         );
         $clean();

         // @ A storage root others may write: nothing in it is trusted
         chmod($base, 0o777);
         $located = Config::locate("{$base}/");
         chmod($base, 0o700);
         yield assert(
            assertion: $located === $temporary && $owns($located) && is_dir($cache) === false && is_dir($own) === false,
            description: "under a world-writable storage root this storage root's own private temporary directory is used, nothing is created there ({$located})"
         );
         @rmdir($temporary);

         // @ The trailing slash does not matter
         yield assert(
            assertion: Config::locate($base) === Config::locate("{$base}/"),
            description: 'locate() resolves the same directory with or without the trailing slash'
         );
         $clean();

         // @ trust() itself: creates a missing directory owner-only in a parent of
         //   its own, and trusts nothing under a parent others may write
         $made = Config::trust("{$base}/made", $EUID);
         chmod($base, 0o770);
         $open = Config::trust("{$base}/open", $EUID);
         chmod($base, 0o700);
         yield assert(
            assertion: $made === true && $mode("{$base}/made") === 0o700 && $open === false && is_dir("{$base}/open") === false,
            description: 'trust() creates a missing directory owner-only inside a parent of its own, and refuses — creating '
               . 'nothing — under a group-writable one (made=' . var_export($made, true) . ', open=' . var_export($open, true) . ')'
         );
         $clean();

         // @ trust() walks every ancestor before creating anything: under a chain
         //   that is refused nothing is made, however trusted the parent itself
         mkdir("{$base}/open", 0o700);
         mkdir("{$base}/open/own", 0o700);
         chmod("{$base}/open", 0o777);
         $deep = Config::trust("{$base}/open/own/new", $EUID);
         chmod("{$base}/open", 0o700);
         yield assert(
            assertion: $deep === false && is_dir("{$base}/open/own/new") === false,
            description: 'trust() refuses — creating nothing — under an ancestor others may write, even inside a parent of its own'
         );
         @rmdir("{$base}/open/own");
         $clean();

         // @ The configured path is checked as written: a component on the way
         //   that others may write (no sticky bit) is never resolved through
         mkdir("{$base}/open", 0o700);
         mkdir("{$base}/real", 0o700);
         symlink("{$base}/real", "{$base}/open/storage");
         chmod("{$base}/open", 0o777);
         $through = Config::locate("{$base}/open/storage");
         chmod("{$base}/open", 0o700);
         yield assert(
            assertion: str_starts_with($through, "{$base}/") === false && $owns($through)
               && is_dir("{$base}/real/cache") === false,
            description: "a storage root reached through a directory others may write is never used, nothing is created behind it ({$through})"
         );
         @unlink("{$base}/open/storage");
         @rmdir("{$base}/real");
         @rmdir($through);
         $clean();

         // @ A memoized location: a private temporary one is proven again on every
         //   use — one recreated open (as anyone could there) or removed is never
         //   handed out again; one in the storage tree is handed out as is, until
         //   a consumer that found it gone calls forget()
         $seed = static fn (null|array $entry) => (static function () use ($EUID, $entry): void {
            if ($entry === null) {
               unset(self::$directories[$EUID]);
               return;
            }
            self::$directories[$EUID] = $entry;
         })->bindTo(null, Config::class)();
         mkdir("{$base}/fallback", 0o700);
         mkdir("{$base}/fallback/app", 0o700);
         try {
            $seed(["{$base}/fallback/app", "{$base}/fallback", null]);
            $kept = Config::locate();
            chmod("{$base}/fallback", 0o777);
            $reopened = Config::locate();
            chmod("{$base}/fallback", 0o700);
            $seed(["{$base}/fallback/app", "{$base}/fallback", null]);
            rmdir("{$base}/fallback/app");
            $removed = Config::locate();
            $seed(["{$base}/fallback/app", null, null]);
            $stored = Config::locate();
            Config::forget();
            $forgotten = Config::locate();
         }
         finally {
            $seed(null);
            @chmod("{$base}/fallback", 0o700);
         }
         yield assert(
            assertion: $kept === "{$base}/fallback/app"
               && $reopened !== "{$base}/fallback/app" && $owns($reopened)
               && $removed !== "{$base}/fallback/app" && $owns($removed)
               && $stored === "{$base}/fallback/app"
               && $forgotten !== "{$base}/fallback/app" && $owns($forgotten),
            description: 'a memoized temporary directory is kept while it stays its own and never handed out again once its '
               . 'parent is open or it is gone; a memoized storage one is kept until forget() — then located again '
               . "(kept={$kept}, reopened={$reopened}, removed={$removed}, stored={$stored}, forgotten={$forgotten})"
         );
         $clean();

         // @ A system temporary directory others may write: `/tmp` is tried next —
         //   in a process of its own, with sys_temp_dir pointed there
         mkdir("{$base}/open", 0o700);
         chmod("{$base}/open", 0o777);
         $script = 'require $argv[1]; echo Bootgly\\ABI\\Resources\\Cache\\Config::locate($argv[2]);';
         $fallback = trim((string) shell_exec(
            escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg("sys_temp_dir={$base}/open") . ' -r ' . escapeshellarg($script)
            . ' ' . escapeshellarg((string) realpath(__DIR__ . '/../Config.php')) . ' ' . escapeshellarg("{$base}/open") . ' 2>&1'
         ));
         chmod("{$base}/open", 0o700);
         $slash = (string) realpath('/tmp');
         yield assert(
            assertion: str_starts_with($fallback, "{$slash}/bootgly-{$EUID}/") && $owns($fallback)
               && is_dir("{$base}/open/bootgly-{$EUID}") === false,
            description: "with the system temporary directory open to others, /tmp is tried next and nothing is created in the open one ({$fallback})"
         );
         @rmdir($fallback);
         $clean();

         // ! When nothing can be trusted, the refusal names every directory tried
         $source = (string) php_strip_whitespace((string) realpath(__DIR__ . '/../Config.php'));
         yield assert(
            assertion: str_contains($source, "foreach ([sys_get_temp_dir(), '/tmp'] as \$system)")
               && str_contains($source, 'throw new RuntimeException("No cache directory this process (uid {$EUID}) can trust: neither the storage tree nor {$tried}.");'),
            description: 'locate() tries the system temporary directory, then /tmp, and refuses naming both when neither can be trusted'
         );

         // @ A relative storage root is taken from the working directory — and the
         //   walk up its components ends (a relative path climbs to `.`)
         mkdir("{$base}/relative", 0o700);
         $cwd = (string) getcwd();
         chdir($base);
         try {
            $relative = Config::locate('relative');
         }
         finally {
            chdir($cwd);
         }
         yield assert(
            assertion: $relative === "{$base}/relative/cache" && $owns($relative),
            description: "a relative storage root resolves against the working directory ({$relative})"
         );
         @rmdir("{$base}/relative/cache");
         @rmdir("{$base}/relative");

         // @ A component of the storage root that does not exist yet: refused —
         //   whoever creates it first would choose where it leads
         $missing = Config::locate("{$base}/missing/storage");
         $steady = static fn (string $path): bool => (static fn (): bool => self::steady($path, $EUID))->bindTo(null, Config::class)();
         yield assert(
            assertion: str_starts_with($missing, "{$base}/") === false && $owns($missing) && is_dir("{$base}/missing") === false
               && $steady("{$base}/missing/storage") === false && $steady($base) === true,
            description: "a storage root with a missing component is never used, nothing is created there ({$missing})"
         );
         @rmdir($missing);
         $clean();

         // @ A storage root reached through a link: where the link leads is held to
         //   the same rule — a target through a name that does not exist (anyone
         //   could make it there first) is never resolved through
         mkdir("{$base}/target/storage", 0o700, true);
         symlink("{$base}/target/storage", "{$base}/to-target");
         symlink("{$base}/vanished/storage", "{$base}/to-vanished");
         $dangling = Config::locate("{$base}/to-vanished");
         yield assert(
            assertion: $steady("{$base}/to-target") === true && $steady("{$base}/to-vanished") === false
               && str_starts_with($dangling, "{$base}/") === false && $owns($dangling)
               && is_dir("{$base}/vanished") === false,
            description: "a link's target is checked like the written path — one through a missing name is never resolved through ({$dangling})"
         );
         @rmdir($dangling);
         @unlink("{$base}/to-target");
         @unlink("{$base}/to-vanished");
         @rmdir("{$base}/target/storage");
         @rmdir("{$base}/target");

         // @ The filesystem root as storage root: `/` itself, never the working
         //   directory — the private fallback is named after `/` — and never
         //   anything made at the real `/` (as root, only steady() is asked)
         $slashed = $steady('/');
         $slash = '';
         if ($EUID !== 0) {
            $cwd = (string) getcwd();
            chdir($base);
            try {
               $slash = Config::locate('/');
            }
            finally {
               chdir($cwd);
            }
         }
         $named = substr(hash('sha256', '/'), 0, 16);
         yield assert(
            assertion: $slashed === true && ($EUID === 0 || (
               str_starts_with($slash, "{$base}/") === false && $owns($slash)
               && ($slash === "/cache-{$EUID}" || str_ends_with($slash, "/bootgly-{$EUID}/{$named}"))
            )),
            description: "a storage root of / is / itself — steady, and its private fallback named after / — never the working directory ({$slash})"
         );
         if (str_ends_with($slash, "/bootgly-{$EUID}/{$named}")) {
            @rmdir($slash);
         }
         $clean();

         // @ Relative link targets, from the link's parent; a link loop and a long
         //   chain of links back to the same directory answer at once
         mkdir("{$base}/target/storage", 0o700, true);
         symlink('target/storage', "{$base}/to-relative");
         symlink('vanished/storage', "{$base}/to-nowhere");
         symlink('loop', "{$base}/loop");
         symlink('.', "{$base}/l");
         $chain = $base . str_repeat('/l', 16) . '/target/storage';
         $relative = Config::locate("{$base}/to-relative");
         $started = hrtime(true);
         $chained = $steady($chain);
         $elapsed = (hrtime(true) - $started) / 1e9;
         yield assert(
            assertion: $steady("{$base}/to-relative") === true && $relative === "{$base}/target/storage/cache"
               && $steady("{$base}/to-nowhere") === false && $steady("{$base}/loop") === false
               && $chained === true && $elapsed < 1.0,
            description: 'a relative link target is checked from the link\'s parent (used: ' . $relative . '), one through a missing name '
               . 'and a link loop are refused, and 16 links back to the same directory answer in ' . round($elapsed, 3) . 's'
         );
         @rmdir("{$base}/target/storage/cache");
         foreach (['to-relative', 'to-nowhere', 'loop', 'l'] as $link) {
            @unlink("{$base}/{$link}");
         }
         @rmdir("{$base}/target/storage");
         @rmdir("{$base}/target");
         $clean();

         // ! A process of its own booted on a scratch storage root, for what only
         //   the booted (memoized) location does
         $lab = (string) realpath(Temporaries::reserve('cache-locate-lab'));
         $boot = "{$lab}/boot.php";
         file_put_contents($boot, '<?php
[, $storage, $code] = $argv;
define(\'BOOTGLY_ROOT_BASE\', ' . var_export(BOOTGLY_ROOT_BASE, true) . ');
define(\'BOOTGLY_ROOT_DIR\', ' . var_export(BOOTGLY_ROOT_DIR, true) . ');
define(\'BOOTGLY_WORKING_BASE\', ' . var_export(BOOTGLY_WORKING_BASE, true) . ');
define(\'BOOTGLY_WORKING_DIR\', ' . var_export(BOOTGLY_WORKING_DIR, true) . ');
define(\'BOOTGLY_STORAGE_BASE\', rtrim($storage, \'/\'));
define(\'BOOTGLY_STORAGE_DIR\', $storage);
define(\'BOOTGLY_VERSION\', ' . var_export(BOOTGLY_VERSION, true) . ');
define(\'BOOTGLY_SAPI\', \'cli\');
spl_autoload_register(static function (string $class): void {
   $file = BOOTGLY_ROOT_DIR . str_replace(\'\\\\\', \'/\', $class) . \'.php\';
   if (is_file($file)) {
      require $file;
   }
});
require BOOTGLY_ROOT_DIR . \'Bootgly/ABI/autoboot.php\';
require $code;
');
         $spawn = static function (string $storage, string $code) use ($lab, $boot): array {
            $file = "{$lab}/code-" . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($file, "<?php\nuse Bootgly\\ABI\\Resources\\Cache;\nuse Bootgly\\ABI\\Resources\\Cache\\Config;\n{$code}");
            $output = (string) shell_exec(
               escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($boot) . ' ' . escapeshellarg($storage) . ' ' . escapeshellarg($file) . ' 2>&1'
            );
            @unlink($file);
            $decoded = json_decode(trim($output), true);

            return is_array($decoded) ? $decoded : ['output' => $output];
         };
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
            // @ Under a sticky shared directory, the memo watches the entry just
            //   below it: removed and made again — open, or as a link — the old
            //   location is never handed out again (anyone could have made it)
            mkdir("{$lab}/shared", 0o700);
            chmod("{$lab}/shared", 0o1777);
            mkdir("{$lab}/shared/app/storage", 0o700, true);
            mkdir("{$lab}/elsewhere/storage", 0o700, true);
            $code = <<<'CODE'
            $purge = static function (string $path) use (&$purge): void {
               if (is_dir($path) && is_link($path) === false) {
                  foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) { $purge("{$path}/{$entry}"); }
                  rmdir($path);
                  return;
               }
               if (is_link($path) || is_file($path)) { unlink($path); }
            };
            $app = dirname(BOOTGLY_STORAGE_DIR);
            // ! Each case from a fresh memo of the original layout
            $reset = static function () use ($app, $purge): bool {
               $purge($app);
               mkdir("{$app}/storage", 0700, true);
               Config::forget();

               return Config::locate() === "{$app}/storage/cache";
            };
            $first = Config::locate();
            $guard = (static fn (): mixed => self::$directories[posix_geteuid()][2][0] ?? null)->bindTo(null, Config::class)();
            // # Moved aside, a link left at its name
            rename($app, "{$app}.old");
            symlink("{$app}.old", $app);
            $moved = Config::locate();
            unlink($app);
            $purge("{$app}.old");
            // # Opened to all in place — same inode and owner
            $fresh = $reset();
            chmod($app, 0777);
            $widened = Config::locate();
            chmod($app, 0700);
            // # Same inode, another owner (what a reused inode number looks like)
            $fresh = $reset() && $fresh;
            (static function (): void {
               self::$directories[posix_geteuid()][2][3] = posix_geteuid() + 1;
            })->bindTo(null, Config::class)();
            Config::locate();
            // ! Dropped and located anew: the same, still trusted, path — with its
            //   real owner recorded again
            $owner = (static fn (): mixed => self::$directories[posix_geteuid()][2][3] ?? null)->bindTo(null, Config::class)() === posix_geteuid();
            // # Removed, made again open to all
            $fresh = $reset() && $fresh;
            $purge($app);
            mkdir("{$app}/storage/cache", 0700, true);
            chmod($app, 0777);
            $open = Config::locate();
            // # Removed, made again as a link
            $fresh = $reset() && $fresh;
            $purge($app);
            symlink(dirname($app) . '/../elsewhere', $app);
            $linked = Config::locate();
            echo json_encode(['first' => $first, 'guard' => $guard, 'fresh' => $fresh, 'moved' => $moved, 'widened' => $widened, 'owner' => $owner, 'open' => $open, 'linked' => $linked]);
            CODE;
            $shared = $spawn("{$lab}/shared/app/storage/", $code);
            yield assert(
               assertion: ($shared['first'] ?? null) === "{$lab}/shared/app/storage/cache"
                  && ($shared['guard'] ?? null) === "{$lab}/shared/app"
                  && ($shared['fresh'] ?? null) === true
                  && is_string($shared['moved'] ?? null) && $shared['moved'] !== $shared['first']
                  && is_string($shared['widened'] ?? null) && $shared['widened'] !== $shared['first'] && $owns($shared['widened'])
                  && ($shared['owner'] ?? null) === true
                  && is_string($shared['open'] ?? null) && $shared['open'] !== $shared['first'] && $owns($shared['open'])
                  && is_string($shared['linked'] ?? null) && $shared['linked'] !== $shared['first'] && $owns($shared['linked']),
               description: 'under a sticky shared directory the memo watches the entry just below it, and drops the location '
                  . 'once that entry is moved aside behind a link, opened to all, owned by someone else, or made again — open or '
                  . 'as a link — then located anew ('
                  . var_export($shared, true) . ')'
            );
            foreach (['moved', 'widened', 'open', 'linked'] as $leg) {
               if (is_string($shared[$leg] ?? null) && str_starts_with($shared[$leg], "{$lab}/") === false) {
                  @rmdir($shared[$leg]);
               }
            }

            // @ The File driver on the located default: cleared by hand while in use,
            //   located again — recreated owner-only, whatever the umask — never
            //   remade group-writable; what was stored after is kept
            mkdir("{$lab}/files/storage", 0o700, true);
            $code = <<<'CODE'
            umask(0002);
            $Cache = new Cache([]);
            $Cache->store('a', 1);
            $before = $Cache->Config->path;
            $purge = static function (string $path) use (&$purge): void {
               if (is_dir($path) && is_link($path) === false) {
                  foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) { $purge("{$path}/{$entry}"); }
                  rmdir($path);
                  return;
               }
               unlink($path);
            };
            $purge($before);
            $Cache->store('b', 2);
            $after = $Cache->Config->path;
            echo json_encode([
               'before' => $before, 'after' => $after, 'mode' => fileperms($after) & 0777,
               'b' => $Cache->fetch('b'), 'located' => $Cache->Config->located, 'given' => new Config(['path' => '/x'])->located,
            ]);
            CODE;
            $files = $spawn("{$lab}/files/storage/", $code);
            yield assert(
               assertion: ($files['before'] ?? null) === "{$lab}/files/storage/cache"
                  && ($files['after'] ?? null) === "{$lab}/files/storage/cache"
                  && ($files['mode'] ?? null) === 0o700
                  && ($files['b'] ?? null) === 2
                  && ($files['located'] ?? null) === true && ($files['given'] ?? null) === false,
               description: 'the File driver on the located default recreates a cache cleared by hand owner-only (umask 0002) and '
                  . 'keeps what it stores after; Config::$located tells the default from a given path (' . var_export($files, true) . ')'
            );
         }
         finally {
            @chmod("{$lab}/shared", 0o700);
            $purge($lab);
         }

         // @ The default is the booted storage root, and a Config with no path takes it
         $Config = new Config([]);
         yield assert(
            assertion: Config::locate() === Config::locate(BOOTGLY_STORAGE_DIR) && $Config->path === Config::locate(),
            description: "with no storage root given the booted one is resolved, and a Config with no path takes it ({$Config->path})"
         );
         $Given = new Config(['path' => "{$base}/given/"]);
         yield assert(
            assertion: $Given->path === "{$base}/given",
            description: "a given path is kept as given ({$Given->path})"
         );
      }
      finally {
         @chmod($base, 0o700);
         $clean();
         @rmdir($base);
      }
   }
);
