<?php

namespace blacksenator\callrouter;

/** class phonetools
 *
 * Provides all phone book and phone number related functions for fbcallrouter
 * A necessary source is "Vorwahlverzeichnis (VwV)" a zipped CSV from BNetzA
 * @see: https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/ON_Einteilung_ONB/ON_ONB_ONKz_ONBGrenzen_Basepage.html
 *
 * It is advisable to consult the above address from time to time to check if
 * there are any changes. If so: download the ZIP-file and save the unpacked
 * file as "ONB.csv" in ./assets
 *
 * @copyright (c) 2019 - 2024 Volker Püschel
 * @license MIT
 */

use blacksenator\fritzsoap\x_contact;
use blacksenator\fritzsoap\x_voip;

class phonetools
{
    const
        ONB_SOURCE = '/assets/ONB.csv',   // path to file with official area codes
        CELLULAR   = 'assets/cellular.php',
        COUNTRYCDS = 'assets/countrycodes.php',
        DELIMITER  = ';',                               // delimiter of ONB.csv
        SRVCSNMBR  = [                              // all incomming numbers?
        /*  '12'  => 'neuartige Dienste',
            '137' => 'Televoting, Gewinnspiel',
            '138' => 'Televoting, Gewinnspiel',
            '164' => 'Pager',
            '168' => 'Cityruf/Pager',
            '169' => 'Cityruf/Pager',
            '18'  => 'Service-Dienste',
            '190' => 'Mehrwertdienste', */
            '3212' => 'nationale Teilnehmernummer',
            '3221' => 'nationale Teilnehmernummer',
            '3222' => 'nationale Teilnehmernummer',
            '700' => 'persönliche Rufnummer',
            '800' => 'Mehrwertdienste',
        /*  '900' => 'Mehrwertdienste',
            '902' => 'Televoting, Gewinnspiel', */
        ];

    private
        $fritzContact,                                          // SOAP client
        $fritzVoIP,                                             // SOAP client
        $prefixes = [],             // area codes incl. mobile codes ($cellular)
        $cellular = [],                                     // cellular prefixes
        $countryCodes = [],                                     // country codes
        $ownAreaCode,
        $fritzBoxPhoneBooks = [];

    /**
     * @param array $config
     * @return void
     */
    public function __construct(array $fritzBox)
    {
        $this->fritzContact = new x_contact($fritzBox['url'], $fritzBox['user'], $fritzBox['password']);
        $this->fritzBoxPhoneBooks = $this->getPhonebookList();
        $this->fritzVoIP = new x_voip($fritzBox['url'], $fritzBox['user'], $fritzBox['password']);
        $this->ownAreaCode = $this->fritzVoIP->getVoIPCommonAreaCode();
        $this->getPhoneCodes();
        $this->getCountryCodes();
    }

    /**
     * returns list of phone books
     *
     * @return array
     */
    public function getPhoneBookList()
    {
        return explode(',', $this->fritzContact->getPhonebookList());
    }

    /**
     * returns URL data
     *
     * @return array
     */
    public function getURL()
    {
        return $this->fritzContact->getURL();
    }

    /**
     * get a fresh x_contact client with new SID
     *
     * @return void
     */
    public function refreshContactClient()
    {
        $this->fritzContact->getClient();
    }

    /**
     * returns area code from FRITZ!Box settings
     *
     * @return array
     */
    public function getOwnAreaCode()
    {
        return [
            'code'   => $this->ownAreaCode,
            'length' => strlen($this->ownAreaCode)
        ];
    }

    /**
     * checks phone book indices against list of phone books from FRITZ!Box
     *
     * @param array $phoneBooks to proof
     * @return void
     */
    public function checkListOfPhoneBooks(array $phoneBooks)
    {
        $message = '';
        foreach ($phoneBooks as $phoneBook) {
            if (!in_array($phoneBook, $this->fritzBoxPhoneBooks)) {
                $message = sprintf('Could not find phone book #%s on FRITZ!Box!', $phoneBook);
            }
        }
        if (!empty($message)) {
            throw new \Exception($message);
        }
    }

    /**
     * get numbers from a phone book
     *
     * @param int $phonebookID
     * @return array
     */
    public function getPhoneNumbers(int $phonebookID = 0)
    {
        $phoneBook = $this->fritzContact->getPhonebook($phonebookID);
        if ($phoneBook == false) {
            return [];
        }

        return $this->fritzContact->getListOfPhoneNumbers($phoneBook);
    }

    /**
     * set new entry in phonebook
     *
     * @param array $entry
     * @return void
     */
    public function setPhoneBookEntry(array $entry)
    {
        $this->fritzContact->getClient();
        $this->fritzContact->setContact(
            $entry['phonebook'],
            $entry['name'],
            $entry['number'],
            $entry['type']
        );
    }

    /**
     * Clean up test number string to ensure no characters other than those are
     * used by the call monitor
     *
     * @param string $number
     * @return string $number
     */
    public function sanitizeNumber(string $number): string
    {
        return $this->fritzContact->sanitizeNumber($number);
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
     * sets the arrays with area codes and codes of mobile providers: from
     * longest and highest key [39999] to the shortest [30]
     *
     * @return void
     */
    private function getPhoneCodes()
    {
        require_once (self::CELLULAR);

        $this->cellular = $cellularNumbers;       // we need them also seperatly
        $this->prefixes = $this->getAreaCodes() + $this->cellular + self::SRVCSNMBR;
        krsort($this->prefixes, SORT_NUMERIC);
    }

    /**
     * sets the array with country codes: from longest and highest key [7979]
     * (Russia) to the shortest [1] (USA)
     *
     * @return void
     */
    private function getCountryCodes()
    {
        require_once (self::COUNTRYCDS);

        $this->countryCodes = $countryCodes;
        krsort($this->countryCodes, SORT_NUMERIC);
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
     * ['prefix']      => area code
     * ['designation'] => area name (~NDC: national destination code)
     * ['subscriber']  => subscribers number (base number plus direct dial in)
     *
     * Germany currently has around 5200 area codes, length between two and five
     * digits (except the leading zero).
     * @see https://de.wikipedia.org/wiki/Rufnummer#/media/Datei:Telefonnummernaufbau.png
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
     * return the country code and country from a phone number:
     * ['countrycode']  => country code
     * ['country']      => country name
     * ['national']     => NSN: national significant number
     * @see https://de.wikipedia.org/wiki/Rufnummer#/media/Datei:Telefonnummernaufbau.png
     *
     * @param string $phoneNumber to identify the country code from
     * @return array|bool $country code data or false
     */
    public function getCountry(string $phoneNumber)
    {
        for ($i = 4; $i > 0; $i--) {
            $needle = substr($phoneNumber, 2, $i);
            if (isset($this->countryCodes[$needle])) {
                $countryCode = '00' . $needle;
                if ($this->countryCodes[$needle] == 'Kanada') {
                    $countryCode = '001';
                } elseif (substr($needle, 0, 1) == '7') {
                    $countryCode = '007';
                }
                return [
                    'countrycode' => $countryCode,
                    'country'     => $this->countryCodes[$needle],
                    'national'    => substr($phoneNumber, strlen($countryCode)),
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
