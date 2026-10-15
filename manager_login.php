<?php
// The platform (licensing) console moved to its own folder with its own login
// session: platform/login.php. This keeps old bookmarks working.
header('Location: platform/login.php', true, 301);
exit;
