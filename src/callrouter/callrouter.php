<?php

namespace blacksenator\callrouter;

use blacksenator\callrouter\callmonitor;
use blacksenator\callrouter\phonetools;
use blacksenator\callrouter\dialercheck;
use blacksenator\callrouter\logging;
use blacksenator\callrouter\infomail;

/** class callrouter
 *
 * central class to use the functional different classes in combination
 *
 * @copyright (c) 2019 - 2022 Volker Püschel
 * @license MIT
 */

class callrouter
{
    const
        ONEDAY = 86400,                                             // seconds
        DBGSTRM = [                             // values for debugging purposes
            'timestamp' => '',                  // will be filled automatically
            'type'      => 'RING',                              // or 'CALL'
            'conID'     => '0',                                 // not in use
            'extern'    => '0821471487',                       // testnumber
            'intern'    => '0000000',                           // MSN
            'device'    => 'SIP0'                               // not in use
        ];

    private
        $contact = [],
        $realName = '',
        $blockForeign,
        $proofList = [],    // list of all telephone books to be checked against
        $blackList,                                 // index of spam phone book
        $newList,           // index of phone book for valid numbers (optional)
        $proofListNumbers = [],                             // all known numbers
        $testNumbers = [],                                  // test case numbers
        $testCases = 0,                                 // number of test cases
        $testCounter = 0,
        $callMonitor,                                       // class instance
        $callMonitorValues = [],                    // keep call monitor values
        $phoneTools,                                        // class instance
        $dialerCheck,                                       // class instance
        $logging,                                           // class instance
        $infoMail = null,                                   // class instance
        $mailNotify = false,
        $mailText = [],                 // collector of logging info for email
        $elapse = 0,
        $nextUpdate = 0;                // timestamp for refreshing phone books

    public function __construct(array $config, array $testNumbers = [])
    {
        $this->testNumbers = $testNumbers;
        $this->testCases = count($this->testNumbers);
        $this->contact = $config['contact'];
        $this->realName = $this->contact['caller'];
        $this->blockForeign = $config['filter']['blockForeign'] ?? false;
        $this->phoneTools = new phonetools($config['fritzbox']);
        $this->setPhoneBooks($config['phonebook']);
        $this->logging = new logging($config['logging']);
        $this->refreshPhoneBooks();                             // initial load
        $this->callMonitor = new callmonitor($this->phoneTools->getURL());
        $this->dialerCheck = new dialercheck($config['filter']);
        if (isset($config['email'])) {
            $this->infoMail = new infomail($config['email']);
        }
        echo 'On guard...' . PHP_EOL;
        $this->setLogging(0, [$this->callMonitor->getSocketAdress()]);
    }

    /**
     * set phone books
     *
     * @param array $config
     * @return void
     */
    private function setPhoneBooks(array $config)
    {
        $this->proofList = $config['whitelist'] ?? [0];
        $this->blackList = $config['blacklist'] ?? 1;
        $this->proofList[] = $this->blackList;
        $this->newList = $config['newlist'] ?? -1;
        if ($this->newList >= 0) {
            $this->proofList[] = $this->newList;
        }
        $this->phoneTools->checkListOfPhoneBooks($this->proofList);
        $refresh = $config['refresh'] ?? 1;
        $this->elapse = $refresh < 1 ? self::ONEDAY : $refresh * self::ONEDAY;
    }

    /**
     * set logging text
     *
     * @param int $stringID
     * @param array $infos
     * @return string
     */
    public function setLogging(int $stringID, array $infos)
    {
        return $this->logging->setLogging($stringID, $infos);
    }

    /**
     * returns rearranged timestamp data
     *
     * @param string $timestamp                             // dd.mm.yy hh:mm:ss
     * @return string                                   // yyyy.mm.dd hh:mm:ss
     */
    private function getTimeStampReverse(string $timeStamp)
    {
        $parts = explode(' ', $timeStamp);
        $date = explode('.', $parts[0]);

        return '20' . $date[2] . '.' . $date[1] . '.' . $date[0] . ' ' . $parts[1];
    }

    /**
     * get real name
     *
     * @param string $timeStamo
     * @return string
     */
    private function getRealName(string $timeStamp)
    {
        if ($this->contact['timestamp']) {
            return $this->realName .  ' (' . $this->getTimeStampReverse($timeStamp) . ')';
        } else {
            return $this->realName;
        }
    }

    /**
     * returns reread phone book numbers
     *
     * @return void
     */
    public function refreshPhoneBooks()
    {
        if (time() > $this->nextUpdate) {
            $this->proofListNumbers = $this->phoneTools->getPhoneBookNumbers($this->proofList);
            $listPhoneBooks = implode(', ', $this->proofList);
            $this->nextUpdate = time() + $this->elapse;
            date_default_timezone_set('Europe/Berlin');
            $this->setLogging(1, [$listPhoneBooks, date('d.m.Y H:i:s', $this->nextUpdate)]);
        }
    }

    /**
     * getting the array from the callmonitor socket stream
     *
     * @return array $this->callMonitorValues
     */
    public function getCallMonitorStream()
    {
        $this->mailText = [];
        if (empty($this->callMonitorValues)) {
            $this->setCallMonitorValues($this->callMonitor->getSocketStream());
        }

        return $this->callMonitorValues;
    }

    /**
     * set call monitor values
     *
     * @return void
     */
    public function setCallMonitorValues(array $values = [])
    {
        $this->callMonitorValues = $values;
    }

    /**
     * set entry in phone book
     *
     * @param array $entry
     * @param int $logIndex
     * @return string
     */
    private function setPhoneBookEntry(array $entry, int $logIndex)
    {
        $this->phoneTools->setPhoneBookEntry($entry);
        $this->proofListNumbers[] = $entry['number'];
        $this->mailNotify = true;

        return $this->setLogging($logIndex, [$entry['name'], $entry['phonebook']]);
    }

    /**
     * set phone book entry
     *
     * @param int $phonebook
     * @param string $number
     * @param string $name (optional)
     * @return array
     */
    private function setContactEntry(int $phonebook, string $number, string $name = null)
    {
        $realName = $this->getRealName($this->callMonitorValues['timestamp']);

        return [
            'phonebook' => $phonebook,
            'name'      => $name ?? $realName,
            'number'    => $number,
            'type'      => $this->contact['type'],
        ];
    }

    /**
     * perform the validation of incomming calls ('RING')
     *
     * @return void
     */
    public function runInboundValidation()
    {
        $this->mailNotify = false;
        $number = $this->callMonitorValues['extern'];
        if (!$this->isNumberKnown($number)) {
            if (empty($number)) {
                $this->setLogging(99, ['Caller uses CLIR - no action possible']);
            } else {
                $checkDasOertliche = false;
                $msn = $this->callMonitorValues['intern'];
                $this->mailText[] = $this->setLogging(2, [$number, $msn]);
                $isForeign = true ? substr($number, 0, 2) === '00' : false;
                // wash cycle 1: skip local network number (no prefix)
                if (substr($number, 0, 1) != '0') {
                    $this->setLogging(99, ['No trunk prefix (VAZ). Probably domestic call. No action possible']);
                // wash cycle 2: put a foreign number on blacklist if blockForeign is set
                } elseif ($isForeign && $this->blockForeign) {
                    $phoneBookEntry = $this->setContactEntry($this->blackList, $number);
                    $this->mailText[] = $this->setPhoneBookEntry($phoneBookEntry, 4);
                // wash cycle 3: put domestic numbers with faked area code on blacklist
                } elseif (
                    !$isForeign
                    && !$this->phoneTools->getArea($number)
                ) {
                    $phoneBookEntry = $this->setContactEntry($this->blackList, $number);
                    $this->mailText[] = $this->setPhoneBookEntry($phoneBookEntry, 5);
                /* wash cycle 4: put number on blacklist if area code is valid, and
                * subscribers number start with "0"
                * but: it´s allowed for cellular numbers to start with "0"! */
                } elseif (
                    !$isForeign
                    && ($result = $this->phoneTools->getArea($number))
                    && ($this->phoneTools->isCellularCode($result['prefix']) == false)
                    && (substr($result['subscriber'], 0, 1) == '0')
                ) {
                    $phoneBookEntry = $this->setContactEntry($this->blackList, $number);
                    $this->mailText[] = $this->setPhoneBookEntry($phoneBookEntry, 9);
                // wash cycle 5
                // try to get a rating from online caller identificators
                } elseif ($result = $this->dialerCheck->getRating($number)) {
                    if ($this->dialerCheck->proofRating($result)) {
                        $phoneBookEntry = $this->setContactEntry($this->blackList, $number);
                        $this->phoneTools->setPhoneBookEntry($phoneBookEntry);
                        $this->proofListNumbers[] = $number;
                        $this->mailText[] = $this->setLogging(
                            6,
                            [$result['score'], $result['comments'],
                            $this->blackList]
                        );
                    } else {
                        $this->mailText[] = $this->setLogging(
                            7,
                            [$result['score'], $result['comments']]
                        );
                        $this->mailNotify = true;
                        $isForeign ?: $checkDasOertliche = true;
                    }
                } else {
                    $this->mailText[] = $this->setLogging(99, ['Request in spam databases failed!']);
                    $this->mailNotify = true;
                    $isForeign ?: $checkDasOertliche = true;
                }
                if ($checkDasOertliche) {
                    $result = $this->checkDasOertliche();
                }
                if (isset($result['url'])) {
                    $this->setLogging(11, [$result['url']]);
                    $this->mailText[] = $result['deeplink'];
                    $this->mailNotify = true;
                }
            }
        }
    }

    /**
     * perform the validation of out going calls ('CALL')
     *
     * @return void
     */
    public function runOutboundValidation()
    {
        $this->mailNotify = false;
        $this->mailText[] = $this->setLogging(12, [$this->callMonitorValues['extern']]);
        $result = $this->checkDasOertliche();
        if (isset($result['url'])) {
            $this->setLogging(11, [$result['url']]);
            $this->mailText[] = $result['deeplink'];
            $this->mailNotify = true;
        }
    }

    /**
     * checks if number is known in Das Örtliche public phone book
     *
     * @return array|bool
     */
    public function checkDasOertliche()
    {
        $number = $this->callMonitorValues['extern'];
        if (
            $this->newList >= 0
            && ($result = $this->dialerCheck->getDasOertliche($number))
        ) {
            $phoneBookEntry = $this->setContactEntry(
                $this->newList,
                $number,
                $result['name']
            );
            $this->mailText[] = $this->setPhoneBookEntry($phoneBookEntry, 10);
        }

        return $result;
    }

    /**
     * returns if number is already known in one of the phone books
     *
     * @return bool
     */
    public function isNumberKnown($number)
    {
        return in_array($number, $this->proofListNumbers);
    }

    /**
     * get current staus of socket and refresh
     *
     * @return void
     */
    public function refreshSocket()
    {
        $socketStatus = $this->callMonitor->refreshSocket();
        $socketStatus == null ?: $this->callRouter->setLogging(99, [$socketStatus]);
    }

    /**
     * send email
     *
     * @return void
     */
    public function sendMail()
    {
        if (isset($this->infoMail) && $this->mailNotify) {
            $msg = $this->infoMail->sendMail($this->callMonitorValues['extern'], $this->mailText);
            if ($msg <> null) {
                $this->setLogging(99, [$msg]);
            }
        }
    }

    /**
     * setting call monitor values for debugging purposes
     *
     * @return void
     */
    public function setDebugStream()
    {
        $this->callMonitorValues = self::DBGSTRM;
        $this->callMonitorValues['timestamp'] = date('d.m.y H:i:s');

        return $this->callMonitorValues;
    }

    /**
     * set one of n test numbers in sequence, as specified in the configuration
     * when executed with the "-t" parameter
     *
     * @return void
     */
    public function getTestInjection()
    {
        if ($this->testCases > 0) {
            if ($this->testCounter === 0) {
                $this->setLogging(99, ['START OF TEST OPERATION']);
            } elseif ($this->testCounter === $this->testCases) {
                $this->setLogging(99, ['END OF TEST OPERATION']);
                $this->testCases = 0;
            }
        }
        if ($this->testCounter < $this->testCases) {    // as long as the test case was not used
            echo sprintf('Running test case %s of %s', $this->testCounter + 1, $this->testCases) . PHP_EOL;
            // inject the next sanitized number from row
            $this->callMonitorValues['extern'] = $this->phoneTools->sanitizeNumber($this->testNumbers[$this->testCounter]);
            $this->testCounter++;
        }
    }
}
