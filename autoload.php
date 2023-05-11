<?php
#@include __DIR__ . '/@/imports/autoload.php'; // composer

define('BOOTGLY_BASE', __DIR__);
define('BOOTGLY_DIR', __DIR__ . DIRECTORY_SEPARATOR);

// TODO load with autoloader
const BOOTGLY_SUBMODULES = [
   'Bootgly/-abstract/',
   'Bootgly/-base/',
   'Bootgly/-core/',
   'Bootgly/-interfaces/',
   'Bootgly/-modules/',
   'Bootgly/-nodes/',
   'Bootgly/-platforms/'
];

// @ Bootables [0-9]
// 0-abstract
include 'Bootgly/-abstract/@functions.php';
include 'Bootgly/-abstract/@traits.php';
include 'Bootgly/-abstract/__Array.php';
include 'Bootgly/-abstract/__Class.php';
include 'Bootgly/-abstract/__String.php';
include 'Bootgly/-abstract/__String/Escapeable.php';
include 'Bootgly/-abstract/__String/Escapeable/cursor/Positionable.php';
include 'Bootgly/-abstract/__String/Escapeable/cursor/Shapeable.php';
include 'Bootgly/-abstract/__String/Escapeable/cursor/Visualizable.php';
include 'Bootgly/-abstract/__String/Escapeable/mouse/Reportable.php';
include 'Bootgly/-abstract/__String/Escapeable/text/Formattable.php';
include 'Bootgly/-abstract/__String/Escapeable/text/Modifiable.php';
include 'Bootgly/-abstract/__String/Escapeable/viewport/Scrollable.php';
include 'Bootgly/-abstract/__String/Path.php';
include 'Bootgly/-abstract/data/Dir.php';
include 'Bootgly/-abstract/data/Table.php';
include 'Bootgly/-abstract/iterators/Iterator.php';
include 'Bootgly/-abstract/streams/File.php';
include 'Bootgly/-abstract/sockets/Pipe.php';
include 'Bootgly/-abstract/templates/ANSI/Escaped.php';
include 'Bootgly/-abstract/templates/Template.php';
// 1-base
include 'Bootgly/-base/Debugger.php';
include 'Bootgly/-base/Logs.php';
include 'Bootgly/-base/Tests.php';
include 'Bootgly/-base/Benchmark/Benchmark.php';
include 'Bootgly/-base/Debugger/Backtrace.php';
include 'Bootgly/-base/Logger/Logger.php';
include 'Bootgly/-base/Logger/Logging.php';
include 'Bootgly/-base/Logger/functions.php';
include 'Bootgly/-base/functions.php';
// 2-core
include 'Bootgly/-core/Database.php';
include 'Bootgly/-core/Event/Event.php';
include 'Bootgly/-core/Event/Loops.php';
include 'Bootgly/-core/Event/On.php';
include 'Bootgly/-core/Event/Timer.php';
include 'Bootgly/-core/Project.php';
// 3-interfaces
include 'Bootgly/-interfaces/API/Environment.php';
include 'Bootgly/-interfaces/API/Server.php';
include 'Bootgly/-interfaces/CLI/Commands.php';
include 'Bootgly/-interfaces/CLI/Terminal.php';
include 'Bootgly/-interfaces/CLI/Terminal/@/Logger/Logging.php'; // @ trait
include 'Bootgly/-interfaces/CLI/Terminal/@/Tester/Tests.php';
include 'Bootgly/-interfaces/CLI/Terminal/@/Tester/Tests/Test.php';
include 'Bootgly/-interfaces/CLI/Terminal/Input.php';
include 'Bootgly/-interfaces/CLI/Terminal/Output.php';
include 'Bootgly/-interfaces/CLI/Terminal/Output/Cursor.php';
include 'Bootgly/-interfaces/CLI/Terminal/Output/Text.php';
include 'Bootgly/-interfaces/CLI/Terminal/Output/Viewport.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Alert/Alert.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Item.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Menu.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items/Option.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items/Options.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items/extensions/Headers/Header.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items/extensions/Headers/Headers.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisor.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Menu/Items/extensions/Divisors/Divisors.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Progress/Progress.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Progress/Bar/Bar.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Table/Table.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Table/Cells.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Table/Columns.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Table/Row.php';
include 'Bootgly/-interfaces/CLI/Terminal/components/Table/Rows.php';
include 'Bootgly/-interfaces/Web/Connections.php'; // @interface
include 'Bootgly/-interfaces/Web/Packages.php';    // @interface
include 'Bootgly/-interfaces/Web/Servers.php';     // @interface
include 'Bootgly/-interfaces/Web/@/Events/Select.php';
include 'Bootgly/-interfaces/Web/TCP/Client.php';
include 'Bootgly/-interfaces/Web/TCP/Client/Connections.php';
include 'Bootgly/-interfaces/Web/TCP/Client/Packages.php';
include 'Bootgly/-interfaces/Web/TCP/Client/@/Process.php';
include 'Bootgly/-interfaces/Web/TCP/Client/@/CLI/Terminal.php';
include 'Bootgly/-interfaces/Web/TCP/Client/Connections/Connection.php';
include 'Bootgly/-interfaces/Web/TCP/Server.php';
include 'Bootgly/-interfaces/Web/TCP/Server/Connections.php';
include 'Bootgly/-interfaces/Web/TCP/Server/Packages.php';
include 'Bootgly/-interfaces/Web/TCP/Server/@/Process.php';
include 'Bootgly/-interfaces/Web/TCP/Server/@/CLI/Terminal.php';
include 'Bootgly/-interfaces/Web/TCP/Server/Connections/Connection.php';
// 4-modules
include 'Bootgly/-modules/Web/protocols/HTTP.php';
include 'Bootgly/-modules/Web/protocols/HTTP/Request/Ranging.php'; // @trait
// 5-nodes
/*
include 'Bootgly/-nodes/CLI/HTTP/Server.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Request.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Request/@/Meta.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Request/@/Header.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Request/@/Header/Cookie.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Request/@/Content.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Request/Downloader.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Response.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Response/Content.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Response/Header.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Response/Header/Cookie.php';
include 'Bootgly/-nodes/CLI/HTTP/Server/Response/Meta.php';
*/
include 'Bootgly/-nodes/Web/HTTP/Server.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Request.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Request/@/Meta.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Request/@/Header.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Request/@/Header/Cookie.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Request/@/Content.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Request/Downloader.php';
#include 'Bootgly/-nodes/Web/HTTP/Server/Request/Session.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Response.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Response/Content.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Response/Header.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Response/Header/Cookie.php';
include 'Bootgly/-nodes/Web/HTTP/Server/Response/Meta.php';
#include 'Bootgly/-nodes/Web/HTTP/Server/Router.php';
#include 'Bootgly/-nodes/Web/HTTP/Server/Router/@constants.php';
#include 'Bootgly/-nodes/Web/HTTP/Server/Router/Route.php';
// 6-platforms
#include 'Bootgly/-platforms/CLI/Script.php';
include 'Bootgly/-platforms/Web/API.php';
include 'Bootgly/-platforms/Web/App.php';

// @ Classes (Interfaces) [A-Z]
@include BOOTGLY_BASE . '/Bootgly/CLI.php';
@include BOOTGLY_BASE . '/Bootgly/Web.php';

// @ Resources [a-z]
// ...

// @ Workables
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
define('BOOTGLY_WORKABLES_DIR', BOOTGLY_BASE . DIRECTORY_SEPARATOR);

// ! Bootgly
require BOOTGLY_DIR . 'Bootgly.php';

\Bootgly\Bootgly::boot();
