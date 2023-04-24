<?php
require '@functions/formatters.php';

require '@interfaces/Debuggable.php';
require '@interfaces/Requestable.php';

require '@traits/Set/Set.php';
require '@traits/Sets/Sets.php';

// __Array
require '__Array/__Array.php';
require '__Array/~functions.php';
// __Class
require '__Class/__Class.php';
require '__Class/Adopted/Adopted.php';
require '__Class/Nulled/Nulled.php';
// __Iterable
require '__Iterable/__Iterable.php';
// __String
#require '__String/.functions.php';
require '__String/__String.php';

require '__String/Escaping/Escaping.php';
// cursor
require '__String/Escaping/cursor/Positioning.php';
require '__String/Escaping/cursor/Shaping.php';
require '__String/Escaping/cursor/Visualizing.php';
// mouse
require '__String/Escaping/mouse/Reporting.php';
// text
require '__String/Escaping/text/Formatting.php';
require '__String/Escaping/text/Modifying.php';
// viewport
require '__String/Escaping/viewport/Positioning.php';

require '__String/~functions.php';

// - data
// Table
require 'data/Table/Table.php';
// - streams/socket
// Pipe
require 'streams/socket/Pipe/Pipe.php';
// - storage
// Dir
require 'streams/storage/Dir/Dir.php';
// File
require 'streams/storage/File/File.php';
// Path
require 'streams/storage/Path/Path.php';
