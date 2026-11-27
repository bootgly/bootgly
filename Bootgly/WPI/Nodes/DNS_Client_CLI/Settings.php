<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI;


use const PREG_SPLIT_NO_EMPTY;
use function array_search;
use function array_slice;
use function clearstatcache;
use function count;
use function explode;
use function file_get_contents;
use function filesize;
use function getenv;
use function gethostname;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rtrim;
use function stat;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function trim;
use Closure;


/**
 * The resolver settings in effect: `resolv.conf`, `LOCALDOMAIN`,
 * `RES_OPTIONS` and the `hosts:` line of `nsswitch.conf`, overridden by the
 * node's `Configs`.
 *
 * Honoured from `resolv.conf`: `nameserver` (IP literals, at most 3),
 * `search` / `domain` (the last one wins), `options ndots: timeout: attempts:
 * rotate edns0`. Ignored: `sortlist`, `options use-vc no-aaaa single-request`
 * and the rest. Without a `nameserver` line the nameserver is `127.0.0.1`;
 * without `search`/`domain` the search list is the host name's domain.
 *
 * Re-checked at most once per second; re-parsed when a file's identity
 * (device, inode, size, mtime) changed.
 */
final class Settings
{
   // * Config
   /** The node's configuration. */
   public private(set) Configs $Configs;

   // * Data
   // ...

   // * Metadata
   /** @var list<string> Nameserver IP literals, in order. */
   public private(set) array $nameservers = ['127.0.0.1'];
   /** @var list<string> Search domains, in order. */
   public private(set) array $search = [];
   /** Dots a name needs to be tried as-is first. */
   public private(set) int $ndots = 1;
   /** Seconds each try waits. */
   public private(set) float $timeout = 5.0;
   /** Rounds over the nameservers. */
   public private(set) int $attempts = 2;
   /** Whether each query starts at the next nameserver. */
   public private(set) bool $rotate = false;
   /** Whether queries advertise EDNS(0). */
   public private(set) bool $EDNS = false;
   /** @var list<string> The `hosts:` sources of `nsswitch.conf`, in order. */
   public private(set) array $sources = ['files', 'dns'];
   /** The machine's host name, lowercased (no trailing dot). */
   public private(set) string $hostname = '';
   /** Whether the hosts file is a source. */
   public bool $files {
      get => in_array('files', $this->sources, true);
   }
   /** Whether an mDNS-capable source (`mdns*`, `resolve`) comes before `dns`. */
   public bool $mDNS {
      get => $this->precede(static fn (string $source): bool => str_starts_with($source, 'mdns') || $source === 'resolve');
   }
   /** Whether a source that answers the machine's own name (`myhostname`, `resolve`) comes before `dns`. */
   public bool $myhostname {
      get => $this->precede(static fn (string $source): bool => $source === 'myhostname' || $source === 'resolve');
   }
   /** @var array<string,list<int>> File identities last parsed, by path. */
   private array $stamps = [];
   private float $checked = -1.0;
   private bool $racy = false;


   public function __construct (Configs $Configs)
   {
      // * Config
      $this->Configs = $Configs;
   }

   /**
    * Re-read the system files when they changed (checked at most once per second).
    *
    * @param float $now The current time on a monotonic clock (`Query::clock()`): it paces the checks.
    */
   public function reload (float $now): void
   {
      // ? Checked within the last second (a file modified within the second it was read is
      //   re-checked: a same-second, same-size edit would otherwise be missed)
      if ($now - $this->checked < 1.0 && $this->racy === false) {
         return;
      }
      $this->checked = $now;

      $Configs = $this->Configs;
      $stamps = [];
      // ! Modified within the last second of the wall clock (the one file times use): a same-size
      //   edit in that tick could follow; a future mtime is not racy — it would re-read every lookup
      $wall = time();
      $racy = false;
      foreach ([$Configs->resolv, $Configs->nsswitch] as $path) {
         if ($path === null) {
            continue;
         }
         clearstatcache(true, $path);
         $Stat = @stat($path);
         $stamps[$path] = is_array($Stat) ? [$Stat['dev'], $Stat['ino'], $Stat['size'], $Stat['mtime']] : [];
         $racy = $racy || (is_array($Stat) && $Stat['mtime'] >= $wall - 1 && $Stat['mtime'] <= $wall);
      }
      // ? Unchanged since the last parse, and not modified within the second it was read
      if ($stamps === $this->stamps && $this->stamps !== [] && $this->racy === false) {
         return;
      }
      $this->stamps = $stamps;
      $this->racy = $racy;

      $this->parse();
   }

   /**
    * Parse every source of the settings.
    */
   private function parse (): void
   {
      $Configs = $this->Configs;

      // ! Defaults (glibc)
      $nameservers = [];
      $search = null;
      $options = [];

      // # resolv.conf
      $contents = self::read($Configs->resolv);
      foreach (explode("\n", $contents) as $line) {
         $line = trim(preg_replace('/[#;].*$/', '', $line) ?? '');
         $fields = preg_split('/[ \t\r]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
         if ($fields === false || count($fields) < 2) {
            continue;
         }

         switch ($fields[0]) {
            case 'nameserver':
               if (count($nameservers) < 3 && Configs::check($fields[1])) {
                  $nameservers[] = $fields[1];
               }
               break;
            case 'domain':
               $search = [$fields[1]];
               break;
            case 'search':
               $search = array_slice($fields, 1);
               break;
            case 'options':
               foreach (array_slice($fields, 1) as $option) {
                  $options[] = $option;
               }
               break;
         }
      }

      // # Environment
      $environment = $Configs->environment;
      $local = $environment === null ? getenv('LOCALDOMAIN') : ($environment['LOCALDOMAIN'] ?? false);
      if (is_string($local) && trim($local) !== '') {
         $search = preg_split('/[ \t]+/', trim($local), -1, PREG_SPLIT_NO_EMPTY) ?: [];
      }
      $extra = $environment === null ? getenv('RES_OPTIONS') : ($environment['RES_OPTIONS'] ?? false);
      if (is_string($extra)) {
         foreach (preg_split('/[ \t]+/', trim($extra), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $option) {
            $options[] = $option;
         }
      }

      // # Host name
      $hostname = $Configs->hostname ?? (string) gethostname();
      $this->hostname = strtolower(rtrim($hostname, '.'));

      // ! Without search/domain: the host name's domain
      if ($search === null) {
         $dot = strpos($this->hostname, '.');
         $search = $dot === false ? [] : [substr($this->hostname, $dot + 1)];
      }

      // # Options
      $ndots = 1;
      $timeout = 5.0;
      $attempts = 2;
      $rotate = false;
      $EDNS = false;
      foreach ($options as $option) {
         if (preg_match('/^(ndots|timeout|attempts):(\d{1,4})$/D', $option, $match) === 1) {
            $value = (int) $match[2];
            match ($match[1]) {
               'ndots' => $ndots = min($value, 15),
               'timeout' => $timeout = (float) max(1, min($value, 30)),
               default => $attempts = max(1, min($value, 5)),
            };
         }
         else if ($option === 'rotate') {
            $rotate = true;
         }
         else if ($option === 'edns0') {
            $EDNS = true;
         }
      }

      // # nsswitch.conf `hosts:`
      $sources = ['files', 'dns'];
      foreach (explode("\n", self::read($Configs->nsswitch)) as $line) {
         $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
         if (preg_match('/^hosts\s*:(.*)$/D', $line, $match) !== 1) {
            continue;
         }
         // ! Drop the `[STATUS=action]` items: only the source order matters here
         $list = preg_replace('/\[[^\]]*\]/', ' ', $match[1]) ?? '';
         $parsed = preg_split('/\s+/', strtolower(trim($list)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
         if ($parsed !== []) {
            $sources = $parsed;
         }
      }

      // @ Configs win
      $search = $Configs->search ?? $search;
      $clean = [];
      foreach ($search as $domain) {
         // ? A domain name: labels of 1 to 63 characters, one optional trailing dot
         if (preg_match('/^[A-Za-z0-9_-]{1,63}(?:\.[A-Za-z0-9_-]{1,63})*\.?$/D', $domain) !== 1) {
            continue;
         }
         $domain = strtolower(rtrim($domain, '.'));
         if (strlen($domain) <= 253 && count($clean) < 6) {
            $clean[] = $domain;
         }
      }

      $this->nameservers = $Configs->nameservers ?? ($nameservers === [] ? ['127.0.0.1'] : $nameservers);
      $this->search = $clean;
      $this->ndots = $Configs->ndots ?? $ndots;
      $this->timeout = (float) ($Configs->timeout ?? $timeout);
      $this->attempts = $Configs->attempts ?? $attempts;
      $this->rotate = $Configs->rotate ?? $rotate;
      $this->EDNS = $Configs->EDNS ?? $EDNS;
      $this->sources = $sources;
   }

   /**
    * Whether a source matching `$Match` comes before `dns` (or `dns` is not a source).
    *
    * @param Closure(string): bool $Match
    */
   private function precede (Closure $Match): bool
   {
      $DNS = array_search('dns', $this->sources, true);
      foreach ($this->sources as $index => $source) {
         if ($DNS !== false && $index > $DNS) {
            break;
         }
         if ($Match($source)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Read a small system file; a missing, unreadable or oversized one is empty.
    */
   private static function read (null|string $path): string
   {
      if ($path === null) {
         return '';
      }
      $size = @filesize($path);
      if ($size === false || $size > 65_536) {
         return '';
      }
      $contents = @file_get_contents($path);

      return $contents === false ? '' : $contents;
   }
}
