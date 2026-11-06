<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\API\Platform;
use Bootgly\CLI;
use Bootgly\WPI;


class Bootgly
{
   /**
    * The platform roots Bootgly mounts when installed, each named after its
    * global class and constant (`Console`, `Web`).
    *
    * @var array<int,string>
    */
   private const array PLATFORMS = ['Console', 'Web'];

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   private static bool $booted = false;


   /**
    * Autoboot Bootgly interfaces.
    *
    * Mounts every installed platform root (see `mount()`) before CLI routes a
    * command, since a command may start a project built on that platform.
    * 
    * @return void
    *
    * @throws Exception
    */
   public function autoboot (): void
   {
      // ?
      if (self::$booted)
         throw new Exception("Bootgly has already been booted.");

      // * Metadata
      self::$booted = true;

      // !
      /** @var CLI $CLI */
      /** @var WPI $WPI */
      [
         $CLI,
         $WPI
      ] = require(__DIR__ . '/Bootgly/autoboot.php');

      // @@ Platform roots whose `autoboot.php` ran before Bootgly
      foreach (self::PLATFORMS as $platform) {
         self::mount($platform);
      }

      // @
      $CLI->autoboot();
      $WPI->autoboot();
   }

   /**
    * Mount a platform root: create it, define its global constant and boot it.
    *
    * A platform's `autoboot.php` defines its constants and registers its
    * autoloader, with no Bootgly dependency — so the launchers include it
    * before Bootgly. Its root implements `Bootgly\API\Platform` and can only
    * exist once Bootgly's autoloader does: `autoboot()` mounts the platforms
    * included before it, and a platform `autoboot.php` included after Bootgly
    * (Composer's `files` order) mounts its own root through this method.
    *
    * Does nothing when the platform is not installed (its `autoboot.php` did
    * not run) or its constant already exists — mounted before, or created by
    * a platform release older than 1.1.0 that still creates its own root
    * (that skip goes in 2.0.0).
    *
    * @param string $platform `Console` or `Web`.
    *
    * @return void
    *
    * @throws InvalidArgumentException When `$platform` is not a known platform.
    * @throws Exception When the root class cannot be loaded or does not
    *         implement `Bootgly\API\Platform`.
    */
   public static function mount (string $platform): void
   {
      // ?
      if (in_array($platform, self::PLATFORMS, true) === false) {
         throw new InvalidArgumentException("Unknown platform '{$platform}'.");
      }

      // ! The prefix of its constants (`WEB_ROOT_BASE`)
      $prefix = strtoupper($platform);

      // ? Not installed, or already mounted (by itself before 1.1.0 — remove that skip in 2.0.0)
      if (defined("{$prefix}_ROOT_BASE") === false || defined($platform) === true) {
         return;
      }

      // ? Resolved by the platform's own autoloader: the class lives in its repository
      if (class_exists($platform) === false) {
         throw new Exception("The {$platform} platform root class cannot be loaded.");
      }

      // @
      $Platform = new $platform;

      // ?
      if ($Platform instanceof Platform === false) {
         throw new Exception("The {$platform} platform root does not implement Bootgly\\API\\Platform.");
      }

      // @ The constant first: the root may reach for it while it boots
      define($platform, $Platform);
      $Platform->autoboot();
   }
}
