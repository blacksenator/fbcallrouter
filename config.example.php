<?php

$config = [
    'url'          => 'fritz.box',          // your Fritz!Box IP
    'user'         => 'dslf_config',        // your Fritz!Box user
    'password'     => 'xxxxxxxxx',          // your Fritz!Box user password
    'getPhonebook' => 0,                    // phonebook in which you want to check if this number is already known (first = 0!)
    'refresh'      => 1,                    // after how many days the phonebook should be read again
    'setPhonebook' => 1,                    // phonebook in which the spam number should be recorded
    'caller'       => 'autom. gesperrt',    // alias for new caller
    'type'         => 'default',            // type of phone line (home, work, mobil, fax etc.)
    'logging'      => '',                   // were callrouter_logging.txt schould be saved e.g. 'C:/Users/Admin/Documents/',
];