<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\Selector;


/**
 * Why a Selector refused a stream or a wait.
 */
enum Reasons
{
   /**
    * `watch()`: the value is not an open stream — a closed one, another kind
    * of resource, or no resource at all.
    */
   case Invalid;
   /**
    * `watch()`: the stream has no descriptor the system can wait on
    * (`php://memory`, a user wrapper without one).
    */
   case Unselectable;
   /**
    * `watch()`: the stream's descriptor number is at or above what this PHP
    * build can hand to `select()` (`FD_SETSIZE`, 1024 by default) — the
    * process holds too many open descriptors.
    */
   case Overflow;
   /**
    * `wait()`: watched streams were closed without `forget()`. They were
    * dropped from the Selector, so the next `wait()` runs on the rest.
    */
   case Closed;
   /**
    * `wait()`: nothing is watched and no deadline was given — the wait could
    * never return.
    */
   case Empty;
   /**
    * `watch()` or `wait()`: the system refused the call for another reason;
    * the message carries what it reported.
    */
   case Failure;
}
