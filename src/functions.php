<?php

namespace blacksenator;

//use blacksenator\fritzsoap\fritzsoap;
use blacksenator\callrouter\callrouter;
use \SimpleXMLElement;

function callRouter($config)
{
    date_default_timezone_set("Europe/Berlin");
    $callrouter = new callrouter($config);
    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    $fbSocket = $callrouter->getSocket();
    // initial load current phonebook
    $callrouter->getCurrentData($config['getPhonebook']);

    // now listen to the callmonitor and wait for new lines
    echo 'Cocked and unlocked...' . PHP_EOL;
    $callrouter->setLogging('Program started. Listen to call monitor.' . PHP_EOL);
    while(true) {
        $newLine = fgets($fbSocket);
        if($newLine != null) {
            $values = explode(';', $newLine);                               // [DATE TIME];[STATUS];[0];[NUMBER];;;
            if ($values[1] == 'RING') {                                     // incomming call
                $number = $values[3];                                       // caller number
                $message = sprintf('Call from number %s to MSN %s', $number, $values[4]);
                $callrouter->setLogging($message . PHP_EOL);
                if (!in_array($number, $callrouter->currentNumbers)) {      // number is unknown
                    $callrouter->setLogging('Could not find caller in phonebook. Checking area code.' . PHP_EOL);
                    $areaCode = $callrouter->getArea($number);
                    if (!$areaCode) {                                       // fake area code
                        $callrouter->setContact($config['caller'], $number, $config['type'], $config['setPhonebook']);
                        $message = sprintf('Caller used fake area code! Added to spam phonebook #%s', $config['setPhonebook']);
                        $callrouter->setLogging($message . PHP_EOL);
                    } else {
                        $message = sprintf('Found valid area code: %s', $areaCode);
                        $callrouter->setLogging($message . PHP_EOL);
                        if ($callrouter->getRating($number) > 5) {          // bad reputation
                            $callrouter->setContact($config['caller'], $number, $config['type'], $config['setPhonebook']);
                            $message = sprintf('Caller has a bad reputation! Added to spam phonebook #%s', $config['setPhonebook']);
                            $callrouter->setLogging($message . PHP_EOL);
                        }
                    }
                } else {
                    $message = sprintf('Number %s found in phonebook #%s', $number, $config['getPhonebook']);
                    $callrouter->setLogging($message . PHP_EOL);
                }
            }
            if ($values[1] == 'DISCONNECT') {
                $callrouter->setLogging('Disconnected' . PHP_EOL);
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