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
use function array_shift;
use function clearstatcache;
use function count;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_split;
use function rtrim;
use function stat;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function trim;

use Bootgly\ABI\IO\Resolver;


/**
 * The hosts file table (`/etc/hosts`): name → addresses, case-insensitive.
 *
 * Re-checked at most once per second; re-read when the file's device, inode,
 * size or modification time changed — or when it was modified within the
 * second it was read (a same-second, same-size edit would otherwise be
 * missed). A file larger than 1 MiB, missing or unreadable is an empty table.
 */
final class Hosts
{
   // * Config
   /** Path of the hosts file; `null` reads none. */
   public private(set) null|string $path;

   // * Data
   // ...

   // * Metadata
   /** @var array<string,list<string>> */
   private array $Table = [];
   /** @var array<int,int> */
   private array $stamp = [];
   private float $checked = -1.0;
   private bool $racy = false;


   public function __construct (null|string $path)
   {
      // * Config
      $this->path = $path;
   }

   /**
    * Find a name's addresses, in file order.
    *
    * @param string $name A host name (case-insensitive; one trailing dot allowed).
    * @param float $now The current time on a monotonic clock (`Query::clock()`): it paces the checks.
    *
    * @return list<string> The canonical addresses; `[]` when the table has none.
    */
   public function find (string $name, float $now): array
   {
      $this->load($now);

      // :
      return $this->Table[strtolower(rtrim($name, '.'))] ?? [];
   }

   /**
    * Re-read the file when it changed (checked at most once per second).
    */
   private function load (float $now): void
   {
      // ? No file, or checked within the last second
      if ($this->path === null || ($now - $this->checked < 1.0 && $this->racy === false)) {
         return;
      }
      $this->checked = $now;

      // ! The file's identity
      clearstatcache(true, $this->path);
      $Stat = @stat($this->path);
      if (is_array($Stat) === false) {
         $this->Table = [];
         $this->stamp = [];
         return;
      }
      $stamp = [$Stat['dev'], $Stat['ino'], $Stat['size'], $Stat['mtime']];
      // ? Unchanged, and not modified within the second it was read
      if ($stamp === $this->stamp && $this->racy === false) {
         return;
      }
      $this->stamp = $stamp;
      // ! Modified within the last second of the wall clock (the one file times use): a same-size
      //   edit in that tick could follow; a future mtime is not racy — it would re-read every lookup
      $wall = time();
      $this->racy = $Stat['mtime'] >= $wall - 1 && $Stat['mtime'] <= $wall;

      // ? Too large to hold
      if ($Stat['size'] > 1_048_576) {
         $this->Table = [];
         return;
      }

      $contents = @file_get_contents($this->path);
      $this->Table = $contents === false ? [] : self::parse($contents);
   }

   /**
    * Parse hosts-file contents: `address name [aliases...]`, `#` comments.
    *
    * @return array<string,list<string>>
    */
   public static function parse (string $contents): array
   {
      $Table = [];

      // @@ One entry per line
      foreach (explode("\n", $contents) as $line) {
         $comment = strpos($line, '#');
         if ($comment !== false) {
            $line = substr($line, 0, $comment);
         }

         $fields = preg_split('/[ \t\r]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
         if ($fields === false || count($fields) < 2) {
            continue;
         }

         // ? An address the resolver can dial (a zone id is not kept)
         $address = Resolver::normalize((string) array_shift($fields));
         if ($address === null) {
            continue;
         }

         foreach ($fields as $name) {
            $name = strtolower(rtrim($name, '.'));
            if ($name === '') {
               continue;
            }

            $Table[$name] ??= [];
            if (in_array($address, $Table[$name], true) === false) {
               $Table[$name][] = $address;
            }
         }
      }

      // :
      return $Table;
   }
}
