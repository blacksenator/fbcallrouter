<?php

namespace blacksenator\callrouter;

/** class phone
 *
 * Copyright (c) 2019 - 2022 Volker Püschel
 * @license MIT
 */

use blacksenator\fritzsoap\x_contact;

class phonetools
{
    const ONB_SOURCE = '/assets/ONB.csv';   // path to file with official area codes
    const CELLULAR = 'assets/cellular.php';
    const DELIMITER = ';';                              // delimiter of ONB.csv

    private $fritzSoap;                                 // SOAP client
    private $prefixes = [];         // area codes incl. mobile codes ($cellular)
    private $cellular = [];
    private $phonebookList = [];

    /**
     * @param array $config
     * @return void
     */
    public function __construct(array $fritzBox)
    {
        $this->fritzSoap = new x_contact($fritzBox['url'], $fritzBox['user'], $fritzBox['password']);
        $this->fritzSoap->getClient();
        $this->phonebookList = explode(',', $this->fritzSoap->getPhonebookList());
        $this->getPhoneCodes();
    }

    /**
     * returns URL data
     *
     * @return array
     */
    public function getURL()
    {
        return $this->fritzSoap->getURL();
    }

    /**
     *
     */
    public function getPhoneBooks(array $phoneBooks)
    {
        $numbers = [];
        foreach ($phoneBooks as $phoneBook) {
            if ($this->phonebookExists($phoneBook)) {
                $numbers = array_merge($numbers, $this->getPhoneNumbers($phoneBook));
            } else {
                $message = sprintf('The phonebook #%s does not exist on the FRITZ!Box!', $phoneBook);
                throw new \Exception($message);
            }
        }

        return $numbers;
    }

    /**
     * get a fresh client with new SID
     *
     * return void
     */
    public function refreshClient()
    {
        $this->fritzSoap->getClient();
    }

    /**
     * get numbers from phonebook
     *
     * @param int $phonebookID
     * @return array
     */
    private function getPhoneNumbers(int $phonebookID = 0)
    {
        $phoneBook = $this->fritzSoap->getPhonebook($phonebookID);
        if ($phoneBook == false) {
            return [];
        }

        return $this->fritzSoap->getListOfPhoneNumbers($phoneBook);
    }

    /**
     * set new entry in blacklist
     *
     * @param int $phonebook
     * @param string $name
     * @param string $number
     * @param string $type
     * @return array
     */
    public function setPhoneBookEntry(int $phonebook, string $name, string $number, string $type)
    {
        $this->fritzSoap->getClient();
        $this->fritzSoap->setContact($phonebook, $name, $number, $type);

        return $this->getPhoneNumbers($phonebook);
    }

    /**
     * return true if phonebook exist on FRITZ!Box
     *
     * @param int $phonebook
     * @return bool
     */
    public function phonebookExists($phonebook)
    {
        return in_array($phonebook, $this->phonebookList);
    }

    /**
     * sanitize an phone number string
     *
     * @param string $number
     * @return string $number
     */
    public function sanitizeNumber(string $number): string
    {
        if (substr(trim($number), 0, 1) === '+') {  // if number starts with "+" (foreign)
            $number = '00' . substr($number, 1);    // it will be replaced with 00
        }

        return preg_replace("/[^0-9]/", '', $number);           // only digits
    }

    /**
     * get an array, where the area code (ONB) is key and area name is value
     * ONB stands for OrtsNetzBereich(e)
     *
     * @return array
     */
    private function getAreaCodes()
    {
        if (!$onbData = file(dirname(__DIR__, 2) . self::ONB_SOURCE)) {
            throw new \exception('Could not read ONB data from local file!');
        }
        $areaCodes = [];
        end($onbData) != "\x1a" ?: array_pop($onbData);    // usually file comes with this char at eof
        $rows = array_map(function($row) { return str_getcsv($row, self::DELIMITER); }, $onbData);
        array_shift($rows);                                     // delete header
        foreach($rows as $row) {
            ($row[2] == 0) ?: $areaCodes[$row[0]] = $row[1];    // only use active ONBs ("1")
        }

        return $areaCodes;
    }

    /**
     * returns an array with area codes and codes of mobile providers: from
     * longest and highest key [39999] to the shortest [30]
     *
     * @return array
     */
    private function getPhoneCodes()
    {
        require_once (self::CELLULAR);

        $this->cellular = $cellularNumbers;       // we need them also seperatly
        $this->prefixes = $this->getAreaCodes() + $this->cellular;
        krsort($this->prefixes, SORT_NUMERIC);
    }

    /**
     * returns the prefixes
     *
     * @return array $prefixes
     */
    public function getPrefixes()
    {
        return $this->prefixes;
    }

    /**
     * return the area code and subscribers number from a phone number:
     * [0] => area code
     * [1] => area name (NDC = national destination code)
     * [2] => subscribers number (base number plus direct dial in)
     *
     * Germany currently has around 5200 area codes, length between two and five
     * digits (plus zero).
     *
     * @param string $phoneNumber to extract the area code from
     * @return array|bool $area code data or false
     */
    public function getArea(string $phoneNumber)
    {
        for ($i = 5; $i > 1; $i--) {
            $needle = substr($phoneNumber, 1, $i);
            if (isset($this->prefixes[$needle])) {
                return [
                    'prefix'      => $needle,
                    'designation' => $this->prefixes[$needle],
                    'subscriber'  => substr($phoneNumber, 1 + $i),
                ];
            }
        }

        return false;
    }

    /**
     * returns true if prefix is a cellular code
     *
     * @param string $prefix
     * @return bool
     */
    public function isCellularCode($prefix)
    {
        return isset($this->cellular[$prefix]);
    }
}
