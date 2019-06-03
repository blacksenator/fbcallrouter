<?php

namespace blacksenator;

//use blacksenator\fritzsoap\fritzsoap;
use blacksenator\callrouter\callrouter;
use \SimpleXMLElement;

function callRouter($config)
{
    $whitelist = (string)$config['whitelist'];
    $blacklist = (string)$config['blacklist'];
    date_default_timezone_set("Europe/Berlin");
    $callrouter = new callrouter($config);
    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    $fbSocket = $callrouter->getSocket();
    if (strpos($callrouter->phonebookList, $whitelist) === false) {
        $message = sprintf('The phonebook #%s (whitelist) is not available!', $whitelist);
        error_log($message);
    } else {
        $callrouter->getCurrentData($whitelist);            // initial load current phonebook
    }
    if (strpos($callrouter->phonebookList, $blacklist) === false) {
        $message = sprintf('The phonebook #%s (blacklist) is not available!', $blacklist);
        error_log($message);
    }

    // now listen to the callmonitor and wait for new lines
    echo 'Cocked and unlocked...' . PHP_EOL;
    $callrouter->setLogging('Program started. Listen to call monitor.');
    while(true) {
        $newLine = fgets($fbSocket);
        if($newLine != null) {
            $values = explode(';', $newLine);                               // [DATE TIME];[STATUS];[0];[NUMBER];;;
            if ($values[1] == 'RING') {                                     // incomming call
                $number = $values[3];                                       // caller number
                $message = sprintf('Call from number %s to MSN %s', $number, $values[4]);
                $callrouter->setLogging($message);
                // step 1: check if number is known (in phonebook included)
                if (in_array($number, $callrouter->currentNumbers)) {
                    $message = sprintf('Number %s found in phonebook #%s', $number, $whitelist);
                    $callrouter->setLogging($message);
                // step 2: put a foreign number on blacklist
                } elseif ($config['blockForeign'] && substr($number, 0, 2) === '00') {
                    $callrouter->setContact($config['caller'], $number, $config['type'], $blacklist);
                    $message = sprintf('Foreign number! Added to spam phonebook #%s', $blacklist);
                    $callrouter->setLogging($message);
                // step 3: put domestic numbers with invalid area code on blacklist
                } elseif (!$callrouter->getArea($number) && !substr($number, 0, 2) === '00') {
                    $callrouter->setContact($config['caller'], $number, $config['type'], $blacklist);
                    $message = sprintf('Caller uses a nonexistent area code! Added to spam phonebook #%s', $blacklist);
                    $callrouter->setLogging($message);
                // step 4: try to get a rating
                } else {
                    $result = $callrouter->getRating($number);
                    if (!$result) {                                     // request returned false
                        $callrouter->setLogging('The request to tellows failed!');
                    } else {
                        // if rating (score & comments) is equal or above settings put on blacklist
                        if (($result['score'] >= $config['score']) && ($result['comments'] >= $config['comments'])) {
                            $callrouter->setContact($config['caller'], $number, $config['type'], $blacklist);
                            $message = sprintf('Caller has a bad reputation (%s/%s)! Added to spam phonebook #%s', $result['score'], $result['comments'], $blacklist);
                            $callrouter->setLogging($message);
                        } else {                                        // positiv or indifferent reputation
                            $message = sprintf('Caller has a rating of %s and %s comments.', $result['score'], $result['comments']);
                            $callrouter->setLogging($message);
                        }
                    }
                }
            }
            if ($values[1] == 'DISCONNECT') {
                $callrouter->setLogging('Disconnected');
            }
        } else {
            sleep(1);
        }
        // refresh
        $currentTime = time();
        if ($currentTime > ($callrouter->lastupdate + ($config['refresh'] * 864000))) {
            $callrouter->getCurrentData($whitelist);
        }
    }
}