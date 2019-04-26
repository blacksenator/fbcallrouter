<?php

namespace blacksenator;

/* fbcallrouter is a spam killer
 *
 * This script is an extension for call routing to/from FRITZ!Box
 * Dependency: callmonitor (port 1012) is open - dial #96*5* to open it
 *
 * The programm is listen to the callmonitor.
 * For an incoming call, it is checked whether the number is already known in the telephone book.
 * If not, it is checked at tellows if this unknown number has received a bad score (> 5) and more than 3 comments.
 * If this is the case, the number will be transferred to the corresponding phonebook for future rejections.
 *
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

$config = [
    'url'          => 'fritz.box',          // your Fritz!Box IP
    'user'         => 'dslf_config',        // your Fritz!Box user
    'password'     => 'xxxxxxxx',           // your Fritz!Box user password
    'getPhonebook' => 0,                    // phonebook in which you want to check if this number is already known (first = 0!)
    'refresh'      => 1,                    // after how many days the phonebook should be read again
    'setPhonebook' => 1,                    // phonebook in which the spam number should be recorded
    'caller'       => 'autom. gesperrt',    // alias for new caller
    'type'         => 'default',            // type of phone line (home, work, mobil, fax etc.)
];

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/callrouter.php';

$callrouter = new callrouter($config);
// get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
$fbSocket = $callrouter->getSocket();
// initial load current phonebook
$callrouter->getCurrentData($config['getPhonebook']);

// now listen to the callmonitor and wait for new lines
echo 'Cocked and rotated...' . PHP_EOL;
while(true) {
    $newLine = fgets($fbSocket);
    if($newLine != null) {
        $values = explode(';', $newLine);                      // [DATE TIME];[STATUS];[0];[NUMBER];;;
        if ($values[1] == 'RING') {                            // incomming call
            if (!in_array($values[3], $callrouter->currentNumbers)) {      // number is unknown
                if ($callrouter->getRating($values[3]) > 5) {               // bad reputation
                    $callrouter->setContact($config['caller'], $values[3], $config['type'], $config['setPhonebook']);
                }
            }
        }
    } else {
        sleep(1);
    }
    // refresh
    $currentTime = time();
    if ($currentTime > ($callrouter->lastupdate + ($config['refresh'] * 864000))) {
        $callrouter->getCurrentData($config['getPhonebook']);
    }
}
