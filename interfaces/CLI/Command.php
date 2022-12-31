<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use Bootgly\CLI;


class Command
{
   public CLI $CLI;

   // * Config
   // * Data
   // string $_                               /usr/bin/php
   // string $shell                           /bin/bash
   // string $user                            bootgly
   // string $language                        en_US.UTF-8
   // string $pwd                             /path/to/bootgly
   // string $logname                         bootgly
   // string $xdg_session_type                tty
   // string $vscode_git_askpass_node         /path/to
   // string $motd_shown                      pam
   // string $home                            /home/bootgly
   // string $ls_colors                       rs=0:di=01;34:ln=01;36:mh=00:pi=40;33:so=01;35:do=01;...
   // string $git_askpass                     /path/to
   // string $ssh_connection                  123.200.123.200 51123 123.100.123.100 1234
   // string $vscode_git_askpass_extra_args   
   // string $xdg_session_class               user
   // string $android_home                    /path/to
   // string $vscode_git_ipc_handle           /path/to
   // string $shlvl                           1
   // string $android_sdk_root                /path/to
   // string $xdg_sesion_id                   123456
   // string $xdg_runtime_dir                 /run/user/1234
   // string $ssh_client                      123.100.123.100 1234
   // ...

   // string $term                            xterm-256color
   // string $colorterm                       truecolor
   // string $term_program                    vscode
   // string $termprogramversion              1.74.0-insider
   // ! Terminal
   // object $Terminal
   // ...

   // string $argv                            ['index.php', 'test1', 'test2']
   // int $argc                               1
   // ! Argument
   // object $Argument
   // string $script                          'index.php'
   // string $arguments                       ['test1', 'test2']
   // ...
   // * Meta
   // integer $time                           1667682772


   public function __construct (CLI $CLI)
   {
      $this->CLI = $CLI;
   }

   public function __get ($name)
   {
      switch ($name) {
         case '_':
            return $this->_ = $_SERVER['_'];
         case 'shell':
            return $this->shell = $_SERVER['SHELL'];
         // ! Argument
         case 'argv':
            return $this->argv = $_SERVER['argv'];
         case 'argc':
            return $this->argc = $_SERVER['argc'];
      }
   }
}
