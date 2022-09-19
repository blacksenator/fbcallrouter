<?php

namespace blacksenator;

use blacksenator\callrouter\callrouter;

/**
 * main function
 *
 * @param array $config
 * @param array $testNumbers
 * @return void
 */
function callRouter(array $config, array $testNumbers = [])
{
    // initialization
    date_default_timezone_set("Europe/Berlin");
    $phonebook = $config['phonebook'];
    $contact = $config['contact'];
    $blockForeign = $config['filter']['blockForeign'];
    $whitelists = $phonebook['whitelist'];
    $blacklist = $phonebook['blacklist'];
    $whiteNumbers = [];
    $blackNumbers = [];
    $testCases = count($testNumbers);
    $testCounter = 0;
    $callrouter = new callrouter($config['fritzbox'], $config['filter'], $config['logging']);
    $elapse = $callrouter->getRefreshInterval($phonebook['refresh']);       // sec to next whitelist refresh
    // load phonebooks
    foreach ($whitelists as $whitelist) {
        if ($callrouter->phonebookExists($whitelist)) {
            $whiteNumbers = array_merge($whiteNumbers, $callrouter->refreshPhonebook($whitelist, $elapse));
        } else {
            $message = sprintf('The phonebook #%s (whitelist) does not exist on the FRITZ!Box!', $whitelist);
            throw new \Exception($message);
        }
    }
    if ($callrouter->phonebookExists($blacklist)) {
        $blackNumbers = $callrouter->getPhoneNumbers($blacklist);
    } else {
        $message = sprintf('The phonebook #%s (blacklist) does not exist on the FRITZ!Box!', $blacklist);
        throw new \Exception($message);
    }

    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    echo 'On guard...' . PHP_EOL;
    $callrouter->setLogging(0, ['Guarding started: listen to FRITZ!Box call monitor']);
    // now listen to the callmonitor and wait for new lines
    while (true) {
        $values = $callrouter->getSocketStream();               // get the current line from port
        if ($values['type'] == 'RING') {                        // incomming call
            $number = $values['extern'];                        // caller number

            // start test case injection (if you use the -t option)
            if ($testCases > 0) {
                if ($testCounter === 0) {
                    $callrouter->setLogging(0, ['START OF TEST OPERATION']);
                } elseif ($testCounter === $testCases) {
                    $callrouter->setLogging(0, ['END OF TEST OPERATION']);
                    $testCases = 0;
                }
            }
            // injection routine
            if ($testCounter < $testCases) {                    // as long as the test case was not used
                echo sprintf('Running test case %s of %s', $testCounter + 1, $testCases) . PHP_EOL;
                // inject the next sanitized number from row
                $number = $callrouter->sanitizeNumber($testNumbers[$testCounter]);
                $testCounter++;                             // increment counter
            }
            // detect foreign numbers
            $isForeign = true ? substr($number, 0, 2) === '00' : false; // FRITZ!OS does not output '+' at callmonitor
            $realName = $contact['caller'];
            if ($contact['timestamp']) {
                $realName .= ' (' . $callrouter->getTimeStampReverse($values['timestamp']) . ')';
            }
            $callrouter->setLogging(2, [$number, $values['intern']]);
            // wash cycle 1: skip unknown
            if (empty($number)) {
                $callrouter->setLogging(0, ['Caller uses CLIR - no action possible']);
            // wash cycle 2: check if number is known (in phonebook included)
            } elseif (in_array($number, $whiteNumbers)) {
                $callrouter->setLogging(3, [$number, "Whitelist"]);
            // wash cycle 3: check if number is known (in spamlist included)
            } elseif (in_array($number, $blackNumbers)) {   // avoid duplicate entries
                $callrouter->setLogging(3, [$number, $blacklist]);
            // wash cycle 4: skip local network number (no prefix)
            } elseif (substr($number, 0, 1) != '0') {
                $callrouter->setLogging(0, ['No "0" as Perfix. No action possible']);
            // wash cycle 5: put a foreign number on blacklist if blockForeign is set
            } elseif ($isForeign && $blockForeign) {
                $blackNumbers = $callrouter->writeBlacklist($blacklist, $realName, $number, $contact['type']);
                $callrouter->setLogging(4, [$blacklist]);
            // wash cycle 6: put domestic numbers with faked area code on blacklist
            } elseif (!$isForeign && !($result = $callrouter->getArea($number))) {
                $blackNumbers = $callrouter->writeBlacklist($blacklist, $realName, $number, $contact['type']);
                $callrouter->setLogging(5, [$blacklist]);
            // wash cycle 7: put number on blacklist if area code is valid, but subscribers number start with "0"
            // but: it´s allowed for celluar numbers to start with "0"!
            } elseif ($callrouter->isCelluarCode($result['prefix'] == false) && substr($result['subscriber'], 0, 1) == '0') {
                $blackNumbers = $callrouter->writeBlacklist($blacklist, $realName, $number, $contact['type']);
                $callrouter->setLogging(9, [$blacklist]);
            // wash cycle 8
            // try to get a rating from online caller identificators
            } elseif ($result = $callrouter->getRating($number)) {
                $numberScore = $result['score'];
                $numberComments = $result['comments'];
                // if rating (score & comments) is equal or above settings put on blacklist
                if ($callrouter->proofRating($result)) {
                    $blackNumbers = $callrouter->writeBlacklist($blacklist, $realName, $number, $contact['type']);
                    $callrouter->setLogging(6, [$numberScore, $numberComments, $blacklist]);
                } else {
                    $callrouter->setLogging(7, [$numberScore, $numberComments]);;
                }
            } else {
                $callrouter->setLogging(0, ['Online request of number failed!']);
            }
        } elseif (isset($values['type'])) {
            $type = $values['type'] == 'CALL' ? 'CALL OUT' : $values['type'];
            $callrouter->setLogging(0, [$type]);
        } else {                                // do life support during idle
            // check if socket is still alive
            $socketStatus = $callrouter->refreshSocket();   // get current staus of socket and refresh
            !$socketStatus ?: $callrouter->setLogging(0, [$socketStatus]);
        }
        // refresh whitelist if necessary
        if (time() > $callrouter->getNextUpdate()) {
            $callrouter->refreshClient();
            $whiteNumbers = [];
            foreach ($whitelists as $whitelist) {
                $whiteNumbers = array_merge($whiteNumbers, $callrouter->refreshPhonebook($whitelist, $elapse));
            }
        }
    }
}
