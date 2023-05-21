<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\Terminal\Input;


enum Keystrokes : string
{
   case BACKSPACE = "\177";
   case ESCAPE    = "\e";
   case ENTER     = "\n";
   case TAB       = "\t";
   case SPACE     = " ";

   case UP        = "\e[A";
   case DOWN      = "\e[B";
   case RIGHT     = "\e[C";
   case LEFT      = "\e[D";

   case HOME      = "\e[H";
   case INSERT    = "\e[2~";
   case DELETE    = "\e[3~";
   case END       = "\e[F";
   case PAGEUP   = "\e[5~";
   case PAGEDOWN = "\e[6~";

   case F1  = "\eOP";
   case F2  = "\eOQ";
   case F3  = "\eOR";
   case F4  = "\eOS";
   case F5  = "\e[15~";
   case F6  = "\e[17~";
   case F7  = "\e[18~";
   case F8  = "\e[19~";
   case F9  = "\e[20~";
   case F10 = "\e[21~";
   case F11 = "\e[23~";
   case F12 = "\e[24~";

   // @ Combined keys
   // CTRL + [key]
   case CTRL_A = "\x01";
   case CTRL_B = "\x02";
   case CTRL_C = "\x03";
   case CTRL_D = "\x04";
   case CTRL_E = "\x05";
   case CTRL_F = "\x06";
   case CTRL_G = "\x07";
   case CTRL_H = "\x08"; // \b
   #case CTRL_I = "\x09"; // (duplicated with TAB)
   #case CTRL_J = "\x0A"; // (duplicated with ENTER)
   case CTRL_K = "\x0B";
   case CTRL_L = "\x0C"; // \f
   case CTRL_M = "\x0D"; // \n?
   case CTRL_N = "\x0E";
   case CTRL_O = "\x0F";
   case CTRL_P = "\x10";
   case CTRL_Q = "\x11";
   case CTRL_R = "\x12";
   case CTRL_S = "\x13";
   case CTRL_T = "\x14";
   case CTRL_U = "\x15";
   case CTRL_V = "\x16";
   case CTRL_W = "\x17";
   case CTRL_X = "\x18";
   case CTRL_Y = "\x19";
   case CTRL_Z = "\x1A";

   case CTRL_UP    = "\e[1;5A";
   case CTRL_DOWN  = "\e[1;5B";
   case CTRL_RIGHT = "\e[1;5C";
   case CTRL_LEFT  = "\e[1;5D";

   case CTRL_BACKSLASH     = "\x1C"; // Ctrl + \
   #case CTRL_LEFT_BRACKET = "\x1B"; // Ctrl + [ (duplicated with ESCAPE)
   case CTRL_RIGHT_BRACKET = "\x1D"; // Ctrl + ]
   case CTRL_UNDERSCORE    = "\x1F"; // Ctrl + _
   case CTRL_AT            = "\x00"; // Ctrl + @
   case CTRL_CIRCUMFLEX    = "\x1E"; // Ctrl + ^

   // SHIFT + [key]
   case SHIFT_TAB = "\e[Z";

   case SHIFT_UP    = "\e[1;2A";
   case SHIFT_DOWN  = "\e[1;2B";
   case SHIFT_RIGHT = "\e[1;2C";
   case SHIFT_LEFT  = "\e[1;2D";

   // ALT + [key]
   case ALT_UP    = "\e[1;3A";
   case ALT_DOWN  = "\e[1;3B";
   case ALT_RIGHT = "\e[1;3C";
   case ALT_LEFT  = "\e[1;3D";

   case ALT_INSERT    = "\e[2;3~"; // Alt + Insert
   case ALT_DELETE    = "\e[3;3~"; // Alt + Delete
   case ALT_HOME      = "\e[1;3H"; // Alt + Home
   case ALT_END       = "\e[1;3F"; // Alt + End
   case ALT_PAGEUP   = "\e[5;3~"; // Alt + Page Up
   case ALT_PAGEDOWN = "\e[6;3~"; // Alt + Page Down
}
