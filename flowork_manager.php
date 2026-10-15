<?php
// The platform (licensing) console moved to its own folder with its own login
// session: platform/index.php. This keeps old bookmarks working.
header('Location: platform/index.php', true, 301);
exit;
