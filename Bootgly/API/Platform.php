<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


/**
 * A Bootgly platform: the bootable root a top-level interface gives rise to —
 * `Console` over `Bootgly\CLI`, `Web` over `Bootgly\WPI`.
 *
 * A platform is a class library over its interface, shipped in its own
 * repository. Its root `autoboot.php` defines the platform constants and
 * registers the platform autoloader, with no Bootgly dependency; the root class
 * implements this contract, so Bootgly creates its one instance — the global
 * constant named after it — and boots it (`Bootgly::mount()`).
 */
interface Platform
{
   /**
    * Autoboot the platform: warm what the whole process shares, once.
    *
    * A platform boots once per process; a second call throws instead of
    * booting it again.
    *
    * @return void
    */
   public function autoboot (): void;
}
