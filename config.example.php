<?php
// Copy this file to config.php and fill in the values. config.php is never committed to Git.
return [
    // Shared database, OUTSIDE public_html so it cannot be downloaded. Created automatically.
    'database_path' => '/home4/rmsugnxf/trume-data/trume.sqlite',
    // Folder of the public website on the server (for its uploads/ folder and default content).
    'public_site_path' => '/home4/rmsugnxf/public_html/trumecompanyltd.truehome.com.ug',
    // Public website address (for previews and "View website" links).
    'public_site_url' => 'https://trumecompanyltd.truehome.com.ug',
    // Used once by install.php to create the first administrator. Use a long random value.
    'setup_key' => 'replace-with-a-long-random-setup-key',
    'site_name' => 'Trume Company Limited',
];
