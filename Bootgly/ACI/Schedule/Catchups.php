<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Schedule;


/**
 * Missed-run catch-up policy for a scheduled Job.
 *
 * - `Skip` — ignore minutes missed while the worker was down; resume from now.
 * - `Once` — if one or more scheduled runs were missed, run the Job once on boot.
 */
enum Catchups
{
   case Skip;
   case Once;
}
