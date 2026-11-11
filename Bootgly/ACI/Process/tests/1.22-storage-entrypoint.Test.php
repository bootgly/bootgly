<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\commands\KitCommand;


// ! The preparation runs as root only: as root itself, or as the root of a user namespace
$namespace = [];
if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && function_exists('exec')) {
   exec('unshare --user --map-root-user true 2>/dev/null', $lines, $status);
   $namespace = $status === 0 ? ['unshare', '--user', '--map-root-user'] : null;
}

return new Test(
   description: 'Docker entrypoint: a root start prepares a mounted storage directory without following links',
   skip: function_exists('proc_open') === false
      || function_exists('posix_geteuid') === false
      || $namespace === null,
   test: function () use ($namespace) {
      // ! The entrypoint runs with stand-ins first on its PATH: `bootgly` (the
      //   command it ends in, and the boot that judges a directory named by the
      //   environment — recorded, refusing as told), `chown` (records its
      //   arguments, exits as told), `id` (answers for the runtime identity as
      //   told) and `mkdir`, `mv`, `stat` (the real ones, unless told to fail).
      //   The real boot and handover are proven by the container run.
      $entrypoint = BOOTGLY_ROOT_DIR . '@/__docker__/entrypoint.sh';
      $base = (string) realpath(Temporaries::reserve('storage-entrypoint'));
      chmod($base, 0755);
      $bin = "{$base}/bin";
      $calls = "{$base}/calls";
      $layout = ['cache', 'locks', 'logs', 'pids', 'queues', 'schedule', 'sessions', 'temp', 'tests'];
      $root = posix_geteuid() === 0;

      mkdir($bin, 0755);
      $id = trim((string) shell_exec('command -v id'));
      file_put_contents("{$bin}/bootgly", <<<'SH'
      #!/bin/sh
      if [ "$1" = '--version' ]; then
         echo boot >> "$STAND_IN_CALLS.boot"
         [ "$STAND_IN_BOOT" = '0' ] && echo 'Bootgly v0' && exit 0
         echo 'Bootgly: refused by the framework' >&2
         exit 1
      fi
      echo "bootgly $*"
      SH);
      file_put_contents("{$bin}/chown", "#!/bin/sh\necho \"\$*\" >> \"\$STAND_IN_CALLS\"\nexit \"\$STAND_IN_CHOWN\"\n");
      file_put_contents("{$bin}/id", <<<SH
      #!/bin/sh
      if [ "\$1" = '-u' ] && [ "\$2" = 'bootgly' ]; then
         [ "\$STAND_IN_ACCOUNT" = '1' ] && echo 999 && exit 0
         exit 1
      fi
      exec {$id} "\$@"
      SH);
      foreach (['mkdir', 'mv', 'stat'] as $name) {
         $real = trim((string) shell_exec("command -v {$name}"));
         // ! `mv-link`: a link takes the destination's name right before the move
         $plant = $name === 'mv'
            ? "if [ \"\$STAND_IN_FAIL\" = 'mv-link' ]; then for last in \"\$@\"; do :; done; ln -s \"\$STAND_IN_TARGET\" \"\$last\"; fi\n"
            : '';
         file_put_contents("{$bin}/{$name}", "#!/bin/sh\n[ \"\$STAND_IN_FAIL\" = '{$name}' ] && exit 1\n{$plant}exec {$real} \"\$@\"\n");
      }
      foreach (['bootgly', 'chown', 'id', 'mkdir', 'mv', 'stat'] as $name) {
         chmod("{$bin}/{$name}", 0755);
      }

      /**
       * @param array<int,string> $wrapper
       *
       * @return array{0:int,1:string,2:string} Exit code, stdout, stderr.
       */
      $start = function (
         null|string $storage, bool $account = true, int $chown = 0, null|array $wrapper = null,
         int $boot = 0, string $fail = ''
      ) use ($entrypoint, $base, $bin, $calls, $namespace): array {
         $environment = getenv();
         $environment['PATH'] = "{$bin}:" . (getenv('PATH') ?: '/usr/bin:/bin');
         unset($environment['BOOTGLY_STORAGE_DIR']);
         if ($storage !== null) {
            $environment['BOOTGLY_STORAGE_DIR'] = $storage;
         }
         $environment['STAND_IN_BOOT'] = (string) $boot;
         $environment['STAND_IN_CALLS'] = $calls;
         $environment['STAND_IN_CHOWN'] = (string) $chown;
         $environment['STAND_IN_ACCOUNT'] = $account ? '1' : '0';
         $environment['STAND_IN_FAIL'] = $fail;
         $environment['STAND_IN_TARGET'] = "{$base}/planted";
         // ? proc_open() drops a variable whose value is empty: `env` sets it
         $wrapper ??= $namespace;
         if ($storage === '') {
            $wrapper = [...$wrapper, 'env', 'BOOTGLY_STORAGE_DIR='];
         }
         @unlink($calls);
         @unlink("{$calls}.boot");

         $Process = proc_open(
            [...$wrapper, 'sh', $entrypoint, 'status'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $base,
            $environment
         );
         if (is_resource($Process) === false) {
            return [-1, '', ''];
         }
         $output = trim((string) stream_get_contents($pipes[1]));
         $errors = trim((string) stream_get_contents($pipes[2]));
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($Process), $output, $errors];
      };
      $make = function (string $path, int $mode = 0755): string {
         mkdir($path, 0755, true);
         chmod($path, $mode);

         return $path;
      };
      /** @return array<int,string> What the storage root holds, as `<mode> <name>`. */
      $list = function (string $storage): array {
         clearstatcache();
         $entries = [];
         foreach (array_diff(scandir($storage) ?: [], ['.', '..']) as $entry) {
            $state = lstat("{$storage}/{$entry}");
            $entries[] = decoct((int) $state['mode'] & 0177777) . " {$entry}";
         }

         return array_values($entries);
      };
      /** @return array<int,string> The recorded `chown` calls. */
      $read = fn (): array => is_file($calls)
         ? array_values(array_filter(explode("\n", (string) file_get_contents($calls))))
         : [];
      $prepared = array_map(
         fn (string $name): string => ($name === 'sessions' ? '40700' : '40755') . " {$name}",
         $layout
      );

      try {
         // # An empty directory root owns alone
         $empty = $make("{$base}/empty");
         [$code, $output, $errors] = $start($empty);
         yield assert(
            assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
               && $list($empty) === $prepared,
            description: "an empty storage gets the layout, sessions/ as 0700, and the command runs: [{$code}] {$output} {$errors}"
         );
         yield assert(
            assertion: $read() === ["-h bootgly:bootgly {$empty}/.logs.prepare", "-h bootgly:bootgly {$empty}/.sessions.prepare"],
            description: 'only logs/ and sessions/ go to the runtime identity — each by itself, never through a link, before it takes its name: '
               . implode(' | ', $read())
         );

         // @ Idempotent: a second start changes nothing — not even an owner or a mode.
         touch("{$empty}/logs/kept.log");
         chmod("{$empty}/logs", 0750);
         $before = $list($empty);
         [$code, $output, $errors] = $start("{$empty}/");
         yield assert(
            assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
               && $list($empty) === $before && $read() === []
               && is_file("{$empty}/logs/kept.log"),
            description: "a prepared storage is left as found: [{$code}] {$output} {$errors}"
         );

         // @ A missing piece is created; the existing ones keep their owner.
         rmdir("{$empty}/sessions");
         [$code] = $start($empty);
         yield assert(
            assertion: $code === 0 && $list($empty) === $before
               && $read() === ["-h bootgly:bootgly {$empty}/.sessions.prepare"],
            description: 'only the missing directory is created and handed over: ' . implode(' | ', $read())
         );

         // @ What an interrupted start left under the staging name is not in the way.
         $resumed = $make("{$base}/resumed");
         $make("{$resumed}/.sessions.prepare");
         [$code, $output, $errors] = $start("{$resumed}/");
         yield assert(
            assertion: $code === 0 && $errors === '' && $list($resumed) === $prepared,
            description: "a staging directory left behind is replaced and none is left (one trailing separator accepted): [{$code}] {$errors} " . implode(' | ', $list($resumed))
         );

         // @ An image without the kit's runtime identity has no such layout: nothing is
         //   prepared — the server creates what it needs for the identity it demotes to.
         $plain = $make("{$base}/plain");
         [$code, $output, $errors] = $start($plain, account: false);
         yield assert(
            assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
               && $list($plain) === [] && $read() === [],
            description: "without the runtime identity nothing is created and nothing changes owner: [{$code}] {$errors} " . implode(' | ', $list($plain))
         );

         // # The framework judges a directory named by the environment first
         yield assert(
            assertion: is_file("{$calls}.boot")
               && file_get_contents("{$calls}.boot") === "boot\n",
            description: 'the framework boots once before the storage is prepared'
         );

         // @ It refuses: its message is the only one, nothing is created, the command never runs.
         $refused = $make("{$base}/refused");
         [$code, $output, $errors] = $start($refused, boot: 1);
         yield assert(
            assertion: $code === 1 && $output === '' && $errors === 'Bootgly: refused by the framework'
               && $list($refused) === [] && $read() === [],
            description: "a directory the framework refuses is not prepared: [{$code}] {$output} {$errors}"
         );

         // @ Unset: nothing to judge — the default directory is the image's.
         if (file_exists('/bootgly/storage') === false) {
            [$code, $output, $errors] = $start(null, boot: 1);
            yield assert(
               assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
                  && is_file("{$calls}.boot") === false,
               description: "without the variable the framework is not asked: [{$code}] {$output} {$errors}"
            );
         }

         // @ Empty: the framework is asked — it refuses unless a launcher pre-defined
         //   the constant — and what is left to prepare is the image's default directory.
         if (file_exists('/bootgly/storage') === false) {
            [$code, $output, $errors] = $start('');
            yield assert(
               assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
                  && file_get_contents("{$calls}.boot") === "boot\n" && $read() === [],
               description: "an empty value is the framework's to judge: [{$code}] {$output} {$errors}"
            );
         }

         // # The layout is the kit's: what `kit boot` lays down, plus sessions/
         $source = (string) file_get_contents($entrypoint);
         $kit = array_map(
            fn (string $name): string => rtrim($name, '/'),
            (array) new ReflectionClassConstant(KitCommand::class, 'STORAGE')->getValue()
         );
         sort($kit);
         yield assert(
            assertion: preg_match("/^   layout='([a-z ]+)'\$/m", $source, $matches) === 1
               && explode(' ', $matches[1]) === $layout
               && array_values(array_diff($layout, ['sessions'])) === $kit,
            description: 'the entrypoint prepares the storage layout `kit boot` lays down, plus sessions/'
         );

         // # Loud failures — the command never runs
         // @ The storage root cannot be inspected.
         $opaque = $make("{$base}/opaque");
         [$code, $output, $errors] = $start($opaque, fail: 'stat');
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: cannot be inspected: {$opaque}"
               && $list($opaque) === [],
            description: "a storage root that cannot be inspected stops the start: [{$code}] {$output} {$errors}"
         );

         // @ A directory cannot be created.
         [$code, $output, $errors] = $start($opaque, fail: 'mkdir');
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: cannot create: {$opaque}/cache"
               && $list($opaque) === [],
            description: "a directory that cannot be created stops the start: [{$code}] {$output} {$errors}"
         );

         // @ A handed-over directory cannot take its name: its staging is removed.
         [$code, $output, $errors] = $start($opaque, fail: 'mv');
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: cannot create: {$opaque}/logs"
               && $list($opaque) === ['40755 cache', '40755 locks'],
            description: "a directory that cannot take its name stops the start and leaves no staging: [{$code}] {$output} {$errors}"
         );

         // @ The handover fails: nothing half-made is left behind.
         $failing = $make("{$base}/failing");
         [$code, $output, $errors] = $start($failing, chown: 1);
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: cannot give to bootgly: {$failing}/logs"
               && $list($failing) === ['40755 cache', '40755 locks'],
            description: "a failed handover stops the start and removes the directory it made: [{$code}] {$output} {$errors}"
         );

         // @ The staging name is taken — by a link, a file or a directory that is not
         //   empty: nothing is removed, nothing is written through it.
         $planted = $make("{$base}/planted");
         foreach (['link', 'file', 'directory'] as $kind) {
            $taken = $make("{$base}/taken-{$kind}");
            match ($kind) {
               'link' => symlink($planted, "{$taken}/.logs.prepare"),
               'file' => touch("{$taken}/.logs.prepare"),
               'directory' => $make("{$taken}/.logs.prepare/kept")
            };
            $before = $list($taken);
            [$code, $output, $errors] = $start($taken);
            yield assert(
               assertion: $code === 1 && $output === ''
                  && str_ends_with($errors, "bootgly: storage is not prepared: cannot create: {$taken}/logs")
                  && $list($taken) === [...$before, '40755 cache', '40755 locks']
                  && $list($planted) === [] && $read() === []
                  && ($kind !== 'directory' || is_dir("{$taken}/.logs.prepare/kept")),
               description: "a staging name taken by a {$kind} stops the start and is left as found: [{$code}] {$output} {$errors} " . implode(' | ', $list($taken))
            );
         }

         // @ A link takes the name of logs/ after the check: the handed-over directory
         //   never goes through it.
         $raced = $make("{$base}/raced");
         [$code, $output, $errors] = $start($raced, fail: 'mv-link');
         yield assert(
            assertion: $code === 1 && $output === ''
               && str_ends_with($errors, "bootgly: storage is not prepared: cannot create: {$raced}/logs")
               && $list($raced) === ['40755 cache', '40755 locks', '120777 logs']
               && $list($planted) === [],
            description: "a link that appears at logs/ is never moved through: [{$code}] {$output} {$errors} " . implode(' | ', $list($planted))
         );

         // @ A link where a directory is expected: refused before anything is created.
         $linked = $make("{$base}/linked");
         $target = $make("{$base}/target");
         symlink($target, "{$linked}/sessions");
         [$code, $output, $errors] = $start($linked);
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: symbolic link: {$linked}/sessions"
               && $list($linked) === ['120777 sessions'] && $list($target) === [] && $read() === [],
            description: "a linked sessions/ is refused and nothing is created or handed over: [{$code}] {$output} {$errors}"
         );

         // @ A file where a directory is expected.
         $filed = $make("{$base}/filed");
         touch("{$filed}/pids");
         chmod("{$filed}/pids", 0644);
         [$code, $output, $errors] = $start($filed);
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: not a directory: {$filed}/pids"
               && $list($filed) === ['100644 pids'],
            description: "a pids that is a file is refused and nothing is created: [{$code}] {$output} {$errors}"
         );

         // @ A linked storage root.
         symlink($target, "{$base}/rootlink");
         [$code, $output, $errors] = $start("{$base}/rootlink");
         yield assert(
            assertion: $code === 1 && $output === ''
               && $errors === "bootgly: storage is not prepared: symbolic link: {$base}/rootlink"
               && $list($target) === [],
            description: "a linked storage root is refused and its target is untouched: [{$code}] {$output} {$errors}"
         );

         // # Left to the framework — nothing is created, the command runs
         $group = $make("{$base}/group", 0775);
         $world = $make("{$base}/world", 0757);
         $untouched = [
            'a group-writable storage root' => [$group, $group],
            'a world-writable storage root' => [$world, $world],
            'a path with a `..` segment' => ["{$group}/../target", $target],
            'a path with a `.` segment' => ["{$base}/./target", $target],
            'a path with a doubled separator' => ["{$base}//target", $target],
            'two trailing separators' => ["{$target}//", $target],
            // ! The start's working directory is the fixture: `target` exists there
            'a relative path' => ['target', $target],
         ];
         foreach ($untouched as $case => [$value, $directory]) {
            [$code, $output, $errors] = $start($value);
            yield assert(
               assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
                  && $list($directory) === [] && $read() === [],
               description: "{$case} is not prepared: [{$code}] {$output} {$errors}"
            );
         }

         // @ A missing storage root is not created.
         [$code, $output, $errors] = $start("{$base}/missing");
         yield assert(
            assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
               && file_exists("{$base}/missing") === false,
            description: "a missing storage root is not created: [{$code}] {$output} {$errors}"
         );

         // @ A storage root that is not root's.
         if ($root) {
            $foreign = $make("{$base}/foreign");
            chown($foreign, 65534);
            [$code, $output, $errors] = $start($foreign);
            yield assert(
               assertion: $code === 0 && $errors === '' && $output === 'bootgly status'
                  && $list($foreign) === [] && $read() === [],
               description: "a storage root another user owns is not prepared: [{$code}] {$output} {$errors}"
            );
         }
         else {
            // ! What root owns outside a user namespace belongs to an unmapped owner inside
            //   it, and nothing can be created there: a preparation would fail loud
            $system = @lstat('/usr');
            $probe = is_array($system) && $system['uid'] === 0
               && ((int) $system['mode'] & 0170022) === 0040000
               && array_intersect($layout, scandir('/usr') ?: $layout) === [];
            if ($probe) {
               [$code, $output, $errors] = $start('/usr');
               yield assert(
                  assertion: $code === 0 && $errors === '' && $output === 'bootgly status' && $read() === [],
                  description: "a storage root another user owns is not prepared (user namespace): [{$code}] {$output} {$errors}"
               );

               // @ Not root (`--user`): skipped, even over a directory root owns alone.
               [$code, $output, $errors] = $start('/usr', wrapper: []);
               yield assert(
                  assertion: $code === 0 && $errors === '' && $output === 'bootgly status' && $read() === [],
                  description: "a start that is not root prepares nothing: [{$code}] {$output} {$errors}"
               );
            }
         }
      }
      finally {
         // @ Remove the fixture tree — links are removed, never followed
         $remove = function (string $path) use (&$remove): void {
            if (is_link($path) || is_dir($path) === false) {
               @unlink($path);

               return;
            }
            @chmod($path, 0700);
            foreach (scandir($path) ?: [] as $entry) {
               if ($entry !== '.' && $entry !== '..') {
                  $remove("{$path}/{$entry}");
               }
            }
            @rmdir($path);
         };
         $remove($base);
      }
   }
);
