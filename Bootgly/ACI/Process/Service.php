<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Process;


use const PHP_EOL;
use function array_map;
use function bin2hex;
use function chmod;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_executable;
use function is_file;
use function is_link;
use function is_resource;
use function preg_match;
use function preg_replace_callback;
use function proc_close;
use function proc_open;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strtoupper;
use function trim;
use function unlink;
use InvalidArgumentException;


/**
 * One OS service — a systemd unit that keeps a command running as a given
 * user from a working directory, restarted on failure — and the `systemctl`
 * verbs that manage it. The unit is rendered from what the caller knows and
 * stamped with the project and kit it belongs to; nothing here decides what
 * the service runs, and a unit another project or kit stamped is never
 * written over or removed.
 */
class Service
{
   // * Config
   /** Where systemd reads the units this machine's administrator installs. */
   public static string $directory = '/etc/systemd/system/';
   /** Trusted absolute binaries, never resolved through PATH. */
   private const array SYSTEMCTL = ['/usr/bin/systemctl', '/bin/systemctl'];

   /** Unit name, without the `.service` suffix. */
   public private(set) string $name;
   /** Canonical project path the unit belongs to — stamped in the unit, checked before every write. */
   public private(set) string $project;
   /** Kit directory the unit belongs to — stamped beside the project. */
   public private(set) string $kit;
   public private(set) string $description;
   /** @var array<string> The command line, one argument per entry. */
   public private(set) array $command;
   public private(set) string $user;
   /** @var array<string> Units this one starts after, when they exist. */
   public private(set) array $after;
   /** The `ExecReload=` line, rendered verbatim — `''` for a service that cannot reload. */
   public private(set) string $reload;

   // * Metadata
   public string $unit {
      get => "{$this->name}.service";
   }
   public string $file {
      get => rtrim(self::$directory, '/') . "/{$this->unit}";
   }
   /**
    * Whether anything sits at the unit's path — a file, or a link: a masked
    * unit is a link to /dev/null, which is nothing systemd will ever start
    * but everything a write through the link would damage.
    */
   public bool $installed {
      get => is_link($this->file) === true || is_file($this->file);
   }
   /**
    * The project and kit an installed unit declares, as `[project, kit]` —
    * `['', '']` when nothing is installed, when the unit is a link, or when
    * it carries no stamp.
    *
    * @var array{string, string}
    */
   public array $owner {
      get {
         if ($this->installed === false || is_link($this->file) === true) {
            return ['', ''];
         }

         $content = (string) @file_get_contents($this->file);
         $project = preg_match('/^X-Bootgly-Project=(.*)$/m', $content, $matches) === 1 ? trim($matches[1]) : '';
         $kit = preg_match('/^X-Bootgly-Kit=(.*)$/m', $content, $matches) === 1 ? trim($matches[1]) : '';

         return [$project, $kit];
      }
   }
   /**
    * Whether the unit is this project's to write or remove: absent, or a
    * regular file stamped with this project and kit — a link is nobody's, and
    * an empty project can own nothing.
    */
   public bool $owned {
      get => $this->installed === false
         || (
            is_link($this->file) === false
            && self::escape($this->project) !== ''
            && $this->owner === [self::escape($this->project), self::escape($this->kit)]
         );
   }


   /**
    * @param string $name Unit name, without the `.service` suffix.
    * @param string $project Canonical project path the unit belongs to.
    * @param string $kit Kit directory the command runs from — the working directory.
    * @param string $description One line for `systemctl status`.
    * @param array<string> $command The command line, one argument per entry — absolute binary first.
    * @param string $user Account the command runs as.
    * @param array<string> $after Units this one starts after, when they exist.
    * @param string $reload The `ExecReload=` line, verbatim — `''` when the service cannot reload.
    */
   public function __construct (
      string $name,
      string $project,
      string $kit,
      string $description,
      array $command,
      string $user,
      array $after = [],
      string $reload = ''
   )
   {
      $this->name = $name;
      $this->project = $project;
      $this->kit = $kit;
      $this->description = $description;
      $this->command = $command;
      $this->user = $user;
      $this->after = $after;
      $this->reload = $reload;
   }

   /**
    * Derive a unit name from a project path, one name per path: `/` becomes
    * `-`, letters, digits and `_` stay, and any other byte — a `-` the path
    * carries included — becomes `:` plus its two hex digits. A `-` in the
    * name therefore always means a `/`, a `.` can never come from the path,
    * and the suffix a sibling unit takes after a `.` is its own.
    * `Demo/HTTP_Server_CLI` becomes `bootgly-Demo-HTTP_Server_CLI`, its
    * schedule worker `bootgly-Demo-HTTP_Server_CLI.schedule`, `App-API`
    * becomes `bootgly-App:2DAPI`. systemd loads and runs a `:`-bearing name;
    * `systemd-analyze verify` refuses to look one up, so paths minted by the
    * kit (letters, digits, `_`, `/`) keep their names free of it.
    */
   public static function identify (string $path, string $suffix = ''): string
   {
      $slug = (string) preg_replace_callback(
         '/[^A-Za-z0-9_\/]/',
         static fn (array $match): string => ':' . strtoupper(bin2hex($match[0])),
         $path
      );
      $slug = str_replace('/', '-', $slug);

      // :
      return $suffix !== '' ? "bootgly-{$slug}.{$suffix}" : "bootgly-{$slug}";
   }

   /**
    * Render the unit.
    */
   public function render (): string
   {
      // ? systemd strips its prefix characters (`@ - : + !`) from the first
      //   word AFTER unquoting, so no quoting protects it: the executable must
      //   be an absolute path, which no prefix character can begin
      if (str_starts_with($this->command[0] ?? '', '/') === false) {
         throw new InvalidArgumentException('The service command must begin with an absolute path.');
      }

      $after = implode(' ', ['network-online.target', ...$this->after]);
      $exec = implode(' ', array_map(self::quote(...), $this->command));

      $lines = [
         '[Unit]',
         'Description=' . self::escape($this->description),
         // ! The stamp `uninstall`/`inspect` read back — systemd ignores `X-` keys
         'X-Bootgly-Project=' . self::escape($this->project),
         'X-Bootgly-Kit=' . self::escape($this->kit),
         "After={$after}",
         'Wants=network-online.target',
         // ! A service that keeps dying stops being retried after ten starts in
         //   five minutes — `systemctl reset-failed` arms it again (systemd ≥ 230
         //   reads these in [Unit]; older managers ignore them and keep 5 in 10 s)
         'StartLimitIntervalSec=300',
         'StartLimitBurst=10',
         '',
         '[Service]',
         'Type=simple',
         'User=' . self::escape($this->user),
         // ! A path setting takes the rest of its line verbatim: never quoted
         'WorkingDirectory=' . self::escape($this->kit),
         "ExecStart={$exec}",
      ];
      if ($this->reload !== '') {
         $lines[] = "ExecReload={$this->reload}";
      }

      // :
      return implode(PHP_EOL, [
         ...$lines,
         'Restart=on-failure',
         // ! Spaced past systemd's default start-rate window (5 in 10 s), so a
         //   port taken for a moment never wedges the unit in `failed`
         'RestartSec=5',
         'KillMode=mixed',
         'TimeoutStopSec=30',
         '',
         '[Install]',
         'WantedBy=multi-user.target',
      ]) . PHP_EOL;
   }

   /**
    * Write the unit where systemd reads it — root only. A unit stamped by
    * another project or kit is left alone.
    */
   public function install (): bool
   {
      // ? Never through a link — a masked unit is one, and so is a trap
      if (is_link($this->file) === true || $this->owned === false) {
         return false;
      }
      // ?
      if (@file_put_contents($this->file, $this->render()) === false) {
         return false;
      }
      @chmod($this->file, 0644);

      // :
      return true;
   }

   /**
    * Remove the unit file — root only; a unit never installed counts as
    * removed, a unit stamped by another project or kit is left alone.
    */
   public function uninstall (): bool
   {
      // ?:
      if ($this->installed === false) {
         return true;
      }
      // ?
      if ($this->owned === false) {
         return false;
      }

      // :
      return @unlink($this->file);
   }

   /**
    * Tell systemd the unit files changed.
    */
   public static function reload (): bool
   {
      // :
      return self::run(['daemon-reload'])[0] === 0;
   }

   /**
    * Enable the unit at boot — and start it right away with `$now`.
    */
   public function enable (bool $now = false): bool
   {
      // :
      return self::run($now ? ['enable', '--now', $this->unit] : ['enable', $this->unit])[0] === 0;
   }

   /**
    * Restart the unit — the only way a rewritten unit reaches a running service.
    */
   public function restart (): bool
   {
      // :
      return self::run(['restart', $this->unit])[0] === 0;
   }

   /**
    * Disable the unit at boot — and stop it right away with `$now`.
    */
   public function disable (bool $now = false): bool
   {
      // :
      return self::run($now ? ['disable', '--now', $this->unit] : ['disable', $this->unit])[0] === 0;
   }

   /**
    * Inspect the unit as systemd sees it: whether it is enabled at boot and
    * whether it is active now — `unknown` when systemctl cannot answer.
    *
    * @return array{enabled: string, active: string}
    */
   public function inspect (): array
   {
      $enabled = trim(self::run(['is-enabled', $this->unit])[1]);
      $active = trim(self::run(['is-active', $this->unit])[1]);

      // :
      return [
         'enabled' => preg_match('/^[a-z-]+$/', $enabled) === 1 ? $enabled : 'unknown',
         'active' => preg_match('/^[a-z-]+$/', $active) === 1 ? $active : 'unknown',
      ];
   }

   // ---

   /**
    * Escape one unit-file value that systemd reads verbatim: `%` doubles so
    * no specifier expands inside it, a line break can never open a directive
    * of its own, and a trailing `\` can never join the next line to this one.
    */
   private static function escape (string $value): string
   {
      // :
      return rtrim(trim(str_replace(['%', "\r", "\n"], ['%%', ' ', ' '], $value)), '\\');
   }

   /**
    * Quote one command-line word the way systemd splits `ExecStart=`: bare
    * when it carries nothing special, double-quoted with `"` and `\` escaped
    * otherwise — `%` and `$` doubled either way, since specifiers and
    * variables expand after the quotes are gone.
    */
   private static function quote (string $word): string
   {
      $word = str_replace('$', '$$', self::escape($word));

      // ?:
      if ($word !== '' && preg_match('/^[A-Za-z0-9_.\/:@+=,%$-]+$/', $word) === 1) {
         return $word;
      }

      // :
      return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $word) . '"';
   }

   /**
    * Run one `systemctl` verb through a trusted absolute binary, without a shell.
    *
    * @param list<string> $arguments
    *
    * @return array{int, string} Exit status and combined output.
    */
   private static function run (array $arguments): array
   {
      // ! Binary
      $binary = null;
      foreach (self::SYSTEMCTL as $candidate) {
         if (is_executable($candidate) === true) {
            $binary = $candidate;
            break;
         }
      }
      // ?
      if ($binary === null) {
         return [127, 'systemctl was not found'];
      }

      // @ One pipe for both streams — nothing to drain out of order
      $pipes = [];
      $process = @proc_open(
         [$binary, ...$arguments],
         // @phpstan-ignore-next-line — a `redirect` spec takes the target descriptor as an int
         [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
         ],
         $pipes,
         '/',
         ['LC_ALL' => 'C', 'PATH' => '/usr/bin:/bin']
      );
      // ?
      if (is_resource($process) === false) {
         return [126, 'systemctl could not be started'];
      }

      $output = (string) stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      $status = proc_close($process);

      // :
      return [$status, $output];
   }
}
