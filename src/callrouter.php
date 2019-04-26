<?php

namespace blacksenator;

/* class callrouter
 *
 * Copyright (c) 2019 Volker Püschel
 * @license MIT
 */

use blacksenator\fritzsoap\fritzsoap;

class callrouter
{
    const CALLMONITORPORT = '1012';     // FRITZ!Box port for callmonitor

    public $currentNumbers = [];
    public $lastupdate;

    private $fritzbox;
    private $url;

    public function __construct($config)
    {
        $this->fritzbox = new fritzsoap($config['url'], $config['user'], $config['password']);
        $this->url = $this->fritzbox->getURL();
        $this->fritzbox->getClient('x_contact', 'X_AVM-DE_OnTel:1');
        $this->lastupdate = time();
    }

    /**
     * get the FRITZ!Box callmonitor socket
     *
     * @return string|bool $socket
     */
    public function getSocket()
    {
        $adress = $this->url['host'] . ':' . SELF::CALLMONITORPORT;
        $socket = stream_socket_client($adress, $errno, $errstr);
            if (!$socket) {
                error_log(sprintf("Could not listen to callmonitor! Error: %s (%s)!", $errstr, $errno));
                return;
            }
        // echo get_resource_type($socket) . PHP_EOL;

        return $socket;
    }

    /**
     * get current data from FRITZ!Box
     */
    function getCurrentData($phonebookID)
    {
        $phoneBook = $this->fritzbox->getPhonebook($phonebookID);
            $this->currentNumbers = $this->getNumbers($phoneBook);
            if (count($this->currentNumbers) == 0) {
                echo 'The phone book against which you want to check is empty!' . PHP_EOL;
                $this->lastupdate = time();
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
     * get the tellows score if it is above 5 (neutral) and it got more than 3 comments
     *
     * @param string $number phone number
     * @param int $comments  must be three or higher (everything else makes no sense)
     * @return int $score
     */
    public function getRating($number, $comments = 3)
    {
        $score = 5;
        if ($comments < 3) {
            $comments = 3;
        }
        $url = sprintf('http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123', $number);
        $rating = @simplexml_load_file($url);
        if ($rating !== false) {
            $rating->asXML();
            if (($rating->score > 5) && ($rating->comments >= $comments)) {
                $score = $rating->score;
            }
        }

        return $score;
    }

    public function setContact($name, $number, $type, $phonebook)
    {
        // assamble minimal contact structure
        $spamContact = $this->fritzbox->newContact($name, $number, $type);
        // add the spam call as new phonebook entry
        $this->fritzbox->setPhonebookEntry($spamContact, $phonebook);
    }
}
