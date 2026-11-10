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
 * What a Selector watches a stream for.
 */
enum Interests
{
   /**
    * The stream can be read without blocking: data arrived, the peer closed
    * its end, or a listening socket has a connection to accept.
    */
   case Read;
   /**
    * The stream can be written without blocking: its send buffer has room, or
    * a non-blocking connect finished.
    */
   case Write;
}
