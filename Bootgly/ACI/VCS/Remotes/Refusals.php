<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\VCS\Remotes;


/**
 * Why `Remotes::screen()` refuses a repository URL.
 */
enum Refusals
{
   /**
    * Empty, option-shaped (a leading dash), carrying a control byte, or an
    * authority git and curl would read apart (`?`, `#`, `\`, a blank) — or a
    * host ssh would take for an option.
    */
   case Malformed;
   /**
    * `<helper>::<address>` — handed to `git-remote-<helper>`: `ext::` runs a
    * command, `fd::` reads a descriptor, any other name is a program on `PATH`.
    */
   case Helper;
   /** `http://`, `git://`, `ftp://` — anyone on the path can rewrite what is fetched. */
   case Plaintext;
   /** A scheme other than `https`, `ssh`, `git+ssh` and `file`. */
   case Unsupported;
   /**
    * Userinfo on `https://` (a lone name may be a token) or `file://` (git
    * ignores it but keeps it as the clone's `origin`), or a password on `ssh://`.
    */
   case Credentials;
}
