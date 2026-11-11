<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Registration;


/**
 * How the Router stored a registered route — which lookup dispatches it.
 */
enum Kinds
{
   /**
    * A literal path, found by one hash lookup, dispatched through its handler.
    */
   case Static;
   /**
    * A path with `:param` segments, matched by segments or by its pattern.
    */
   case Dynamic;
   /**
    * The generic `/*` fallback, dispatched when nothing else matched.
    */
   case CatchAll;
   /**
    * A literal path registered with `serve()` and no middleware: its body is
    * copied into the response without a handler call.
    */
   case Body;
}
