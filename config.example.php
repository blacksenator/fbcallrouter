<?php

$config = [
    'url'            => 'fritz.box',        // your Fritz!Box IP (or set '192.168.178.1' or ...)
    'user'           => 'dslf_config',      // your Fritz!Box user ('dslf_config' is the standard TR-064 user)
    'password'       => 'xxxxxxxxx',        // your Fritz!Box user password
    'whitelist'      => 0,                  // phonebook in which you want to check if this number is already known (first = 0!)
    'refresh'        => 1,                  // after how many days the phonebook should be read again
    'blacklist'      => 1,                  // phonebook in which the spam number should be recorded
    'blockForeign'   => true,               // block unknown foreign numbers
    'score'          => 6,                  // must be above 5 (neutral); increase the value to be less sensitive (max. 9)
    'comments'       => 3,                  // should be above 3 decrease the value to be less sensitive (min. 0)
    'caller'         => 'autom. gesperrt',  // alias for new unknown caller
    'timestamp'      => true,               // adding timestamp to the caller: "[caller] ([timestamp])"
    'type'           => 'default',          // type of phone line (home, work, mobil, fax etc.); 'default' = 'sonstige'
    'logging'        => true,
    'loggingPath'    => '',                 // were callrouter_logging.txt schould be saved (default value is = './')
];