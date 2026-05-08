<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


/**
 * Hosts a Fixture slot on the consuming class.
 *
 * Reserved for classes that want to participate in fixture lifecycle without
 * extending Fixture directly (e.g. Specification, Suite extensions).
 */
trait Fixturable // @phpstan-ignore trait.unused
{
   /**
    * Fixture owned by the consuming test-related object.
    */
   public null|Fixture $Fixture = null;
}
