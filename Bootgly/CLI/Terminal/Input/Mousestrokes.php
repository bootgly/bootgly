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


enum Mousestrokes : string
{
   // ! Actions \e[<35;1;1m
   //               ↑↑
   // ? Clicks
   case LEFT_CLICK = "0";   // \e[<0;1;1M
   case MIDDLE_CLICK = "1"; // \e[<1;1;1M
   case RIGHT_CLICK = "2";  // \e[<2;1;1M
   // TODO 3 ?
   // * Combineds (Actions + Keystrokes)
   // @ Clicks + Keystrokes
   // Clicks + SHIFT
   case LEFT_CLICK_WITH_SHIFT = "4";
   case MIDDLE_CLICK_WITH_SHIFT = "5";
   case RIGHT_CLICK_WITH_SHIFT = "6";
   // TODO 7 ?
   // Clicks + ALT
   case LEFT_CLICK_WITH_ALT = "8";
   case MIDDLE_CLICK_WITH_ALT = "9";
   case RIGHT_CLICK_WITH_ALT = "10";
   // TODO 11 ?
   // Clicks + (SHIFT + ALT)
   case LEFT_CLICK_WITH_SHIFT_ALT = "12";
   case MIDDLE_CLICK_WITH_SHIFT_ALT = "13";
   case RIGHT_CLICK_WITH_SHIFT_ALT = "14";
   // TODO 15 ?
   // Clicks + CTRL
   case LEFT_CLICK_WITH_CTRL = "16";
   case MIDDLE_CLICK_WITH_CTRL = "17";
   case RIGHT_CLICK_WITH_CTRL = "18";
   // TODO 19 ?
   // Clicks + (SHIFT + CTRL)
   case LEFT_CLICK_WITH_SHIFT_CTRL = "20";
   case MIDDLE_CLICK_WITH_SHIFT_CTRL = "21";
   case RIGHT_CLICK_WITH_SHIFT_CTRL = "22";
   // TODO 23 ?
   // Clicks + (ALT + CTRL)
   case LEFT_CLICK_WITH_ALT_CTRL = "24";
   case MIDDLE_CLICK_WITH_ALT_CTRL = "25";
   case RIGHT_CLICK_WITH_ALT_CTRL = "26";
   // TODO 27-31 (5) ... ?
   // ? Movements
   // @ Clicks + Movements
   case LEFT_CLICK_WITH_MOVEMENT = "32";
   case MIDDLE_CLICK_WITH_MOVEMENT = "33";
   case RIGHT_CLICK_WITH_MOVEMENT = "34";
   case NONE_CLICK_WITH_MOVEMENT = "35";
   // @ Clicks + Movements + Keystrokes
   // Clicks + Movements + SHIFT
   case LEFT_CLICK_WITH_MOVEMENT_WITH_SHIFT = "36";
   case MIDDLE_CLICK_WITH_MOVEMENT_WITH_SHIFT = "37";
   case RIGHT_CLICK_WITH_MOVEMENT_WITH_SHIFT = "38";
   case NONE_CLICK_WITH_MOVEMENT_WITH_SHIFT = "39";
   // Clicks + Movements + ALT
   case LEFT_CLICK_WITH_MOVEMENT_WITH_ALT = "40";
   case MIDDLE_CLICK_WITH_MOVEMENT_WITH_ALT = "41";
   case RIGHT_CLICK_WITH_MOVEMENT_WITH_ALT = "42";
   case NONE_CLICK_WITH_MOVEMENT_WITH_ALT = "43";
   // Clicks + Movements + (SHIFT + ALT)
   case LEFT_CLICK_WITH_MOVEMENT_WITH_SHIFT_ALT = "44";
   case MIDDLE_CLICK_WITH_MOVEMENT_WITH_SHIFT_ALT = "45";
   case RIGHT_CLICK_WITH_MOVEMENT_WITH_SHIFT_ALT = "46";
   case NONE_CLICK_WITH_MOVEMENT_WITH_SHIFT_ALT = "47";
   // Clicks + Movements + CTRL
   case LEFT_CLICK_WITH_MOVEMENT_WITH_CTRL = "48";
   case MIDDLE_CLICK_WITH_MOVEMENT_WITH_CTRL = "49";
   case RIGHT_CLICK_WITH_MOVEMENT_WITH_CTRL = "50";
   case NONE_CLICK_WITH_MOVEMENT_WITH_CTRL = "51";
   // Clicks + Movements + (SHIFT + CTRL)
   case LEFT_CLICK_WITH_MOVEMENT_WITH_SHIFT_CTRL = "52";
   case MIDDLE_CLICK_WITH_MOVEMENT_WITH_SHIFT_CTRL = "53";
   case RIGHT_CLICK_WITH_MOVEMENT_WITH_SHIFT_CTRL = "54";
   case NONE_CLICK_WITH_MOVEMENT_WITH_SHIFT_CTRL = "55";
   // Clicks + Movements + (ALT + CTRL)
   case LEFT_CLICK_WITH_MOVEMENT_WITH_ALT_CTRL = "56";
   case MIDDLE_CLICK_WITH_MOVEMENT_WITH_ALT_CTRL = "57";
   case RIGHT_CLICK_WITH_MOVEMENT_WITH_ALT_CTRL = "58";
   case NONE_CLICK_WITH_MOVEMENT_WITH_ALT_CTRL = "59";
   // TODO 60-63 ?
   // ? Scroll
   case SCROLL_UP = "64";
   case SCROLL_DOWN = "65";
   // TODO 66-71 ?
   case SCROLL_UP_WITH_ALT = "72";
   case SCROLL_DOWN_WITH_ALT = "73";
   // TODO 74-79 ?
   case SCROLL_UP_WITH_CTRL = "80";
   case SCROLL_DOWN_WITH_CTRL = "81";

   // ! States \e[<35;1;1m
   //                    ↑
   case CLICKED = "M";
   case UNCLICKED = "m";
}
