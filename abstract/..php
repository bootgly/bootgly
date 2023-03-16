<?php
require '@functions/formatters.php';
require '@interfaces/Requestable.php';
require '@traits/Configuring.php';


// * .Constructors
// ! .Types
// ? __Array
require '__array/.php';
require '__array/~functions.php';
// ? __Iterable
require '__iterable/.php';
// ? __String
#require '__String.functions.php';
require '__string/.php';
require '__string/~functions.php';
// ! __Class
require '__class/adopted/.php';
require '__class/nulled/.php';
#require '__Class.functions.php';

#require '__Class.php';
// ? Index
#require '__Class@Index.php';
// ? Index/Handlers
#require '__Class@Index@Handlers.php';
// ? Index/Permissions
#require '__Class@Index@Permissions.php';
// ? Index/Status
#require '__Class@Index@Status.php';


// *
// ! Autoloader
#require 'Autoloader.php';

// ? __Class
#require 'Autoloader@__Class.php';

// ! .Data
// ? Table
require 'data/table/.php';

// ! .Streams
// ? Dir
require 'dir/.php';
// ? File
require 'file/.php';
// ? Path
#require 'Path.constants.php';
#require 'Path.functions.php';
#require 'Path.globals.php';
#require 'Path~constants.php';
#require 'Path~functions.php';

require 'path/.php';


// * .Controllers
// ! Output
#require 'Output.php';
// ? Output/Buffer
#require 'Output@Buffer.php';
