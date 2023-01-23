<?php

namespace blacksenator\callrouter;

/** class logging
 *
 * provides function to log call router actions
 *
 * @copyright (c) 2019 - 2023 Volker Püschel
 * @license MIT
 */

class logging
{
    private
        $logging = false,
        $loggingPath = '';

    /**
     * @param array $logging
     * @return void
     */
    public function __construct(array $config)
    {
        $this->logging = $config['log'] ?? false;
        $this->loggingPath = $config['logPath'] ?: dirname(__DIR__, 2);
    }

    /**
     * set logging
     *
     * @param int $stringID
     * @param array $infos
     * @return void
     */
    public function setLogging(int $stringID = null, array $infos = [])
    {
        if ($stringID == 0) {
            $message = sprintf('Guarding started: listen to FRITZ!Box call monitor at %s', $infos[0]);
        } elseif ($stringID == 1) {
            $message = sprintf('Phone books %s (re-)loaded; next refresh: %s', $infos[0], $infos[1]);
        } elseif ($stringID == 2) {
            $message = sprintf('CALL IN from number %s to MSN %s', $infos[0], $infos[1]);
        } elseif ($stringID == 3) {
            $message = sprintf('Caller uses an unknown country code! Added to spam phone book #%s', $infos[1]);
        } elseif ($stringID == 4) {
            $message = sprintf('Foreign number. Added to spam phone book #%s', $infos[1]);
        } elseif ($stringID == 5) {
            $message = sprintf('Caller uses a nonexistent area code! Added to spam phone book #%s', $infos[1]);
        } elseif ($stringID == 6) {
            $message = sprintf('Caller has a bad reputation (%s/%s)! Added to spam phone book #%s', $infos[2], $infos[3], $infos[1]);
        } elseif ($stringID == 7) {
            $message = sprintf('Caller has a rating of %s out of 9 and %s valuations.', $infos[0], $infos[1]);
        } elseif ($stringID == 8) {
            $message = sprintf('Status: phonebook %s refreshed; next refresh: %s', $infos[0], $infos[1]);
        } elseif ($stringID == 9) {
            $message = sprintf('Caller is using an illegal subscriber number! Added to spam phone book #%s', $infos[1]);
        } elseif ($stringID == 10) {
            $message = sprintf('Called number identified as: %s. Entry added to phone book #%s', $infos[0], $infos[1]);
        } elseif ($stringID == 11) {
            $message = sprintf('Number traced in: %s', $infos[0]);
        } elseif ($stringID == 12) {
            $message = sprintf('CALL OUT to number %s', $infos[0]);
        } elseif ($stringID == 13) {
            $message = sprintf('Number length is not in range. Added to spam phone book #%s', $infos[1]);
        } elseif ($stringID == 14) {
            $message = sprintf('Caller transmits an unusual number combination. Added to spam phone book #%s', $infos[1]);
        } elseif ($stringID == 15) {
            $message = sprintf('Derived actual phone number: %s Added to spam phone book #%s', $infos[2], $infos[1]);
        } elseif ($stringID == 16) {
            $message = sprintf('User provided phone number: %s Added to spam phone book #%s', $infos[2], $infos[1]);
        } elseif ($stringID == 17) {
            $message = sprintf('Network provided phone number: %s Added to spam phone book #%s', $infos[2], $infos[1]);
        } elseif ($stringID == 99) {
            $message = $infos[0];
        }

        return $this->writeLogging($message);
    }

    /**
     * write logging info
     *
     * @param string $info
     * @return string $message
     */
    private function writeLogging ($info)
    {
        date_default_timezone_set('Europe/Berlin');
        $message = date('d.m.Y H:i:s') . ' => ' . $info . PHP_EOL;
        if ($this->logging) {
            file_put_contents($this->loggingPath . '/callrouter_logging.txt', $message, FILE_APPEND);
        }

        return $message;
    }
}
