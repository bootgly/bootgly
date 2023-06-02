<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\__String\Escapeable\mouse;


trait Reportable
{
   /**
    * [?1000x] (Mouse Click Reporting)
    */
   public const _MOUSE_ENABLE_CLICK_REPORTING = '?1000h';
   public const _MOUSE_DISABLE_CLICK_REPORTING = '?1000l';

   /**
    * [?1001x] (Mouse Highlight Reporting)
    */
   public const _MOUSE_ENABLE_HIGHLIGHT_REPORTING = '?1001l';
   public const _MOUSE_DISABLE_HIGHLIGHT_REPORTING = '?1001l';

   /**
    * [?1002x] (Mouse Button Event Reporting)
    */
   public const _MOUSE_ENABLE_BUTTON_REPORTING = '?1002l';
   public const _MOUSE_DISABLE_BUTTON_REPORTING = '?1002l';

   /**
    * [?1003x] (Mouse All Event Reporting)
    */
   public const _MOUSE_ENABLE_ALL_EVENT_REPORTING = '?1003h';
   public const _MOUSE_DISABLE_ALL_EVENT_REPORTING = '?1003l';

   // ! Coordinates
   // @ Extending
   // Modes
   /**
    * [?1006x] (Mouse Set SGR (Select Graphic Rendition) extended mode)
    */
   public const _MOUSE_SET_SGR_EXT_MODE = '?1006h';
   public const _MOUSE_UNSET_SGR_EXT_MODE = '?1006l';
   /**
    * [?1015x] (Mouse Set URXVT mode)
    */
   public const _MOUSE_SET_URXVT_EXT_MODE = '?1015h';
   public const _MOUSE_UNSET_URXVT_EXT_MODE = '?1015l';
   /**
    * [?1016x] (Mouse Set Pixel Position mode)
    * Use the same mouse response format as the 1006 control, but
    *     report position in pixels rather than character cells.
    */
    public const _MOUSE_SET_PIXEL_POSITION_MODE = '?1016h';
    public const _MOUSE_UNSET_PIXEL_POSITION_MODE = '?1016l';
}
