<?php

namespace blacksenator\callrouter;

/** class callmonitor
 *
 * provides function to access the callmonitor at a FRITZ!Box via Port 1022.
 * Observations have shown that the socket connection needs to be refreshed
 * every two hours if it is constantly being eavesdropped
 *
 * @copyright (c) 2019 - 2022 Volker Püschel
 * @license MIT
 */

class callmonitor
{
    const
        CALLMONITORPORT = '1012',           // FRITZ!Box port for callmonitor
        REFRESHTIME = 7200;                             // two hours in seconds

    private
        $fritzBoxSocket,
        $socketAdress,
        $nextSocketRefresh = 0;

    /**
     * @param array $config
     * @param array $loggingPath
     * @return void
     */
    public function __construct(array $url)
    {
        date_default_timezone_set('Europe/Berlin');
        $this->socketAdress = 'tcp://' . $url['host'] . ':' . self::CALLMONITORPORT;
        $this->getSocket();
    }

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
        $this->nextSocketRefresh = time() + self::REFRESHTIME;
    	$this->fritzBoxSocket = $stream;
    }

    /**
     * check and update socket status
     *
     * @return string
     */
    public function refreshSocket()
    {
        $msg = null;
        if (time() > $this->nextSocketRefresh) {
            $this->getSocket();                             // refresh socket
            $msg = 'Status: Regular Socket refresh';
        } elseif (stream_get_meta_data($this->fritzBoxSocket)['eof']) {   // socket died
            $this->getSocket();                             // refresh socket
            $msg = 'Status: Dead Socket refreshed';
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
        if (($output = fgets($this->fritzBoxSocket)) != false) {
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
     * returns socket adress
     *
     * @return string $this->socketAdress
     */
    public function getSocketAdress()
    {
        return $this->socketAdress;
    }
}
