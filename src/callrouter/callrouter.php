<?php

namespace blacksenator\callrouter;

/* class callrouter
 *
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

use blacksenator\fritzsoap\x_contact;
use SimpleXMLElement;

class callrouter
{
    const CALLMONITORPORT = '1012';     // FRITZ!Box port for callmonitor
    const DELIMITER = ';';  	        // delimiter in /assets/ONB.csv
    const CELLUAR = [                   // an array of celluar network codes (RNB) according to the list of ONB
                '1511'  => 'Telekom',   // source from: BNetzA at https://tinyurl.com/y7648pc9
                '1512'  => 'Telekom',
                '1514'  => 'Telekom',
                '1515'  => 'Telekom',
                '1516'  => 'Telekom',
                '1517'  => 'Telekom',
                '1520'  => 'Vodafone',
                '1521'  => 'Vodafone/Lyca',
                '1522'  => 'Vodafone',
                '1523'  => 'Vodafone',
                '1525'  => 'Vodafone',
                '1526'  => 'Vodafone',
                '1529'  => 'Vodafone/Truphone',
                '15566' => 'Drillisch',
                '15630' => 'multiConnect',
                '15678' => 'Argon',
                '1570'  => 'Telefónica',
                '1573'  => 'Telefónica',
                '1575'  => 'Telefónica',
                '1577'  => 'Telefónica',
                '1578'  => 'Telefónica',
                '1579'  => 'Telefónica/SipGate',
                '15888' => 'TelcoVillage',
                '1590'  => 'Telefónica',
                '160'   => 'Telekom',
                '162'   => 'Vodafone',
                '163'   => 'Telefónica',
                '170'   => 'Telekom',
                '171'   => 'Telekom',
                '172'   => 'Vodafone',
                '173'   => 'Vodafone',
                '174'   => 'Vodafone',
                '175'   => 'Telekom',
                '176'   => 'Telefónica',
                '177'   => 'Telefónica',
                '178'   => 'Telefónica',
                '179'   => 'Telefónica',
            ];

    public $currentNumbers = [];
    public $areaCodes = [];

    private $fritzbox;                                      // SOAP client
    private $url = [];                                      // url components as array
    private $phonebookList;
    private $update = 0;
    private $loggingPath = '';

    /**
     * @param array $fritzbox
     * @param array $logging
     * @return void
     */
    public function __construct($fritzbox, $loggingPath)
    {
        $this->fritzbox = new x_contact($fritzbox['url'], $fritzbox['user'], $fritzbox['password']);
        $this->url = $this->fritzbox->getURL();
        $this->fritzbox->getClient();
        $this->phonebookList = $this->fritzbox->getPhonebookList();
        $this->getAreaCodes();
        $this->loggingPath = empty($loggingPath) ? dirname(__DIR__, 2)  : $loggingPath;
    }

    /**
     * get a fresh client with new SID
     *
     * return void
     */
    public function refreshClient ()
    {
        $this->fritzbox->getClient();
    }

    /**
     * get the FRITZ!Box callmonitor socket
     *
     * @param int $timeout
     * @return resource $socket
     */
    public function getSocket(int $timeout = 1)
    {
        $adress = 'tcp://' . $this->url['host'] . ':' . self::CALLMONITORPORT;
        $stream = stream_socket_client($adress, $errno, $errstr);
        if (!$stream) {
            $message = sprintf("Can't reach the callmonitor port! Error: %s (%s)!", $errstr, $errno);
            throw new \Exception($message);
        }
        $socket = socket_import_stream($stream);
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        stream_set_timeout ($stream, $timeout);

        return $stream;
    }

    /**
     * get list of avalable phonebooks
     *
     * @return string $phonebookList
     */
    public function getPhonebookList(): string
    {
        return $this->phonebookList;
    }

    /**
     * get the time of the last update
     *
     * @return string $update
     */
    public function getLastUpdate()
    {
        return $this->update;
    }

    /**
     * get current data from FRITZ!Box
     *
     * @param int $phonebookID
     * @return void
     */
    public function getCurrentData(int $phonebookID = 0)
    {
        $numbers = [];
        $phoneBook = $this->fritzbox->getPhonebook($phonebookID);
        if ($phoneBook != false) {
            $numbers = $this->getNumbers((object)$phoneBook);
            if (count($numbers) == 0) {
                exit('The phone book against which you want to check is empty!');
            } else {
                $this->currentNumbers = $numbers;
                $this->update = time();
            }
        }
    }

    /**
     * delivers a simple array of numbers from a designated phone book
     * according to $types - if you want only numbers of a special type
     *
     * @param SimpleXMLElement $phoneBook downloaded phone book
     * @param array $types phonetypes (e.g. home, work, mobil, fax, fax_work)
     * @return array phone numbers
     */
    private function getNumbers(SimpleXMLElement $phoneBook, array $types = []): array
    {
        $numbers = [];
        foreach ($phoneBook->phonebook->contact as $contact) {
            foreach ($contact->telephony->number as $number) {
                if ((substr($number, 0, 1) == '*') || (substr($number, 0, 1) == '#')) {
                    continue;
                }
                if (count($types)) {
                    if (in_array($number['type'], $types)) {
                        $number = (string)$number[0];
                    } else {
                        continue;
                    }
                } else {
                    $number = (string)$number[0];
                }
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * get an array, where the area code (ONB) is key and area name is value
     * ONB stands for OrtsNetzBereich(e)
     * source is "Vorwahlverzeichnis (VwV)" a zipped CSV from BNetzA at https://tinyurl.com/y4umk5ww
     * if you want to update this: save the unpacked file as "ONB.csv" in ./assets
     *
     * @return void
     */
    private function getAreaCodes()
    {
        if (!$onbData = file(dirname(__DIR__, 2) . '/assets/ONB.csv')) {
            echo 'Could not read ONB data!';
            return;
        }
        if (end($onbData) == "\x1a") {                                  // file comes with this char at eof
            array_pop($onbData);
        }
        $rows = array_map(function($row) { return str_getcsv($row, self::DELIMITER); }, $onbData);
        array_shift($rows);                                             // delete header
        foreach($rows as $row) {
            if ($row[2] == 1) {                                         // only active ONBs ("1")
                $this->areaCodes[$row[0]] = $row[1];
            }
        }
        $this->areaCodes = $this->areaCodes + self::CELLUAR;            // adding celluar network codes
        krsort($this->areaCodes, SORT_STRING);                          // reverse sorting for quicker result
    }

    /**
     * get the area from a phone number
     *
     * @param string $phoneNumber to extract the area code from
     * @return string|bool $area the found area code or false
     */
    public function getArea(string $phoneNumber)
    {
        foreach ($this->areaCodes as $key => $value) {
            if (substr($phoneNumber, 1, strlen($key)) == $key) {    // area codes are without leading zeros
                return $value;
            }
        }

        return false;
    }

    /**
     * get the tellows rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    public function getRating(string $number)
    {
        $score = [];
        $url = sprintf('http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123', $number);
        $rating = @simplexml_load_file($url);
        if (!$rating) {
            return false;
        }
        $rating->asXML();
        $score = [
            'score' => $rating->score,
            'comments' => $rating->comments,
        ];

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
        $result = $this->fritzbox->setPhonebookEntry($spamContact, $phonebook);
    }

    /**
     * write logging info
     *
     * @param string $info
     * @return void
     */
    public function writeLogging ($info)
    {
        $message = date('d.m.Y H:i:s') . ' => ' . $info . PHP_EOL;
        file_put_contents($this->loggingPath . '/callrouter_logging.txt', $message, FILE_APPEND);
    }

    /**
     * parse a string from callmonitor socket output
     * e.g.: "01.01.20 10:10:10;RING;0;01701234567;987654;SIP0;\r\n"
     * or with less parameters: "18.11.12 00:13:26;DISCONNECT;0;0;\r\n"
     *
     * @param string $line
     * @return array $result
     */
    public function parseCallString(string $line): array
    {
        $line = str_replace(';\\r\\n', '', $line);      // eliminate CR
        $params = explode(';', $line);

        return [
            'timestamp' => $params[0],
            'type' => $params[1],
            'conID' => $params[2],
            'extern' => $params[3],
            'intern' => (isset($params[4])) ? $params[4] : "",
            'device' => (isset($params[5])) ? $params[5] : ""
        ];
    }

    /**
     * sanitize an phone numer string
     *
     * @param string $number
     * @return string $number
     */
    public function sanitizeNumber (string $number): string
    {
        if (substr($number, 0, 1) === '+') {                            // if foreign number starts with +
            $number = '00' . substr($number, 1, strlen($number) - 1);   // it will be replaced with 00
        }

        return preg_replace("/[^0-9]/","",$number);                     // only digits
    }

}
