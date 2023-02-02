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
 * Hinweis zur Längenprüfung:
 * Format der Nummern (BNetzA)
 * "Nach der Empfehlung E.164 sollen Rufnummern im internationalen Format, d. h.
 * einschließlich der Länderkennzahl aus maximal 15 Ziffern bestehen. Nationale
 * Rufnummern sollen demnach in Deutschland aus maximal 13 Ziffern bestehen.
 * Verkehrsausscheidungsziffern (Präfixe) wie die „0“ für nationale und „00“ für
 * internationale Verbindungen zählen nicht zur Rufnummer."
 * @see https://www.bundesnetzagentur.de/SharedDocs/Downloads/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/NP_Nummernraum.pdf?__blob=publicationFile&v=4
 *
 * @copyright (c) 2019 - 2023 Volker Püschel
 * @license MIT
 */

class callrouter
{
    const
        ONEDAY = 86400,                                             // seconds
        DBGSTRM = [                             // values for debugging purposes
            'timestamp' => '',                  // will be filled automatically
            'type'      => 'CALL',                              // or 'CALL'
            'conID'     => '0',                                 // not in use
            'extern'    => '05151796288',          // testnumber
            'intern'    => '0000000',                           // MSN
            'device'    => 'SIP0'                               // not in use
        ];

    private
        $contactConfig = [],
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
        $ownArea = [],                              // contains your area code
        $contactEntry = [], // contains the data for the telephone book entry
        $mailNotify = false,
        $mailText = [],                 // collector of logging info for email
        $elapse = 0,
        $nextUpdate = 0;                // timestamp for refreshing phone books

    public function __construct(array $config, array $testNumbers = [])
    {
        $this->testNumbers = $testNumbers;
        $this->testCases = count($this->testNumbers);
        $this->contactConfig = $config['contact'];
        $this->realName = $this->contactConfig['caller'];
        $this->blockForeign = $config['filter']['blockForeign'] ?? false;
        $this->phoneTools = new phonetools($config['fritzbox']);
        $this->ownArea = $this->phoneTools->getOwnAreaCode();
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
     * @param string $timeStamp
     * @return string
     */
    private function getRealName(string $timeStamp)
    {
        if ($this->contactConfig['timestamp']) {
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
            $this->nextUpdate = time() + $this->elapse;
            $this->proofListNumbers = $this->phoneTools->getPhoneBookNumbers($this->proofList);
            $listPhoneBooks = implode(', ', $this->proofList);
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
        $this->setCallMonitorValues($this->callMonitor->getSocketStream());

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
    private function setPhoneBookEntry(int $logIndex, array $additional = [])
    {
        $this->phoneTools->setPhoneBookEntry($this->contactEntry);
        $this->proofListNumbers[] = $this->contactEntry['number'];
        $this->mailNotify = true;
        $logInfo = [$this->contactEntry['name'], $this->contactEntry['phonebook']];
        if (!empty($additional)) {
            $logInfo = array_merge($logInfo, $additional);
        }

        return $this->setLogging($logIndex, $logInfo);
    }

    /**
     * set contact data for phone book entry
     *
     * @param int $phonebook
     * @param string $number
     * @param string $name (optional)
     * @return array
     */
    private function setContactEntry(int $phonebook, string $number, string $name = null)
    {
        $realName = $this->getRealName($this->callMonitorValues['timestamp']);

        $this->contactEntry = [
            'phonebook' => $phonebook,
            'name'      => $name ?? $realName,
            'number'    => $number,
            'type'      => $this->contactConfig['type'],
        ];
    }

    /**
     * checking presumably foreign numbers
     *
     * @param string $number
     * @param int $numberLength
     * @return bool
     */
    private function parseForeignNumber(string $number, int $numberLength)
    {
        $result = true;
        $countryData = [];
        if ($this->blockForeign) {
            $this->mailText[] = $this->setPhoneBookEntry(4);
        } elseif ($numberLength < 7 || $numberLength > 17) {
            // see class comment at top of file
            $this->mailText[] = $this->setPhoneBookEntry(13);
        } elseif (($countryData = $this->phoneTools->getCountry($number)) == false) {
            // unknown country code
            $this->mailText[] = $this->setPhoneBookEntry(3);
        } else {
            $this->callMonitorValues += $countryData;
            $result = false;
        }

        return $result;
    }

    /**
     * decomposition of atypically composed cellular number consists of:
     * [AREACODE][COUNTRYCODE(49)][CELLULARPREFIX][NUMBER]
     *
     * @param string $number
     * @return bool
     */
    private function getVeiledCellular(string $number)
    {
        $nationalData = [];
        $rear = '0' . substr($number, $this->ownArea['length'] + 2);
        if (
            ($nationalData = $this->phoneTools->getArea($rear)) != false
            && $this->phoneTools->isCellularCode($nationalData['prefix'])
        ){
            // adding transmitted number
            $this->mailText[] = $this->setPhoneBookEntry(14);
            // adding derived (actual) number
            $this->contactEntry['number'] = $rear;
            $this->mailText[] = $this->setPhoneBookEntry(15, [$rear]);
            return true;
        }

        return false;
    }

    /**
     * decomposition of two transmitted numbers (second one bracketed):
     * [USER_PROVIDED_NUMBER]([NETWORK_PROVIDED_NUMBER])
     * @see https://avm.de/service/wissensdatenbank/dok/FRITZ-Box-7490/1613_In-Anrufliste-werden-zwei-Rufnummern-fur-einen-Anruf-angezeigt/
     *
     * @param string $number
     * @return void
     */
    private function separateBracketedNumber(string $number)
    {
        $result = false;
        $networkProvided = substr(strstr($number, '('), 1, -1);
        $userProvided = trim(strstr($number, '(', true));
        // adding transmitted number
        if ($this->isNumberKnown($userProvided) == false) {
            $this->contactEntry['number'] = $userProvided;
            $this->mailText[] = $this->setPhoneBookEntry(16, [$userProvided]);
            $result = true;
        }
        // adding bracketed number
        if ($this->isNumberKnown($networkProvided) == false) {
            $this->contactEntry['number'] = $networkProvided;
            $this->mailText[] = $this->setPhoneBookEntry(17, [$networkProvided]);
            $result = true;
        }

        return $result;
    }

    /**
     * checking domestic numbers
     *
     * @param string $number
     * @param int $numberLength
     * @return bool
     */
    private function parseDomesticNumber(string $number, int $numberLength)
    {
        $nationalData = [];
        $result = true;
        if (substr($number, 0, 1) != '0') {
            // presumably obsolete, since the area code is always transmitted even with local calls?
            $this->setLogging(99, ['No trunk prefix (VAZ). Probably domestic call. No action possible']);
        }
        if (($nationalData = $this->phoneTools->getArea($number)) == false) {
            // unknown german area code
            $this->mailText[] = $this->setPhoneBookEntry(5);
        } elseif (
            // seldom: landline fake numbers starting with "0"; exclusiv cellular numbers
            $this->phoneTools->isCellularCode($nationalData['prefix']) == false
            && substr($nationalData['subscriber'], 0, 1) == '0'
        ) {
            $this->mailText[] = $this->setPhoneBookEntry(9);
        } elseif (preg_match('/^' . $this->ownArea['code'] . '49[1][5-7][0-9]+/', $number)) {
            // particularly observed case: [AREACODE]49[CELLUAR_NUMBER]
            $result = $this->getVeiledCellular($number);
        } elseif (preg_match('/[0][0-9]+\s?\([0][0-9]+\)$/', $number)) {
            // particularly observed case: [NUMBER]([ALTNUMBER])
            $result = $this->separateBracketedNumber($number);
        } elseif ($numberLength < 8 || $numberLength > 14) {
            // see class comment at top of file
            $this->mailText[] = $this->setPhoneBookEntry(13);
        } else {
            $result = false;
        }

        return $result;
    }

    /**
     * sarch for number in web directories
     *
     * @param string $number
     * @param bool $isForeign
     * @return void
     */
    private function webSearch(string $number, bool $isForeign)
    {
        $webResult = [];
        $checkDasOertliche = false;
        if ($webResult = $this->dialerCheck->getRating($number)) {
            $score = $webResult['score'];
            $comments = $webResult['comments'];
            if ($this->dialerCheck->proofRating($webResult)) {
                $this->mailText[] = $this->setPhoneBookEntry(6, [$score, $comments]
                );
            } else {
                $this->mailText[] = $this->setLogging(7, [$score, $comments]);
                $this->mailNotify = true;
                $isForeign ?: $checkDasOertliche = true;
            }
        } else {
            $this->mailText[] = $this->setLogging(99, ['Request in spam databases failed!']);
            $this->mailNotify = true;
            $isForeign ?: $checkDasOertliche = true;
        }
        if ($checkDasOertliche) {
            $webResult = $this->checkDasOertliche($number);
        }
        if (isset($webResult['url'])) {
            $this->setLogging(11, [$webResult['url']]);
            $this->mailText[] = $webResult['deeplink'];
            $this->mailNotify = true;
        }
    }

    /**
     * perform the validation of incomming calls ('RING')
     *
     * @return void
     */
    public function runInboundValidation()
    {
        $this->mailNotify = false;
        $isSortedOut = false;
        $number = $this->callMonitorValues['extern'];
        if ($this->isNumberKnown($number) == false) {
            $numberLength = strlen($number);
            if ($numberLength == 0) {
                $this->setLogging(99, ['Caller uses CLIR - no action possible']);
            } else {
                $this->setContactEntry($this->blackList, $number);
                $this->mailText[] = $this->setLogging(2, [$number, $this->callMonitorValues['intern']]);
                $isForeign = true ? substr($number, 0, 2) === '00' : false;
                if ($isForeign) {                   // foreign number specific
                    $isSortedOut = $this->parseForeignNumber($number, $numberLength);
                } else {                            // domestic numbers specific
                    $isSortedOut =  $this->parseDomesticNumber($number, $numberLength);
                }
            }
            if (!$isSortedOut) {
                $this->webSearch($number, $isForeign);
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
        $number = $this->callMonitorValues['extern'];
        $message = $this->setLogging(12, [$number]);
        if ($this->isNumberKnown($number) == false) {
            $this->mailNotify = false;
            $this->mailText[] = $message;
            $webResult = $this->checkDasOertliche($number);
            if (isset($webResult['url'])) {
                $this->setLogging(11, [$webResult['url']]);
                $this->mailText[] = $webResult['deeplink'];
                $this->mailNotify = true;
            }
        }
    }

    /**
     * checks if number is known in Das Örtliche public phone book
     *
     * @param string $number
     * @return array|bool
     */
    public function checkDasOertliche($number)
    {
        if (
            $this->newList >= 0
            && ($webResult = $this->dialerCheck->getDasOertliche($number))
        ) {
            $this->setContactEntry($this->newList, $number, $webResult['name']);
            $this->mailText[] = $this->setPhoneBookEntry(10);
        }

        return $webResult;
    }

    /**
     * returns if number is known in one of the phone books
     *
     * @param string $number
     * @return bool
     */
    private function isNumberKnown(string $number)
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
        $socketStatus == null ?: $this->setLogging(99, [$socketStatus]);
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
        $this->mailNotify = false;
        $this->contactEntry = [];
    }

    /**
     * setting call monitor values for debugging purposes
     *
     * @return array
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
