<?php

namespace blacksenator;

use blacksenator\callrouter\callrouter;

function callRouter($config)
{
    $whitelist = (string)$config['whitelist'];
    $blacklist = (string)$config['blacklist'];
    date_default_timezone_set("Europe/Berlin");
    $callrouter = new callrouter($config);
    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    $fbSocket = $callrouter->getSocket();
    if (strpos($callrouter->getPhonebookList(), $whitelist) === false) {
        $message = sprintf('The phonebook #%s (whitelist) does not exist on the FRITZ!Box!', $whitelist);
        exit($message);
    } else {
        $callrouter->getCurrentData((int)$whitelist);            // initial load current phonebook
    }
    if (strpos($callrouter->getPhonebookList(), $blacklist) === false) {
        $message = sprintf('The phonebook #%s (blacklist) does not exist on the FRITZ!Box!', $blacklist);
        exit($message);
    }

    // now listen to the callmonitor and wait for new lines
    echo 'On guard...' . PHP_EOL;
    $callrouter->setLogging('Program started. Listen to call monitor.');
    while(true) {
        $newLine = fgets($fbSocket);
        if($newLine != null) {
            $foreign = false;
            $values = $callrouter->parseCallString($newLine);           // [timestamp];[type];[conID];[extern];[intern];[device];
            if ($values['type'] == 'RING') {                            // incomming call
                if (empty($values['extern'])) {
                    $number = 'unknown';
                } else {
                    $number = $values['extern'];                         // caller number
                }
                if (substr($number, 0, 2) === '00') {
                    $foreign = true;
                }
                $realName = $config['caller'];
                if ($config['timestamp']) {
                    $realName = $realName . ' (' . $values['timestamp'] . ')';
                }
                $message = sprintf('Call from number %s to MSN %s', $number, $values['intern']);
                $callrouter->setLogging($message);
                // wash cycle 1:
                // skip unknown
                if ($number == 'unknown') {
                    $message = 'Caller uses CLIR - no action possible';
                    $callrouter->setLogging($message);
                // wash cycle 2:
                // check if number is known (in phonebook included)
                } elseif (in_array($number, $callrouter->currentNumbers)) {
                    $message = sprintf('Number %s found in phonebook #%s', $number, $whitelist);
                    $callrouter->setLogging($message);
                // wash cycle 3:
                // put a foreign number on blacklist if blockForeign is set
                } elseif ($foreign && $config['blockForeign']) {
                    $callrouter->setContact($realName, $number, $config['type'], $blacklist);
                    $message = sprintf('Foreign number! Added to spam phonebook #%s', $blacklist);
                    $callrouter->setLogging($message);
                // wash cycle 4:
                // put domestic numbers with faked area code on blacklist
                } elseif (!$foreign && !$callrouter->getArea($number)) {
                    $callrouter->setContact($realName, $number, $config['type'], $blacklist);
                    $message = sprintf('Caller uses a nonexistent area code! Added to spam phonebook #%s', $blacklist);
                    $callrouter->setLogging($message);
                // wash cycle 5:
                // try to get a rating from tellows
                } else {
                    $result = $callrouter->getRating($number);
                    if (!$result) {                                     // request returned false
                        $callrouter->setLogging('The request to tellows failed!');
                    } else {
                        // if rating (score & comments) is equal or above settings put on blacklist
                        if (($result['score'] >= $config['score']) && ($result['comments'] >= $config['comments'])) {
                            $callrouter->setContact($realName, $number, $config['type'], $blacklist);
                            $message = sprintf('Caller has a bad reputation (%s/%s)! Added to spam phonebook #%s', $result['score'], $result['comments'], $blacklist);
                            $callrouter->setLogging($message);
                        } else {                                        // positiv or indifferent reputation
                            $message = sprintf('Caller has a rating of %s and %s comments.', $result['score'], $result['comments']);
                            $callrouter->setLogging($message);
                        }
                    }
                }
            } else {
                $callrouter->setLogging($values['type']);
            }
        } else {
            sleep(1);
        }
        // refresh
        $currentTime = time();
        if ($currentTime > ($callrouter->lastupdate + ($config['refresh'] * 864000))) {
            $callrouter->refreshClient();
            $callrouter->getCurrentData((int)$whitelist);
        }
    }
}