<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__String\Escapeable\Viewport;


use Bootgly\ABI\Code\__String\Escapeable\Viewport;


trait Bufferable
{
   use Viewport;


   // * Metadata
   // ! Buffering
   /**
    * [?1049h] (Alternate Screen Buffer — enable)
    * "Switch to the alternate screen buffer, preserving the main screen contents"
    */
   public const _VIEWPORT_ENABLE_ALTERNATE_BUFFER = '?1049h';
   /**
    * [?1049l] (Alternate Screen Buffer — disable)
    * "Switch back to the main screen buffer, restoring its contents"
    */
   public const _VIEWPORT_DISABLE_ALTERNATE_BUFFER = '?1049l';
}
