<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

#@include __DIR__ . '/@imports/autoload.php'; // composer

if (defined('BOOTGLY_BASE') === true) {
   return;
}

define('BOOTGLY_BASE', __DIR__);
define('BOOTGLY_DIR', __DIR__ . DIRECTORY_SEPARATOR);

// ? Bootgly
// ! Bootables ([0-9]) || (-[a-z]) || ([0-9]-[a-z])
// 1
include BOOTGLY_DIR . 'Bootgly/1/loader.php';
// 2
include BOOTGLY_DIR . 'Bootgly/2/loader.php';
// 3
include BOOTGLY_DIR . 'Bootgly/3/loader.php';
// 4
include BOOTGLY_DIR . 'Bootgly/4/loader.php';

// ! Classes ([A-Z])
spl_autoload_register (function (string $class) {
   $paths = explode('\\', $class);
   $file = implode('/', $paths) . '.php';

   $included = @include BOOTGLY_WORKABLES_DIR . $file;

   if ($included === false && BOOTGLY_DIR !== BOOTGLY_WORKABLES_DIR) {
      @include BOOTGLY_DIR . $file;
   }
});

// @ API
/*
@include BOOTGLY_DIR . 'Bootgly/API/Environment.php';
@include BOOTGLY_DIR . 'Bootgly/API/Server.php';

// @ CLI
@include BOOTGLY_DIR . 'Bootgly/CLI/Command.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Commading.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Commands.php';
// Terminal
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/Input.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/Output.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/Output/Cursor.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/Output/Text.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/Output/Viewport.php';
// TODO move
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/_/Logger/Logging.php'; // @ trait
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/_/Tester/Tests.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/_/Tester/Tests/Test.php';
// TODO reduce Bootgly/CLI/Terminal/components/ => Bootgly/CLI/components/
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Alert/Alert.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Item.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Menu.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items/Option.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items/Options.php';
// TODO reduce ...extensions/Headers/Header.php => extensions/Header.php
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items/extensions/Headers/Header.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items/extensions/Headers/Headers.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisor.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisors.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Progress/Progress.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Progress/Bar/Bar.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Table/Table.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Table/Cells.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Table/Columns.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Table/Row.php';
@include BOOTGLY_DIR . 'Bootgly/CLI/Terminal/components/Table/Rows.php';
// components
@include BOOTGLY_DIR . 'Bootgly/CLI/components/Header.php';
// programs
#include BOOTGLY_DIR . 'Bootgly/CLI/programs/Script.php';
// @?!
@include BOOTGLY_DIR . 'Bootgly/CLI.php';

// @ Web
@include BOOTGLY_DIR . 'Bootgly/Web/Connections.php'; // @interface
@include BOOTGLY_DIR . 'Bootgly/Web/Packages.php';    // @interface
// TODO move to endpoints
@include BOOTGLY_DIR . 'Bootgly/Web/Servers.php';     // @interface
// events
@include BOOTGLY_DIR . 'Bootgly/Web/events/Select.php';
// interfaces
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Client.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Client/Connections.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Client/Packages.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Client/Connections/Connection.php';
// TODO normalize path
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Client/_/Process.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Client/_/CLI/Terminal.php';

@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Server.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Server/Connections.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Server/Packages.php';
// TODO normalize path
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Server/_/Process.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Server/_/CLI/Terminal.php';
@include BOOTGLY_DIR . 'Bootgly/Web/interfaces/TCP/Server/Connections/Connection.php';
// modules
@include BOOTGLY_DIR . 'Bootgly/Web/modules/HTTP.php';
@include BOOTGLY_DIR . 'Bootgly/Web/modules/HTTP/Request/Ranging.php'; // @trait
// nodes
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request.php';
// TODO normalize path
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request/_/Meta.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request/_/Header.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request/_/Header/Cookie.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request/_/Content.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request/Downloader.php';
#@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Request/Session.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Response.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Response/Content.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Response/Header.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Response/Header/Cookie.php';
@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Response/Meta.php';
#@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Router.php';
#@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Router/@constants.php';
#@include BOOTGLY_DIR . 'Bootgly/Web/nodes/HTTP/Server/Router/Route.php';
// programs
@include BOOTGLY_DIR . 'Bootgly/Web/programs/API.php';
@include BOOTGLY_DIR . 'Bootgly/Web/programs/App.php';
#include BOOTGLY_DIR . 'Bootgly/Web/programs/System.php';
// @?!
@include BOOTGLY_DIR . 'Bootgly/Web.php';
*/

// ! Resources ([a-z])
use Bootgly\Debugger;


function debug (...$vars)
{
   if (Debugger::$trace === null) {
      Debugger::$trace = debug_backtrace();
   }

   $Debugger = new Debugger(...$vars);

   if (Debugger::$trace !== false) {
      Debugger::$trace = null;
   }

   return $Debugger;
}

// ? Workables
// composer?
$installed = BOOTGLY_BASE . '/../../composer/installed.php';
if ( is_file($installed) ) {
   $installed = @include $installed;

   $root = $installed['root']['install_path'] ?? null;
   if ($root) {
      $root = realpath($root);
   }

   define('BOOTGLY_WORKABLES_BASE', $root ?? BOOTGLY_BASE);
} else {
   define('BOOTGLY_WORKABLES_BASE', BOOTGLY_BASE);
}
define('BOOTGLY_WORKABLES_DIR', BOOTGLY_WORKABLES_BASE . DIRECTORY_SEPARATOR);

// @
require BOOTGLY_DIR . 'Bootgly.php';

new Bootgly;
