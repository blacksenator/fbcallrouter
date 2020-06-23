<?php

namespace blacksenator;

use blacksenator\callrouter\callrouter;
// use blacksenator\fritzsoap\fritzbox;

define('ONEDAY', 86400);
define('TWOHRS', 7200);

function callRouter(array $config, array $testNumbers = [])
{
    // initialization
    date_default_timezone_set("Europe/Berlin");
    $fritzbox = $config['fritzbox'];
    $phonebook = $config['phonebook'];
    $logging = $config['logging'];
    $log = $logging['log'];
    $contact = $config['contact'];
    $filter = $config['filter'];
    $elapse = $phonebook['refresh'] < 1 ? ONEDAY : $phonebook['refresh'] * ONEDAY;     // sec to next whitelist refresh
    $whitelist = (string)$phonebook['whitelist'];
    $blacklist = (string)$phonebook['blacklist'];
    $nextUpdate = 0;
    $nextRefresh = 0;
    $testCases = count($testNumbers);
    $testCounter = 0;
    $callrouter = new callrouter($fritzbox, $logging['logPath']);
    // load phonebooks
    if (strpos($callrouter->getPhonebookList(), $whitelist) === false) {
        $message = sprintf('The phonebook #%s (whitelist) does not exist on the FRITZ!Box!', $whitelist);
        throw new \Exception($message);
    } else {
        $callrouter->getCurrentData((int)$whitelist);            // initial load current phonebook
        $nextUpdate = $callrouter->getLastUpdate() + $elapse;
        setLogging($callrouter, $log, 1, [date('d.m.Y H:i:s', $nextUpdate)]);
    }
    if (strpos($callrouter->getPhonebookList(), $blacklist) === false) {
        $message = sprintf('The phonebook #%s (blacklist) does not exist on the FRITZ!Box!', $blacklist);
        throw new \Exception($message);
    }

    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    $fbSocket = $callrouter->getSocket();
    $nextRefresh = time() + TWOHRS;
    echo 'On guard...' . PHP_EOL;
    setLogging($callrouter, $log, 0, ['Guarding started: listen to FRITZ!Box call monitor']);
    // now listen to the callmonitor and wait for new lines
    while (true) {
        // get the current line from port
        $newLine = fgets($fbSocket);
        if ($newLine != null) {
            $values = $callrouter->parseCallString($newLine);       // [timestamp];[type];[conID];[extern];[intern];[device];
            if ($values['type'] == 'RING') {                        // incomming call
                $number = $values['extern'];                        // caller number

                // test  (if you use the -t option)
                if ($testCases > 0) {
                    if ($testCounter === 0) {
                        setLogging($callrouter, $log, 0, ['START OF TEST OPERATION']);
                    } elseif ($testCounter === $testCases) {
                        setLogging($callrouter, $log, 0, ['END OF TEST OPERATION']);
                        $testCounter = 0;
                        $testCases = 0;
                    }
                }
                // injection routine
                if ($testCounter < $testCases) {                    // as long as the test case was not used
                    echo sprintf('Running test case %s/%s', $testCounter + 1, $testCases) . PHP_EOL;
                    // inject the next sanitized number from row
                    $number = $callrouter->sanitizeNumber($testNumbers[$testCounter]);
                    $testCounter++;                                 // increment counter
                }

                // detect foreign numbers
                $foreign = true ? substr($number, 0, 2) === '00' : false;

                $realName = $contact['caller'];
                if ($contact['timestamp']) {
                    $realName = $realName . ' (' . $values['timestamp'] . ')';
                }
                setLogging($callrouter, $log, 2, [$number, $values['intern']]);
                // wash cycle 1:
                // skip unknown
                if (empty($number)) {
                    setLogging($callrouter, $log, 0, ['Caller uses CLIR - no action possible']);
                // wash cycle 2:
                // check if number is known (in phonebook included)
                } elseif (in_array($number, $callrouter->currentNumbers)) {
                    setLogging($callrouter, $log, 3, [$number, $whitelist]);
                // wash cycle 3:
                // skip local number
                } elseif (substr($number, 0, 1) != '0') {
                    setLogging($callrouter, $log, 0, ['No "0" as Perfix. No action possible']);
                // wash cycle 4:
                // put a foreign number on blacklist if blockForeign is set
                } elseif ($foreign && $filter['blockForeign']) {
                    $callrouter->refreshClient();
                    $callrouter->setContact($realName, $number, $contact['type'], $blacklist);
                    setLogging($callrouter, $log, 4, [$blacklist]);
                    // wash cycle 5:
                // put domestic numbers with faked area code on blacklist
                } elseif (!$foreign && !$callrouter->getArea($number)) {
                    $callrouter->refreshClient();
                    $callrouter->setContact($realName, $number, $contact['type'], $blacklist);
                    setLogging($callrouter, $log, 5, [$blacklist]);
                // wash cycle 6:
                // try to get a rating from tellows
                } else {
                    $result = $callrouter->getRating($number);
                    if (!$result) {                                     // request returned false
                        setLogging($callrouter, $log, 0, ['The request to tellows failed!']);
                    } else {
                        $numberScore = $result['score'];
                        $numberComments = $result['comments'];
                        // if rating (score & comments) is equal or above settings put on blacklist
                        if (($numberScore >= $filter['score']) && ($numberComments >= $filter['comments'])) {
                            $callrouter->refreshClient();
                            $callrouter->setContact($realName, $number, $contact['type'], $blacklist);
                            setLogging($callrouter, $log, 6, [$numberScore, $numberComments, $blacklist]);
                        } else {                                        // positiv or indifferent reputation
                            setLogging($callrouter, $log, 7, [$numberScore, $numberComments]);
                        }
                    }
                }
            } else {
                $type = $values['type'] == 'CALL' ? 'CALL OUT' : $values['type'];
                setLogging($callrouter, $log, 0, [$type]);
            }
        } else {                                                        // do life support during idle
            // check if socket is still alive
            $socketStatus = stream_get_meta_data($fbSocket);            // get current staus of socket
            if ($socketStatus['eof']) {                                 // socket died
                $fbSocket = $callrouter->getSocket();                   // refresh socket
                $nextRefresh = time() + TWOHRS;
                setLogging($callrouter, $log, 0, ['Status: Dead Socket refreshed']);
            } elseif (time() > $nextRefresh) {
                $fbSocket = $callrouter->getSocket();                   // refresh socket
                $nextRefresh = time() + TWOHRS;
                setLogging($callrouter, $log, 0, ['Status: Regular Socket refresh']);
            }
            // refresh whitelist if necessary
            if (time() > $nextUpdate) {
                $callrouter->refreshClient();
                $callrouter->getCurrentData((int)$whitelist);
                $nextUpdate = $callrouter->getLastUpdate() + $elapse;
                $time = date('d.m.Y H:i:s', $nextUpdate);
                setLogging($callrouter, $log, 8, [$time]);
            }
        }
    }
}

/**
 * set logging
 *
 * @param Callrouter $object
 * @param bool $log
 * @param int $stringID
 * @param array $infos
 */
function setLogging(callrouter $object, bool $log = false, int $stringID = null, array $infos = [])
{
    if ($log) {
        switch ($stringID) {
            case 0:
                $message = $infos[0];
                break;

            case 1:
                $message = sprintf('Initialization: phonebook (whitelist) loaded; next refresh: %s',
                                    $infos[0]);
                break;

            case 2:
                $message = sprintf('CALL IN from number %s to MSN %s', $infos[0], $infos[1]);
                break;

            case 3:
                $message = sprintf('Number %s found in phonebook #%s', $infos[0], $infos[1]);
                break;

            case 4:
                $message = sprintf('Foreign number! Added to spam phonebook #%s', $infos[0]);
                break;

            case 5:
                $message = sprintf('Caller uses a nonexistent area code! Added to spam phonebook #%s', $infos[0]);
                break;

            case 6:
                $message = sprintf('Caller has a bad reputation (%s/%s)! Added to spam phonebook #%s', $infos[0], $infos[1], $infos[2]);
                break;

            case 7:
                $message = sprintf('Caller has a rating of %s and %s comments.', $infos[0], $infos[1]);
                break;

            case 8:
                $message = sprintf('Status: phonebook (whitelist) refreshed; next refresh: %s', $infos[0]);
                break;

            }
        $object->writeLogging($message);
    }
}
