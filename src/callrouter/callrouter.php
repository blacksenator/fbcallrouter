<?php

namespace blacksenator\callrouter;

/* class callrouter
 *
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

use blacksenator\fritzsoap\fritzsoap;

class callrouter
{
    const CALLMONITORPORT = '1012';     // FRITZ!Box port for callmonitor
    const DELIMITER = ';';
    const CELLUAR = [                   // an array of celluar network codes (RNB) according to the list of ONB
                '151' => 'Telekom',     // source from: BNetzA at https://tinyurl.com/y7648pc9
                '1511' => 'Telekom',
                '1512' => 'Telekom',
                '1514' => 'Telekom',
                '1515' => 'Telekom',
                '1516' => 'Telekom',
                '1517' => 'Telekom',
                '152' => 'Vodafone',
                '1520' => 'Vodafone',
                '1521' => 'Vodafone/Lyca',
                '1522' => 'Vodafone',
                '1523' => 'Vodafone',
                '1525' => 'Vodafone',
                '1526' => 'Vodafone',
                '1529' => 'Vodafone/Truphone',
                '15566' => 'Drillisch',
                '16630' => 'Argon',
                '157' => 'E-Plus',
                '1570' => 'Telefónica',
                '1573' => 'Telefónica',
                '1575' => 'Telefónica',
                '1577' => 'Telefónica',
                '1578' => 'Telefónica',
                '1579' => 'Telefónica/SipGate',
                '15888' => 'TelcoVillage',
                '159' => 'Telefónica',
                '1590' => 'Telefónica',
                '160' => 'Telekom',
                '162' => 'Vodafone',
                '163' => 'Telefónica',
                '170' => 'Telekom',
                '171' => 'Telekom',
                '172' => 'Vodafone',
                '173' => 'Vodafone',
                '174' => 'Vodafone',
                '175' => 'Telekom',
                '176' => 'Telefónica',
                '177' => 'Telefónica',
                '178' => 'Telefónica',
                '179' => 'Telefónica',
    ];

    public $currentNumbers = [];
    public $lastupdate;

    private $fritzbox;
    private $url;
    private $areaCodes = [];
    private $loggingPath;

    public function __construct($config)
    {
        $this->fritzbox = new fritzsoap($config['url'], $config['user'], $config['password']);
        $this->url = $this->fritzbox->getURL();
        $this->fritzbox->getClient('x_contact', 'X_AVM-DE_OnTel:1');
        $this->lastupdate = time();
        $this->getAreaCodes();
        $this->loggingPath = isset($config['logging']) ? $config['logging'] : null;
    }

    /**
     * get the FRITZ!Box callmonitor socket
     *
     * @return stream|bool $socket
     */
    public function getSocket()
    {
        $adress = $this->url['host'] . ':' . SELF::CALLMONITORPORT;
        $socket = stream_socket_client($adress, $errno, $errstr);
            if (!$socket) {
                error_log(sprintf("Could not listen to callmonitor! Error: %s (%s)!", $errstr, $errno));
                return;
            }

        return $socket;
    }

    /**
     * get current data from FRITZ!Box
     */
    function getCurrentData($phonebookID)
    {
        $phoneBook = $this->fritzbox->getPhonebook($phonebookID);
            $numbers = $this->getNumbers($phoneBook);
            if (count($numbers) == 0) {
                echo 'The phone book against which you want to check is empty!' . PHP_EOL;
            } else {
                $this->lastupdate = time();
                $this->currentNumbers = $numbers;
            }
    }

    /**
     * delivers an simple array of numbers from a designated phone book
     *
     * @param SimpleXMLElement $phoneBook downloaded phone book
     * @param array $types phonetypes (e.g. home, work, mobil, fax, fax_work)
     * @return array phone numbers
     */
    private function getNumbers($phoneBook, $types = [])
    {
        foreach ($phoneBook->phonebook->contact as $contact) {
            foreach ($contact->telephony->number as $number) {
                if ((substr($number, 0, 1) == '*') || (substr($number, 0, 1) == '#')) {
                    continue;
                }
                if (count($types)) {
                    if (in_array($number['type'], $types)) {
                        $number = $number[0]->__toString();
                    } else {
                        continue;
                    }
                } else {
                    $number = $number[0]->__toString();
                }
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * get a simple array, where the area code (ONB) is key and area name is value
     * ONB stands for OrtsNetzBereich(e)
     * source is "Vorwahlverzeichnis (VwV)" a zipped CSV from BNetzA at https://tinyurl.com/y4umk5ww
     * if you want to update this: save the unpacked file as "ONB.csv" in ./assets
     *
     * @return void
     */
    private function getAreaCodes()
    {
        $areaCodes = [];
        $rows = array_map(function($row) { return str_getcsv($row, SELF::DELIMITER); }, file('./assets/ONB.csv'));
        array_shift($rows);                                             // delete header
        foreach($rows as $row) {
            if ($row[2] == 1) {                                         // only active ONBs ("1")
                $areaCodes[$row[0]] = $row[1];
            }
        }
        $this->areaCodes = $areaCodes + SELF::CELLUAR;                  // adding celluar network codes
        krsort($this->areaCodes, SORT_STRING);                          // reverse sorting for quicker result
    }

    /**
     * get the area from a phone number
     *
     * @param string $phoneNumber
     * @return string|bool $area
     */
    public function getArea($phoneNumber)
    {
        foreach ($this->areaCodes as $key => $value) {
            if (substr($phoneNumber, 1, strlen($key)) == $key) {    // area codes are without leading zeros
                return $value;
            }
        }

        return false;
    }

    /**
     * get the tellows score and comments
     *
     * @param string $number phone number
     * @return array|bool $score
     */
    public function getRating($number)
    {
        $score = [];
        $url = sprintf('http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123', $number);
        $rating = @simplexml_load_file($url);
        if (!$rating) {
            return false;
        }
        $rating->asXML();
        $score = ['score' => $rating->score,
            'comments' => $rating->comments];
        return $score;
    }

    /**
     * set a new contact in a phonebook
     *
     * @param string $name
     * @param string $number
     * @param string $type
     * @param int $phonebook
     * @return void
     */
    public function setContact($name, $number, $type, $phonebook)
    {
        // assamble minimal contact structure
        $spamContact = $this->fritzbox->newContact($name, $number, $type);
        // add the spam call as new phonebook entry
        $this->fritzbox->setPhonebookEntry($spamContact, $phonebook);
    }



    /**
     * set logging info
     *
     * @param string $info
     * @return void
     */
    public function setLogging ($info)
    {
        if ($this->loggingPath) {
            $message = date('d.m.Y H:i:s') . ' => ' . $info;
            file_put_contents($this->loggingPath . 'callrouter_logging.txt', $message, FILE_APPEND);
        }
    }
}
