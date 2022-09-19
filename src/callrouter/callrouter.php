<?php

namespace blacksenator\callrouter;

/** class callrouter
 *
 * A necessary source is "Vorwahlverzeichnis (VwV)" a zipped CSV from BNetzA
 * @see: https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/ON_Einteilung_ONB/ON_ONB_ONKz_ONBGrenzen_Basepage.html
 *
 * It is advisable to consult the above address from time to time to check if there are any changes.
 * If so: download the ZIP-file and save the unpacked file as "ONB.csv" in ./assets
 *
 * Copyright (c) 2019 - 2022 Volker Püschel
 * @license MIT
 */

use \SimpleXMLElement;
use \DOMDocument;
use blacksenator\fritzsoap\x_contact;

class callrouter
{
    const
        CALLMONITORPORT = '1012',                       // FRITZ!Box port for callmonitor
        ONEDAY = 86400,                                 // seconds
        TWOHRS =  7200,                                 // seconds
        ONB_SOURCE = '/assets/ONB.csv',                 // path to file with official area codes
        DELIMITER = ';',                                // delimiter of ONB.csv
        TELLOWS = 'http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123',
        CLVRDLR = 'https://www.cleverdialer.de/telefonnummer/',
        WRRFTAN = 'https://www.werruft.info/telefonnummer/';

    private $fbSocket;
    private $socketAdress;
    private $nextRefresh = 0;
    private $fritzbox;                                  // SOAP client
    private $prefixes = [];                             // area codes incl. mobile codes ($celluar)
    private $celluar = [];
    private $phonebookList = [];
    private $nextUpdate = 0;
    private $score;
    private $comments;
    private $logging = false;
    private $loggingPath = '';

    /**
     * @param array $config
     * @param array $loggingPath
     * @return void
     */
    public function __construct(array $fritzbox, array $filter, array $logging)
    {
        $this->fritzbox = new x_contact($fritzbox['url'], $fritzbox['user'], $fritzbox['password']);
        $this->fritzbox->getClient();
        $this->phonebookList = explode(',', $this->fritzbox->getPhonebookList());
        $this->getPhoneCodes();
        $url = $this->fritzbox->getURL();
        $this->socketAdress = 'tcp://' . $url['host'] . ':' . self::CALLMONITORPORT;
        $this->getSocket();
        $this->score = $filter['score'] > 9 ? 9 : $filter['score'];
        $this->comments = $filter['comments'] < 3 ? 3 : $filter['comments'];
        $this->logging = $logging['log'] ?: false;
        $this->loggingPath = $logging['logPath'] ?: dirname(__DIR__, 2);
    }

    // ### SOAP related functions ###

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
     * get numbers from phonebook
     *
     * @param int $phonebookID
     * @return array
     */
    public function getPhoneNumbers(int $phonebookID = 0)
    {
        $phoneBook = $this->fritzbox->getPhonebook($phonebookID);
        if ($phoneBook == false) {
            return [];
        }

        return $this->fritzbox->getListOfPhoneNumbers($phoneBook);
    }

    /**
     * count refresh interval days in seconds
     *
     * @param int $refresh
     * @return int
     */
    public function getRefreshInterval(int $refresh)
    {
        return $refresh < 1 ? self::ONEDAY : $refresh * self::ONEDAY;
    }

    /**
     * returns a reread phonebook numbers
     *
     * @param int $phonebook
     * @param int $elapse
     * @return array
     */
    public function refreshPhonebook(int $phonebook, int $elapse)
    {
        $numbers = $this->getPhoneNumbers($phonebook);
        $this->nextUpdate = time() + $elapse;
        $this->setLogging(1, [$phonebook, date('d.m.Y H:i:s', $this->nextUpdate)]);

        return $numbers;
    }

    /**
     * returns time of next update
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
        if (substr(trim($number), 0, 1) === '+') {                  // if number starts with "+" (foreign)
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
        $stream = stream_socket_client($this->socketAdress, $errno, $errstr);
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
        if (($output = fgets($this->fbSocket)) != false) {
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
     * get an array, where the area code (ONB) is key and area name is value
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
            if ($row[2] == 0) {                                 // only use active ONBs ("1")
                continue;
            }
            $areaCodes[$row[0]] = $row[1];
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
        require_once ('assets/celluar.php');

        $this->celluar = $celluarNumbers;                   // we need them also seperatly
        $this->prefixes = $this->getAreaCodes() + $this->celluar;
        krsort($this->prefixes, SORT_NUMERIC);
    }

    /**
     * return the german area data and subscribers numberfrom a phone number:
     * [0] => area code
     * [1] => area name (NDC = national destination code)
     * [2] => subscribers number (base number plus direct dial in)
     *
     * Germany currently has around 5200 area codes. If you want to split up a
     * large number of phone numbers, this array based solution should be
     * replaced with a tree/leaf structure. However, this is sufficient for
     * occasional inquiries.
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
    private function getTellowsRating(string $number)
    {
        $rating = @simplexml_load_file(sprintf(self::TELLOWS, $number));
        if (!$rating) {
            return false;
        }
        $rating->asXML();

        return [
            'score'    => (string)$rating->score,
            'comments' => (string)$rating->comments,
        ];
    }

    /**
     * converting an HTML response into a SimpleXMLElement
     *
     * @param string $response
     * @return SimpleXMLElement $xmlSite
     */
    private function convertHTMLtoXML($response)
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        @$dom->loadHTML($response);

        return simplexml_import_dom($dom);
    }

    /**
     * returns a websites HTML as XML
     *
     * @param string $url
     * @return SimpleXMLelement | bool
     */
    private function getWebsiteAsXML(string $url)
    {
        $html = file_get_contents($url);
        if (!$html) {
            return false;
        }

        return $this->convertHTMLtoXML($html);
    }

    /**
     * returns the equivalent from one of/to five stars to the score from one to
     * nine, where five stars are a score of one and one star is the score of nine
     *
     * @param string | float $stars
     * @return float as 1 .. 9
     */
    private function convertStarsToScore($stars)
    {
        return round($stars * 2) / 2 * -2 + 11;
    }

    /**
     * return the werruft.info rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    private function getWerRuftInfoRating(string $number)
    {
        $rawXML = $this->getWebsiteAsXML(self::WRRFTAN . $number . '/');
        if (!$rawXML) {
            return false;
        }
        $comments = $rawXML->xpath('//i[contains(@class, "comop")]');
        if (!count($comments) == 5) {
            return false;
        }
        $weighted = 0;
        $total = 0;
        foreach($comments as $comment) {
            $weighted += intval(substr($comment->attributes()['class'], -1)) * (int)$comment;
            $total += (int)$comment;
        }

        return [
            'score'    => $this->convertStarsToScore($weighted / $total),
            'comments' => $total,
        ];
    }

    /**
     * return the cleverdialer rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    private function getCleverDialerRating(string $number)
    {
        $rawXML = $this->getWebsiteAsXML(self::CLVRDLR . $number);
        if (!$rawXML) {
            return false;
        }
        $valuation = $rawXML->xpath('//div[@class = "rating-text"]');
        $stars = str_replace(' von 5 Sternen', '', $valuation[0]->span[0]);
        if ($stars > 0) {
            $commentsLabel = $rawXML->xpath('//table[@class = "table recent-comments"]');
            $comments = str_replace(' Kommentare zu ' . $number, '', (string)$commentsLabel[0]->caption);
            return [
                'score'    => $this->convertStarsToScore($stars),
                'comments' => $comments,
            ];
        }

        return false;
    }

    /**
     * returns if rating is above or equal to the user limits
     *
     * @param array $rating
     * @return bool
     */
    public function proofRating(array $rating)
    {
        if ($rating['score'] >= $this->score && $rating['comments'] >= $this->comments) {
            return true;
        }

        return false;
    }

    /**
     * proofs cascading if the number is known in online list as bad rated
     *
     * @param string $number
     * @param string $score
     * @param string $comments
     * @return array | bool
     */
    public function getRating(string $number)
    {
        if ($rating = $this->getTellowsRating($number)) {
            if ($this->proofRating($rating)) {
                return $rating;
            }
        }
        if ($rating = $this->getWerRuftInfoRating($number)) {
            if ($this->proofRating($rating)) {
                return $rating;
            }
        }
        $rating = $this->getCleverDialerRating($number);

        return $rating;
    }

    /**
     * returns rearranged timestamp data
     *
     * @param string $timestamp
     * @return string
     */
    public function getTimeStampReverse(string $timeStamp)
    {
        $parts = explode(' ', $timeStamp);
        $date = explode('.', $parts[0]);

        return '20' . $date[2] . '.' . $date[1] . '.' . $date[0] . ' ' . $parts[1];
    }

    // ### logging functions ###

    /**
     * set logging
     *
     * @param Callrouter $object
     * @param bool $log
     *
     * @param int $stringID
     * @param array $infos
     * @return void
     */
    public function setLogging(int $stringID = null, array $infos = [])
    {
        if ($this->logging) {
            if ($stringID == 0) {
                $message = $infos[0];
            } elseif ($stringID == 1) {
                $message = sprintf('Initialization: phonebook %s loaded; next refresh: %s', $infos[0], $infos[1]);
            } elseif ($stringID == 2) {
                $message = sprintf('CALL IN from number %s to MSN %s', $infos[0], $infos[1]);
            } elseif ($stringID == 3) {
                $message = sprintf('Number %s found in phonebook #%s', $infos[0], $infos[1]);
            } elseif ($stringID == 4) {
                $message = sprintf('Foreign number! Added to spam phonebook #%s', $infos[0]);
            } elseif ($stringID == 5) {
                $message = sprintf('Caller uses a nonexistent area code! Added to spam phonebook #%s', $infos[0]);
            } elseif ($stringID == 6) {
                $message = sprintf('Caller has a bad reputation (%s/%s)! Added to spam phonebook #%s', $infos[0], $infos[1], $infos[2]);
            } elseif ($stringID == 7) {
                $message = sprintf('Caller has a rating of %s and %s comments.', $infos[0], $infos[1]);
            } elseif ($stringID == 8) {
                $message = sprintf('Status: phonebook %s refreshed; next refresh: %s', $infos[0], $infos[1]);
            } elseif ($stringID == 9) {
                $message = sprintf('The caller is using an illegal subscriber number! Added to spam phonebook #%s', $infos[0]);
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
