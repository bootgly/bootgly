<?php

use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * SRV-4: a compiled template is included only when this process can trust it.
 *
 * The compiled cache lives under this process's own cache directory
 * (`Config::locate()`), is written owner-only whatever the umask, and a
 * compiled file someone else could have written — group/world-writable, or a
 * link — is compiled again instead of being included; a templates/ directory
 * others could write is refused outright.
 */
return new Test(
   description: 'SRV-4: the compiled template cache is per-identity, owner-only and never trusted when others could write it',
   test: function () {
      $Template = new Template('Hello @> $name; ' . uniqid('trust-', true));
      $Template->render(['name' => 'world']);
      /** @var string $cache */
      $cache = (fn (): string => $this->cache)->call($Template);

      try {
         yield assert(
            assertion: dirname($cache) === Config::locate() . '/templates',
            description: 'the compiled cache lives under this process\'s own cache directory (' . dirname($cache) . ')'
         );

         $mode = static fn (): int => is_array($state = @lstat($cache)) ? (int) $state['mode'] & 0o777 : -1;
         yield assert(
            assertion: $mode() === 0o600,
            description: 'the compiled file is written owner-only (' . decoct($mode()) . ')'
         );

         // @ Group/world-writable with planted code: compiled again, never included
         file_put_contents($cache, '<?php echo "PLANTED"; ?>');
         chmod($cache, 0o666);
         $Again = new Template((fn (): mixed => $this->raw)->call($Template));
         $Again->render(['name' => 'world']);
         yield assert(
            assertion: str_starts_with($Again->output, 'Hello world')
               && str_contains($Again->output, 'PLANTED') === false
               && $mode() === 0o600,
            description: 'a group/world-writable compiled file is compiled again, its planted code never runs (output='
               . var_export($Again->output, true) . ', mode=' . decoct($mode()) . ')'
         );

         // @ A link at the compiled path: compiled again over it, never followed
         $target = "{$cache}.target";
         file_put_contents($target, '<?php echo "PLANTED"; ?>');
         chmod($target, 0o600);
         unlink($cache);
         symlink($target, $cache);
         $Linked = new Template((fn (): mixed => $this->raw)->call($Template));
         $Linked->render(['name' => 'world']);
         yield assert(
            assertion: str_starts_with($Linked->output, 'Hello world')
               && str_contains($Linked->output, 'PLANTED') === false
               && is_link($cache) === false
               && (string) file_get_contents($target) === '<?php echo "PLANTED"; ?>',
            description: 'a link at the compiled path is replaced, its target untouched and never included (output='
               . var_export($Linked->output, true) . ')'
         );
         @unlink($target);

         // @ A templates/ directory others may write — on a scratch cache root,
         //   never the checkout's own: refused, cold and warm alike
         $EUID = posix_geteuid();
         $scratch = (string) realpath(Temporaries::reserve('template-trust'));
         chmod($scratch, 0o700);
         $seed = static fn (null|array $entry) => (static function () use ($EUID, $entry): void {
            if ($entry === null) {
               unset(self::$directories[$EUID]);
               return;
            }
            self::$directories[$EUID] = $entry;
         })->bindTo(null, Config::class)();
         $forget = static fn () => (static function (): void {
            self::$directories = [];
         })->bindTo(null, Template::class)();
         $refused = static function (string $raw): string {
            try {
               new Template($raw)->render(['name' => 'world']);
            }
            catch (Throwable $Throwable) {
               return $Throwable->getMessage();
            }

            return '';
         };
         // ! The checkout's own location, where a relocated cache lands
         $real = Config::locate();
         $proven = static fn (string $directory): bool => (static fn (): bool => isSet(self::$directories["{$EUID}:{$directory}"]))
            ->bindTo(null, Template::class)();
         $seed([$scratch, null, null]);
         $forget();
         $directory = "{$scratch}/templates";
         $Relocated = [];
         try {
            // @ Removed while serving (a cache cleared by hand): proven again and
            //   recreated owner-only, never recreated blindly
            new Template('Hello @> $name; ' . uniqid('first-', true))->render(['name' => 'world']);
            $once = $proven($directory);
            $purge = static function (string $path) use (&$purge): void {
               foreach (array_diff((array) @scandir($path), ['.', '..']) as $entry) {
                  is_dir("{$path}/{$entry}") && is_link("{$path}/{$entry}") === false ? $purge("{$path}/{$entry}") : @unlink("{$path}/{$entry}");
               }
               @rmdir($path);
            };
            $purge($directory);
            $Rebuilt = new Template('Hello @> $name; ' . uniqid('rebuilt-', true));
            $Rebuilt->render(['name' => 'world']);
            $rebuilt = (fn (): string => $this->cache)->call($Rebuilt);
            $recreated = str_starts_with($Rebuilt->output, 'Hello world')
               && dirname($rebuilt) === $directory
               && (fileperms($directory) & 0o777) === 0o700;

            // @ Opened to its group/others: the next process that proves it refuses
            //   it, a new template and one already compiled there alike
            $warm = 'Hello @> $name; ' . uniqid('warm-', true);
            new Template($warm)->render(['name' => 'world']);
            chmod($directory, 0o777);
            $forget();
            $cold = $refused('Hello @> $name; ' . uniqid('cold-', true));
            $forget();
            $hot = $refused($warm);
            chmod($directory, 0o700);

            // @ Its whole tree removed while serving, and not to be made again
            //   where it was: located anew, once, and compiled there
            mkdir("{$scratch}/gone/cache", 0o700, true);
            $seed(["{$scratch}/gone/cache", null, null]);
            $forget();
            new Template('Hello @> $name; ' . uniqid('gone-', true))->render(['name' => 'world']);
            $purge("{$scratch}/gone/cache");
            chmod("{$scratch}/gone", 0o500);
            $Moved = new Template('Hello @> $name; ' . uniqid('moved-', true));
            $Moved->render(['name' => 'world']);
            $Relocated[] = $moved = (fn (): string => $this->cache)->call($Moved);
            $gone = str_starts_with($Moved->output, 'Hello world') && dirname($moved) === "{$real}/templates"
               && Config::locate() === $real;
            chmod("{$scratch}/gone", 0o700);

            // @ The tree opened to others above a templates/ that went away: never
            //   made again in it — located anew, once
            mkdir("{$scratch}/opened/cache", 0o700, true);
            $seed(["{$scratch}/opened/cache", null, null]);
            $forget();
            new Template('Hello @> $name; ' . uniqid('opened-', true))->render(['name' => 'world']);
            chmod("{$scratch}/opened", 0o777);
            $purge("{$scratch}/opened/cache/templates");
            $Away = new Template('Hello @> $name; ' . uniqid('away-', true));
            $Away->render(['name' => 'world']);
            $Relocated[] = $away = (fn (): string => $this->cache)->call($Away);
            $opened = str_starts_with($Away->output, 'Hello world') && dirname($away) === "{$real}/templates"
               && is_dir("{$scratch}/opened/cache/templates") === false;
            chmod("{$scratch}/opened", 0o700);

            // @ Its whole tree removed while serving, where it can be made again:
            //   made again in place, owner-only — not moved
            mkdir("{$scratch}/whole/cache", 0o700, true);
            $seed(["{$scratch}/whole/cache", null, null]);
            $forget();
            new Template('Hello @> $name; ' . uniqid('whole-', true))->render(['name' => 'world']);
            $purge("{$scratch}/whole/cache");
            $Again = new Template('Hello @> $name; ' . uniqid('again-', true));
            $Again->render(['name' => 'world']);
            $again = (fn (): string => $this->cache)->call($Again);
            $whole = str_starts_with($Again->output, 'Hello world')
               && dirname($again) === "{$scratch}/whole/cache/templates"
               && (fileperms("{$scratch}/whole/cache") & 0o777) === 0o700
               && Config::locate() === "{$scratch}/whole/cache";

            // @ A tree of its own it cannot write: said so — no cache is made
            mkdir("{$scratch}/readonly", 0o500);
            $seed(["{$scratch}/readonly", null, null]);
            $forget();
            $unwritable = $refused('Hello @> $name; ' . uniqid('readonly-', true));
            chmod("{$scratch}/readonly", 0o700);
         }
         finally {
            $seed(null);
            $forget();
            @chmod($directory, 0o700);
            foreach (['gone', 'opened', 'readonly'] as $tree) {
               @chmod("{$scratch}/{$tree}", 0o700);
            }
            foreach ($Relocated as $compiled) {
               @unlink($compiled);
            }
            $purge ??= null;
            if ($purge !== null) {
               $purge($scratch);
            }
         }
         yield assert(
            assertion: $once && $recreated,
            description: "a templates/ directory is proven once per process, and one removed while serving is proven again and "
               . "recreated owner-only ({$directory})"
         );
         yield assert(
            assertion: $whole,
            description: "a cache tree removed while serving is made again in place, owner-only, when it can be ({$scratch}/whole/cache)"
         );
         yield assert(
            assertion: $gone && $opened,
            description: 'a cache tree removed while serving that cannot be made again, or opened to others above a templates/ '
               . 'that went away, is located anew and compiled there — nothing is made in it (' . implode(', ', $Relocated) . ')'
         );
         yield assert(
            assertion: $unwritable === "Unwritable template cache directory `{$scratch}/readonly/templates`.",
            description: 'a tree of its own it cannot write is reported as unwritable (' . var_export($unwritable, true) . ')'
         );
         yield assert(
            assertion: str_contains($cold, "Untrusted template cache directory `{$directory}`")
               && str_contains($hot, "Untrusted template cache directory `{$directory}`"),
            description: 'a templates/ directory its group or others can write is never used — a new template and one already '
               . 'compiled there are both refused, naming it (cold=' . var_export($cold, true) . ', warm=' . var_export($hot, true) . ')'
         );
      }
      finally {
         @unlink($cache);
         @unlink("{$cache}.target");
      }
   }
);
