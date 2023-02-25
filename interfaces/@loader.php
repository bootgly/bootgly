<?php
// ! CLI
require 'CLI.php';
// resources
require 'CLI/text/Formatting.php'; // @ trait
// extends
require 'CLI/@/Logger/Logging.php'; // @trait
require 'CLI/@/Tester/Tests.php';
require 'CLI/@/Tester/Tests/Test.php';
// ? Command
require 'CLI/Command.php';
// ? Console
require 'CLI/Console.php';
// ? Output
#require 'CLI/Output.php';
// ? Router
#require 'CLI/Router.php';

// ! OS
require 'OS/Process/Timer.php';

// ! SAPI
require 'SAPI.php';
// ? Environment
require 'SAPI/Environment.php';

// ! Web
require 'Web.php';
require 'Web/Connections.php'; // @interface
require 'Web/Packages.php';    // @interface
require 'Web/Servers.php';     // @interface
// @Events
require 'Web/@/Events/Select.php';
// ? TCP
// ? TCP\Client
require 'Web/TCP/Client.php';
require 'Web/TCP/Client/Connections.php';
require 'Web/TCP/Client/Packages.php';
// @
require 'Web/TCP/Client/@/OS/Process.php';
require 'Web/TCP/Client/@/CLI/Console.php';
// ? TCP\Client\Connection(s)
require 'Web/TCP/Client/Connections/Connection.php';

// ? TCP\Server
require 'Web/TCP/Server.php';
require 'Web/TCP/Server/Connections.php';
require 'Web/TCP/Server/Packages.php';
// @
require 'Web/TCP/Server/@/OS/Process.php';
require 'Web/TCP/Server/@/CLI/Console.php';
// ? TCP\Server\Connection(s)
require 'Web/TCP/Server/Connections/Connection.php';
