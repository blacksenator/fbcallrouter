<?php

$config = [
    'fritzbox' => [
        'url'      => 'fritz.box',          // your Fritz!Box IP
        'user'     => 'youruser',           // your Fritz!Box user
        'password' => 'xxxxxxxxxx',         // your Fritz!Box user password
    ],
    'phonebook' => [
        'whitelist' => [0], // phone books number is already known (first index = 0!)
        'blacklist' => 1,   // phone book in which the spam number should be recorded
        'newlist'   => 0,   // optional: phone book in which the reverse searchable entries should be recorded
        'refresh'   => 1,   // after how many days the phone books should be read again
    ],
    'contact' => [
        'caller'    => 'autom. gesperrt',   // alias for the new unknown caller
        'timestamp' => true,    // adding timestamp to the caller: "[caller] ([timestamp])"
        'type'      => 'other', // type of phone line (home, work, mobil, fax etc.); 'other' = 'sonstige'
    ],
    'filter' => [
        'msn'          => [],   // MSNs to react on (['321765', '321766']; empty = all)
        'blockForeign' => true, // block unknown foreign numbers
        'score'        => 6,    // 5 = neutral, increase the value to be less sensitive (max. 9)
        'comments'     => 3,    // decrease the value to be less sensitive (min 3)
    ],
    'logging' => [
        'log'     => true,
        'logPath' => '',    // were callrouter_logging.txt schould be saved (default value is = './')
    ],
    /*
    'email' => [
        'url'      => 'smtp...',
        'port'     => 587,                                          // alternativ 465
        'secure'   => 'tls',                                        // alternativ 'ssl'
        'user'     => '[USER]',                                     // your sender email adress e.g. account
        'password' => '[PASSWORD]',
        'sender'   => '',                                           // OPTIONAL:your email adress who is sending this email
        'receiver' => 'blacksenator@github.com',                    // your email adress to receive the secured contacts
        'debug'    => 0,                                            // 0 = off (for production use)
                                                                    // 1 = client messages
                                                                    // 2 = client and server messages
    ],
    */
    'test' => [                 // if program is started with the -t option...
        'numbers' => [          // ...the numbers are injected into the following calls
            '03681443300750',   // tellows score > 5, comments > 3
            '0207565747377',    // not existing NDC/STD (OKNz)
            '0618107162530',    // valid NDC/STD, but invalid subscriber number
            '004433456778',     // foreign number
            '',                 // unknown caler (uses CLIR)
        ],
    ],
];
