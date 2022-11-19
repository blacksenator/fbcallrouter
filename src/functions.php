<?php

namespace blacksenator;

use blacksenator\callrouter\callrouter;
use blacksenator\callrouter\dialercheck;
use blacksenator\callrouter\infomail;

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
    $contact = $config['contact'];
    $blockForeign = $config['filter']['blockForeign'];
    $whitelists = $config['phonebook']['whitelist'] ?? 0;
    $blacklist = $config['phonebook']['blacklist'] ?? 1;
    $newlist = $config['phonebook']['newlist'] ?? false;
    $whiteNumbers = [];
    $blackNumbers = [];
    $testCases = count($testNumbers);
    $testCounter = 0;
    $callRouter = new callrouter($config['fritzbox'], $config['logging']);
    $dialerCheck = new dialercheck($config['filter']);
    if (isset($config['email'])) {
        $infoMail = new infomail($config['email']);
    }
    $elapse = $callRouter->getRefreshInterval($config['phonebook']['refresh']);       // sec to next whitelist refresh
    // load phonebooks
    $whiteNumbers = $callRouter->refreshPhonebooks($whitelists, $elapse);
    $blackNumbers = $callRouter->getPhoneBooks([$blacklist]);
    // get socket to FRITZ!Box callmonitor port (in a case of error dial #96*5* to open it)
    echo 'On guard...' . PHP_EOL;
    $callRouter->logging(0, ['Guarding started: listen to FRITZ!Box call monitor']);
    // now listen to the callmonitor and wait for new lines
    while (true) {
        $values = $callRouter->getSocketStream();   // get the current line from port
        // debug
        $debugStream = [
            'timestamp' => date('d.m.y H:i:s'),
            'type'      => 'RING',
            'conID'     => '0',
            'extern'    => '004420',                            // testnumber
            'intern'    => '0000000',                           // MSN
            'device'    => 'SIP0'
        ];
        $values = $debugStream;
        //*/
        if ($values['type'] == 'RING') {                    // incomming call
            $mailText = [];
            $result = [];
            $checkOertliche = false;
            $number = $values['extern'];                    // caller number

            // start test case injection (if you use the -t option)
            if ($testCases > 0) {
                if ($testCounter === 0) {
                    $callRouter->logging(0, ['START OF TEST OPERATION']);
                } elseif ($testCounter === $testCases) {
                    $callRouter->logging(0, ['END OF TEST OPERATION']);
                    $testCases = 0;
                }
            }
            // injection routine
            if ($testCounter < $testCases) {                    // as long as the test case was not used
                echo sprintf('Running test case %s of %s', $testCounter + 1, $testCases) . PHP_EOL;
                // inject the next sanitized number from row
                $number = $callRouter->sanitizeNumber($testNumbers[$testCounter]);
                $testCounter++;                             // increment counter
            }
            // detect foreign numbers
            $isForeign = true ? substr($number, 0, 2) === '00' : false; // FRITZ!OS does not output '+' at callmonitor
            $realName = $contact['caller'];
            if ($contact['timestamp']) {
                $realName .= ' (' . $callRouter->getTimeStampReverse($values['timestamp']) . ')';
            }
            $mailText[] = $callRouter->logging(2, [$number, $values['intern']]);
            // wash cycle 1: skip unknown
            if (empty($number)) {
                $callRouter->logging(0, ['Caller uses CLIR - no action possible']);
            // wash cycle 2: check if number is known (in phonebook included)
            } elseif (in_array($number, $whiteNumbers)) {
                $callRouter->logging(3, [$number, "Whitelist"]);
            // wash cycle 3: check if number is known (in spamlist included)
            } elseif (in_array($number, $blackNumbers)) {   // avoid duplicate entries
                $callRouter->logging(3, [$number, $blacklist]);
            // wash cycle 4: skip local network number (no prefix)
            } elseif (substr($number, 0, 1) != '0') {
                $mailText[] = $callRouter->logging(0, ['No "0" as Perfix. No action possible']);
            // wash cycle 5: put a foreign number on blacklist if blockForeign is set
            } elseif ($isForeign && $blockForeign) {
                $callRouter->setPhoneBookEntry($blacklist, $realName, $number, $contact['type']);
                $blackNumbers[] = $number;
                $mailText[] = $callRouter->logging(4, [$blacklist]);
            // wash cycle 6: put domestic numbers with faked area code on blacklist
            } elseif (
                !$isForeign
                && !($result = $callRouter->getArea($number))
            ) {
                $callRouter->setPhoneBookEntry($blacklist, $realName, $number, $contact['type']);
                $blackNumbers[] = $number;
                $mailText[] = $callRouter->logging(5, [$blacklist]);
            // wash cycle 7: put number on blacklist if area code is valid, but subscribers number start with "0"
            // but: it´s allowed for cellular numbers to start with "0"!
            } elseif (
                !$isForeign
                && $result = $callRouter->getArea($number)
                && ($callRouter->isCellularCode($result['prefix']) == false)
                && (substr($result['subscriber'], 0, 1) == '0')
            ) {
                $callRouter->setPhoneBookEntry($blacklist, $realName, $number, $contact['type']);
                $blackNumbers[] = $number;
                $mailText[] = $callRouter->logging(9, [$blacklist]);
            // wash cycle 8
            // try to get a rating from online caller identificators
            } elseif ($result = $dialerCheck->getRating($number)) {
                if ($dialerCheck->proofRating($result)) {
                    $callRouter->setPhoneBookEntry($blacklist, $realName, $number, $contact['type']);
                    $blackNumbers[] = $number;
                    $mailText[] = $callRouter->logging(6, [$result['score'], $result['comments'], $blacklist]);
                } else {
                    $mailText[] = $callRouter->logging(7, [$result['score'], $result['comments']]);
                    $checkOertliche = true;
                }
            } else {
                $mailText[] = $callRouter->logging(0, ['Online request of number failed!']);
                $checkOertliche = true;
            }
            // at least try to figure out if the number is listed in a public telephone book
            if (
                $checkOertliche == true
                && $result = $dialerCheck->getDasOertliche($number)
            ){
                $callRouter->setPhoneBookEntry($newlist, $result['name'], $number, $contact['type']);
                $whiteNumbers[] = $number;
                $mailText[] = $callRouter->logging(10, [$result['name'], $newlist]);
            }
            if (isset($result['url'])) {
                $mailText[] = $callRouter->logging(11, [$result['url']]);
            }
            if (isset($config['email']) && count($mailText) > 1) {
                $msg = $infoMail->sendMail($number, $mailText);
                if ($msg <> null) {
                    $callRouter->logging(0, [$msg]);
                }
            }
        } elseif (isset($values['type'])) {
            $type = $values['type'] == 'CALL' ? 'CALL OUT' : $values['type'];
            $callRouter->logging(0, [$type]);
        } else {                                // do life support during idle
            // check if socket is still alive
            $socketStatus = $callRouter->refreshSocket();   // get current staus of socket and refresh
            !$socketStatus ?: $callRouter->logging(0, [$socketStatus]);
        }
        // refresh whitelist if necessary
        if (time() > $callRouter->getNextUpdate()) {
            $callRouter->refreshClient();
            $whiteNumbers = [];
            foreach ($whitelists as $whitelist) {
                $whiteNumbers = array_merge($whiteNumbers, $callRouter->refreshPhonebooks($whitelist, $elapse));
            }
        }
    }
}
