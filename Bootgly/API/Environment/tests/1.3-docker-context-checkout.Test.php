<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_WORKING_DIR;
use function file_exists;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * Security regression H5, first half — a containerised framework root must
 * never carry a git checkout.
 *
 * `.git` crossing the COPY is the catastrophic shape of the leak: it drags in
 * the whole history, and with it every `.env`, every private script and every
 * internal report the ignore file exists to keep out. Its sibling case measures
 * the individual canaries and needs a provenance-stamped build to do so; this
 * one needs nothing but a container, so it runs wherever the sibling cannot —
 * including a plain `docker build --target framework` with no build args.
 */
/** @var array{contained:bool,mounted:bool,measurable:bool,image:bool,SHA:string} $Stance */
$Stance = require __DIR__ . '/fixtures/docker-context/stance.php';

return new Test(
   description: 'A containerised framework root must carry no git checkout',
   skip: $Stance['measurable'] === false,

   test: function () {
      // ? A kit legitimately nests the framework under `Bootgly/` and arrives by
      //   `git clone`, so only the framework image — where the framework IS the
      //   working base — makes this claim at all.
      yield (new Assertion(
         description: 'A containerised framework root carries no git checkout',
         fallback: 'CONFIRMED H5: the Docker build context copied `.git` into the runtime image.'
      ))
         ->expect(
            BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR
               || file_exists(BOOTGLY_ROOT_DIR . '.git') === false
         )
         ->to->be(true)
         ->assert();
   }
);
