<?php

$config = [
    'fritzbox' => [
        'url'      => 'fritz.box',          // your Fritz!Box IP
        'user'     => 'dslf_config',        // your Fritz!Box user
        'password' => 'xxxxxxxxxx',         // your Fritz!Box user password
    ],
    'phonebook' => [
        'whitelist' => 0,       // phonebook to check against if this number is already known (first = 0!)
        'blacklist' => 1,       // phonebook in which the spam number should be recorded
        'refresh'   => 1,       // after how many days the whitelist should be read again
    ],
    'contact' => [
        'caller'    => 'autom. gesperrt',   // alias for the new unknown caller
        'timestamp' => true,                // adding timestamp to the caller: "[caller] ([timestamp])"
        'type'      => 'default',           // type of phone line (home, work, mobil, fax etc.); 'default' = 'sonstige'
    ],
    'filter' => [
        'blockForeign'   => true,   // block unknown foreign numbers
        'score'          => 6,      // 5 = neutral, increase the value to be less sensitive (max. 9)
        'comments'       => 3,      // decrease the value to be less sensitive (min 3)
    ],
    'logging' => [
        'log'        => true,
        'logPath'    => '',         // were callrouter_logging.txt schould be saved (default value is = './')
    ],
    'test' => [                     // if program is started with the -t option...
        'numbers' => [              // ...the numbers are injected into the following calls
            '030422068747',         // tellows score > 5
            '0207565747377',        // not existing NDC/STD (OKNz)
            '004433456778',         // foreign number
            '',                     // unknown caler (uses CLIR)
        ],
    ],
];