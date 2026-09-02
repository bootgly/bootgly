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


use function file_get_contents;
use function is_dir;
use function is_file;
use function is_link;
use function readlink;
use function rtrim;
use function str_contains;
use function trim;


/**
 * The init systems a Linux machine may boot with, and how to tell which one
 * did: by the footprint a running init leaves under `/run` first, by the name
 * of PID 1 second, and by the binaries installed last — an installed init is
 * not a booted one.
 */
enum Inits: string
{
   case Systemd = 'systemd';
   case OpenRC = 'OpenRC';
   case Runit = 'runit';
   case S6 = 's6';
   case SysV = 'SysV init';
   case BusyBox = 'BusyBox init';
   /** No init at all — PID 1 is the application itself, as inside a container. */
   case None = 'none';


   /**
    * Detect the init system that booted the machine rooted at `$root`.
    *
    * @param string $root The filesystem root to inspect — `/` for this machine.
    *
    * @return self
    */
   public static function detect (string $root = '/'): self
   {
      $root = rtrim($root, '/');

      // ? The running footprint decides
      if (is_dir("{$root}/run/systemd/system") === true) {
         return self::Systemd;
      }
      if (is_dir("{$root}/run/openrc") === true) {
         return self::OpenRC;
      }
      if (is_dir("{$root}/run/runit") === true) {
         return self::Runit;
      }
      if (is_dir("{$root}/run/s6") === true || is_dir("{$root}/run/s6-rc") === true) {
         return self::S6;
      }

      // ! PID 1 by name — inside a container it is usually the application
      $comm = @file_get_contents("{$root}/proc/1/comm");
      $comm = $comm === false ? '' : trim($comm);
      if ($comm === 'systemd') {
         return self::Systemd;
      }
      if ($comm !== '' && $comm !== 'init') {
         return self::None;
      }

      // ! By the binaries, when PID 1 is a bare `init` or unreadable
      $init = "{$root}/sbin/init";
      $target = is_link($init) === true ? (string) readlink($init) : '';
      if (str_contains($target, 'busybox') === true) {
         return self::BusyBox;
      }
      // ? systemd installed behind /sbin/init but not booted (WSL2 without it)
      if (str_contains($target, 'systemd') === true) {
         return self::None;
      }
      if (is_file("{$root}/sbin/openrc") === true || is_file("{$root}/sbin/openrc-run") === true) {
         return self::OpenRC;
      }
      if (is_dir("{$root}/etc/runit") === true) {
         return self::Runit;
      }
      if (is_dir("{$root}/etc/s6") === true) {
         return self::S6;
      }
      if (is_dir("{$root}/etc/init.d") === true && is_file($init) === true) {
         return self::SysV;
      }

      // :
      return self::None;
   }
}
