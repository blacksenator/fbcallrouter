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

class callrouter extends phonetools
{
    const
        CALLMONITORPORT = '1012',           // FRITZ!Box port for callmonitor
        ONEDAY = 86400,                                             // seconds
        TWOHRS =  7200;                                             // seconds

    private $fbSocket;
    private $socketAdress;
    private $nextRefresh = 0;
    private $nextUpdate = 0;
    private $logging = false;
    private $loggingPath = '';

    /**
     * @param array $config
     * @param array $loggingPath
     * @return void
     */
    public function __construct(array $fritzBox, array $logging)
    {
        parent::__construct($fritzBox);
        $url = $this->getURL();
        $this->socketAdress = 'tcp://' . $url['host'] . ':' . self::CALLMONITORPORT;
        $this->getSocket();
        $this->logging = $logging['log'] ?? false;
        $this->loggingPath = $logging['logPath'] ?: dirname(__DIR__, 2);
    }

    /**
     * returns a reread phonebook numbers
     *
     * @param array $phonebooks
     * @param int $elapse
     * @return array
     */
    public function refreshPhonebooks(array $phoneBooks, int $elapse)
    {
        $numbers = $this->getPhoneBooks($phoneBooks);
        $this->nextUpdate = time() + $elapse;
        $this->setLogging(1, [$phoneBooks[0], date('d.m.Y H:i:s', $this->nextUpdate)]);

        return $numbers;
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
     * returns time of next update
     *
     * @return int
     */
    public function getNextUpdate()
    {
        return $this->nextUpdate;
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
        $this->nextRefresh = time() + self::TWOHRS; // observations have shown that the socket needs to be renewed
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
        if (stream_get_meta_data($this->fbSocket)['eof']) {     // socket died
            $this->getSocket();                             // refresh socket
            $msg = 'Status: Dead Socket refreshed';
        } elseif (time() > $this->nextRefresh) {
            $this->getSocket();                             // refresh socket
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
            return $this->parseCallString($output);
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
