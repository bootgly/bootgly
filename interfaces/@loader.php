<?php
// ! CLI
require 'CLI.php';
require 'CLI/@/Logger/Logging.php'; // @traits
// ? Command
require 'CLI/Command.php';
// ? Output
#require 'CLI/Output.php';
// ? Router
#require 'CLI/Router.php';


// ! Web
require 'Web.php';
require 'Web/Servers.php'; // @interface
// @Events
require 'Web/@/Events/Select.php';
// ? TCP
// ? TCP\Client
require 'Web/TCP/Client.php';
// ? TCP\Server
require 'Web/TCP/Server.php';
require 'Web/TCP/Server/@/OS/Process.php'; // @ OS\Process
require 'Web/TCP/Server/@/CLI/Console.php'; // @ CLI\Console
// ? TCP\Server\Connection(s)
require 'Web/TCP/Server/Connection.php';
require 'Web/TCP/Server/Connections.php';
