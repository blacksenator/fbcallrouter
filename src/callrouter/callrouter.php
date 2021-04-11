<?php

namespace blacksenator\callrouter;

/** class callrouter
 *
 * A necessary source is "Vorwahlverzeichnis (VwV)"
 * a zipped CSV from BNetzA
 * @see: https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/ON_Einteilung_ONB/ON_ONB_ONKz_ONBGrenzen_Basepage.html
 *
 * It is advisable to consult the above address
 * from time to time to check if there are any changes.
 * If so: download the ZIP-file and save the unpacked
 * file as "ONB.csv" in ./assets
 *
 * Copyright (c) 2019 - 2021 Volker Püschel
 * @license MIT
 */

use blacksenator\fritzsoap\x_contact;

class callrouter
{
    const
        CALLMONITORPORT = '1012',                       // FRITZ!Box port for callmonitor
        ONEDAY = 86400,                                 // seconds
        TWOHRS =  7200,                                 // seconds
        ONB_SOURCE = '/assets/ONB.csv',                 // path to file with official area codes
        DELIMITER = ';';                                // delimiter of ONB.csv

    private $fbSocket;
    private $nextRefresh = 0;
    private $fritzbox;                                  // SOAP client
    private $url = [];                                  // url components as array
    private $prefixes = [];                             // area codes incl. mobile codes ($celluar)
    private $celluar = [];
    private $phonebookList = [];
    private $nextUpdate = 0;
    private $logging = false;
    private $loggingPath = '';

    /**
     * @param array $config
     * @param array $loggingPath
     * @return void
     */
    public function __construct($fritzbox, $logging)
    {
        $this->fritzbox = new x_contact($fritzbox['url'], $fritzbox['user'], $fritzbox['password']);
        $this->fritzbox->getClient();
        $this->url = $this->fritzbox->getURL();
        $this->phonebookList = explode(',', $this->fritzbox->getPhonebookList());
        $this->getPhoneCodes();
        $this->getSocket();
        $this->logging = $logging['log'] ?: false;
        $this->loggingPath = $logging['logPath'] ?: dirname(__DIR__, 2);
    }

    // ### SOAP functions ###

    /**
     * get a fresh client with new SID
     *
     * return void
     */
    public function refreshClient()
    {
        $this->fritzbox->getClient();
    }

    /**
     * get phonebook
     *
     * @param int $phonebookID
     * @return array
     */
    public function getPhoneNumbers($phonebookID = 0)
    {
        $phoneBook = $this->fritzbox->getPhonebook($phonebookID);
        if ($phoneBook == false) {
            return [];
        }

        return $this->fritzbox->getListOfPhoneNumbers($phoneBook);
    }

    /**
     * returns a reread phonebook numbers
     *
     * @param int $phonebook
     * @param int $elapse
     * @return array
     */
    public function refreshPhonebook($phonebook, $elapse)
    {
        $numbers = $this->getPhoneNumbers($phonebook);
        $this->nextUpdate = time() + $elapse;
        $this->setLogging(1, [date('d.m.Y H:i:s', $this->nextUpdate)]);

        return $numbers;
    }

    /**
     * returns time of next next update
     *
     * @return int
     */
    public function getNextUpdate()
    {
        return $this->nextUpdate;
    }

    /**
     * set new entry in blacklist
     *
     * @param callrouter $object
     * @param int $phonebook
     * @param string $name
     * @param string $number
     * @param string $type
     * @return array
     */
    public function writeBlacklist(int $phonebook, string $name, string $number, string $type)
    {
        $this->fritzbox->getClient();
        $this->fritzbox->setContact($phonebook, $name, $number, $type);

        return $this->getPhoneNumbers($phonebook);
    }

    /**
     * return true if phonebook exist
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
        if (substr($number, 0, 1) === '+') {                        // if foreign number starts with +
            $number = '00' . substr($number, 1);                    // it will be replaced with 00
        }

        return preg_replace("/[^0-9]/", '', $number);               // only digits
    }

    // ### callmonitor socket functions ###

    /**
     * return the FRITZ!Box callmonitor socket
     *
     * @param int $timeout
     * @return void
     */
    private function getSocket(int $timeout = 1)
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
        $this->nextRefresh = time() + self::TWOHRS;           // observations have shown that the socket needs to be renewed
    	$this->fbSocket = $stream;
    }

    /**
     * check and update socket status
     *
     * @return string
     */
    public function refreshSocket()
    {
        $msg = null;
        if (stream_get_meta_data($this->fbSocket)['eof']) {               // socket died
            $this->getSocket();                   // refresh socket
            $msg = 'Status: Dead Socket refreshed';
        } elseif (time() > $this->nextRefresh) {
            $this->getSocket();                   // refresh socket
            $msg = 'Status: Regular Socket refresh';
        }

        return $msg;
    }

    /**
     * returns the socket output
     *
     * @param array
     */
    public function getSocketStream()
    {
        if (($output = fgets($this->fbSocket)) != null) {
            return $this->parseCallString($output);       // [timestamp];[type];[conID];[extern];[intern];[device];
        }

        return ['type' => null];
    }

    /**
     * parse a string from callmonitor socket output
     * e.g.: "01.01.20 10:10:10;RING;0;01701234567;987654;SIP0;\r\n"
     * or with less parameters: "18.11.12 00:13:26;DISCONNECT;0;0;\r\n"
     *
     * @param string $line
     * @return array $result
     */
    private function parseCallString(string $line): array
    {
        $params = explode(';', str_replace(';\\r\\n', '', $line));

        return [
            'timestamp' => $params[0],
            'type'      => $params[1],
            'conID'     => $params[2],
            'extern'    => $params[3],
            'intern'    => $params[4] ?? "",
            'device'    => $params[5] ?? ""
        ];
    }

    // ### phone number handling ###

    /**
     * get an array, where the area code (ONB) is key
     * and area name is value
     * ONB stands for OrtsNetzBereich(e)
     *
     * @return array
     */
    private function getAreaCodes()
    {
        if (!$onbData = file(dirname(__DIR__, 2) . self::ONB_SOURCE)) {
            echo 'Could not read ONB data from local file!';
            return [];
        }
        !end($onbData) == "\x1a" ?: array_pop($onbData);        // usually file comes with this char at eof
        $rows = array_map(function($row) { return str_getcsv($row, self::DELIMITER); }, $onbData);
        array_shift($rows);                                     // delete header
        foreach($rows as $row) {
            if ($row[2] == 1) {                                 // only use active ONBs ("1")
                $areaCodes[$row[0]] = $row[1];
            }
        }

        return $areaCodes;
    }

    /**
     * returns an array with area codes and codes
     * of mobile providers:
     * from longest and highest key [39999] to
     * the shortest [30]
     *
     * @return array
     */
    private function getPhoneCodes()
    {
        require_once ('assets/celluar.php');

        $this->celluar = $celluarNumbers;                   // we need them seperatly but not sorted
        $this->prefixes = $this->getAreaCodes() + $this->celluar;
        krsort($this->prefixes, SORT_NUMERIC);
    }

    /**
     * return the german area data and subscribers number
     * from a phone number:
     * [0] => area code
     * [1] => area name (NDC = national destination code)
     * [2] => subscribers number (base number plus direct dial in)
     *
     * Germany currently has around 5200 area codes. If you want
     * to split up a large number of phone numbers, this array based
     * solution should be replaced with a tree/leaf structure. However,
     * this is sufficient for occasional inquiries.
     *
     * @param string $phoneNumber to extract the area code from
     * @return array|bool $area code data or false
     */
    public function getArea(string $phoneNumber)
    {
        foreach ($this->prefixes as $key => $value) {
            $codeLength = strlen($key);
            if ($key == substr($phoneNumber, 1, $codeLength)) {    // area codes are without leading zeros
                return [
                    'prefix'      => $key,
                    'designation' => $value,
                    'subscriber'  => substr($phoneNumber, 1 + $codeLength),
                ];
            }
        }

        return false;
    }

    /**
     * returns true if prefix is a celluar code
     *
     * @param string $prefix
     * @return bool
     */
    public function isCelluarCode($prefix)
    {
        return in_array($prefix, $this->celluar);
    }

    /**
     * return the tellows rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    public function getRating(string $number)
    {
        $url = sprintf('http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123', $number);
        $rating = @simplexml_load_file($url);
        if (!$rating) {
            return false;
        }
        $rating->asXML();

        return [
            'score'    => $rating->score,
            'comments' => $rating->comments,
        ];
    }

    // ### logging functions ###

    /**
     * set logging
     *
     * @param Callrouter $object
     * @param bool $log
     * @param int $stringID
     * @param array $infos
     */
    public function setLogging(int $stringID = null, array $infos = [])
    {
        if ($this->logging) {
            switch ($stringID) {
                case 0:
                    $message = $infos[0];
                    break;

                case 1:
                    $message = sprintf('Initialization: phonebook (whitelist) loaded; next refresh: %s', $infos[0]);
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

                case 9:
                    $message = sprintf('The caller is using an illegal subscriber number! Added to spam phonebook #%s', $infos[0]);
                    break;

                    }
            $this->writeLogging($message);
        }
    }

    /**
     * write logging info
     *
     * @param string $info
     * @return void
     */
    private function writeLogging ($info)
    {
        $message = date('d.m.Y H:i:s') . ' => ' . $info . PHP_EOL;
        file_put_contents($this->loggingPath . '/callrouter_logging.txt', $message, FILE_APPEND);
    }
}
