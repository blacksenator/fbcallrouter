<?php

namespace blacksenator;

//use blacksenator\fritzsoap\fritzsoap;
use blacksenator\callrouter\callrouter;
use \SimpleXMLElement;
use \stdClass;

function callRouter($config)
{
    $callrouter = new callrouter($config);
    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    $fbSocket = $callrouter->getSocket();
    // initial load current phonebook
    $callrouter->getCurrentData($config['getPhonebook']);

    // now listen to the callmonitor and wait for new lines
    echo 'Cocked and unlocked...' . PHP_EOL;
    while(true) {
        $newLine = fgets($fbSocket);
        if($newLine != null) {
            $values = explode(';', $newLine);                               // [DATE TIME];[STATUS];[0];[NUMBER];;;
            if ($values[1] == 'RING') {                                     // incomming call
                $number = $values[3];                                       // caller number
                if (!in_array($number, $callrouter->currentNumbers)) {      // number is unknown
                    if (!$callrouter->getArea($number)) {                   // fake area code
                        $callrouter->setContact($config['caller'], $number, $config['type'], $config['setPhonebook']);
                    } else {
                        if ($callrouter->getRating($number) > 5) {          // bad reputation
                            $callrouter->setContact($config['caller'], $number, $config['type'], $config['setPhonebook']);
                        }
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
}