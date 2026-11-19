<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertion\Snapshots;


use const BOOTGLY_STORAGE_DIR;
use const E_NOTICE;
use const E_WARNING;
use function file_exists;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function strcasecmp;
use function unserialize;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use Throwable;
use UnexpectedValueException;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Assertion\Snapshot;


/**
 * A snapshot kept in a `.snap` file under the storage directory, encoded with
 * `serialize()`.
 *
 * Restoring decodes only data and the classes in `$classes`: any other object
 * comes back as `__PHP_Incomplete_Class` — the comparison fails, it never
 * passes in silence — and no other class is loaded while it decodes (an enum
 * payload included). A file that is there but does not restore — unreadable,
 * empty, garbage, or an object whose typed property refuses what it decoded —
 * is refused: `restore()` returns false, leaves the value alone and sets
 * `$refused`, and `assert()` fails without capturing over it. Only a missing
 * file is captured anew.
 *
 * A snapshot file is trusted to hold the expected value: whoever can write to
 * the storage directory decides what it holds.
 */
class FileStorageSnapshot extends Snapshot
{
   private const string SNAPSHOT_DIR = BOOTGLY_STORAGE_DIR . 'tests/snapshots/';
   /** The classes a snapshot restores by default — the value types PHP ships. */
   public const array CLASSES = [
      stdClass::class,
      DateTime::class,
      DateTimeImmutable::class,
      DateTimeZone::class,
      DateInterval::class,
   ];

   // * Config
   // ..Snapshot

   // * Data
   // ..Snapshot
   /**
    * The classes a restored snapshot may instantiate (an enum of your own
    * too); widen it for a snapshot of your own value objects.
    *
    * @var array<int,class-string>
    */
   public array $classes = self::CLASSES;

   // * Metadata
   // ..Snapshot


   /**
    * Capture the snapshot: the file is replaced whole, atomically, and never
    * written through a link at its place.
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be captured.
    *
    * @return bool Returns true if the snapshot file now holds the value.
    */
   public function capture (string $snapshot, mixed $data): bool
   {
      $directory = self::SNAPSHOT_DIR;
      $File = new File(
         path: "{$directory}{$snapshot}.snap"
      );

      // ! The directory only — its "already exists" is no failure
      $File->Basedir->create();
      // @ A refusal belongs to the restore that met it — never to the value captured after it
      $this->refused = false;
      $this->captured = $File->replace(serialize($data), 0644, durable: false);

      // :
      return $this->captured;
   }

   /**
    * Restore the value captured by the snapshot.
    *
    * @param string $snapshot The snapshot name.
    * @param mixed $data The data value to be restored — untouched when the
    *                    snapshot is missing or does not decode.
    *
    * @return bool Returns true if the snapshot was restored.
    */
   public function restore (string $snapshot, mixed &$data): bool
   {
      // !
      $this->restored = false;
      $this->refused = false;
      $directory = self::SNAPSHOT_DIR;
      $path = "{$directory}{$snapshot}.snap";
      $File = new File(
         path: $path
      );
      // ! The snapshot file itself — never a `.php` or `/index.php` the read side converts a missing name to
      $File->convert = false;

      // ? No snapshot — or one it cannot open
      if ($File->open(File::READONLY_MODE) === false) {
         $this->refused = file_exists($path);

         return false;
      }
      $bytes = $File->read();
      $File->close();
      // ? Nothing to decode
      if ($bytes === false || $bytes === '') {
         $this->refused = true;

         return false;
      }

      // ! Only the listed classes may load while it decodes — an enum payload
      //   reaches the autoloader whatever `allowed_classes` says
      $classes = $this->classes;
      $Guard = static function (string $class) use ($classes): void {
         foreach ($classes as $allowed) {
            if (strcasecmp($allowed, $class) === 0) {
               return;
            }
         }

         throw new UnexpectedValueException('A snapshot may not load a class outside its allowed classes.');
      };
      spl_autoload_register($Guard, prepend: true);
      // ! Every diagnostic raised while it decodes is held here: a warning or a notice — what
      //   unserialize() raises on garbage, a truncation or trailing bytes, prefixed or not — marks
      //   the snapshot as not restored; a deprecation an allowed class raises as it loads does not
      $warned = false;
      set_error_handler(static function (int $level) use (&$warned): bool {
         if (($level & (E_WARNING | E_NOTICE)) !== 0) {
            $warned = true;
         }

         return true;
      });
      // @
      try {
         $value = unserialize($bytes, ['allowed_classes' => $classes, 'max_depth' => 4096]);
      }
      catch (Throwable) {
         $this->refused = true;

         return false;
      }
      finally {
         restore_error_handler();
         spl_autoload_unregister($Guard);
      }
      // ? Garbage, truncated or trailing bytes — refused (`b:0;` is a captured false)
      if ($warned === true || ($value === false && $bytes !== 'b:0;')) {
         $this->refused = true;

         return false;
      }

      $data = $value;
      $this->restored = true;

      // :
      return true;
   }
}
