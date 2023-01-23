<?php

namespace blacksenator;

use blacksenator\callrouter\callrouter;

/**
 * main function is listening constantly to the FRITZ!Box call monitor port
 *
 * @param array $config
 * @param array $testNumbers
 * @return void
 */
function callRouting(array $config, array $testNumbers = [])
{
    $callRouter = new callrouter($config, $testNumbers);
    while (true) {
        $values = $callRouter->getCallMonitorStream();
        // uncomment next line for debugging
        // $values = $callRouter->setDebugStream();
        if ($values['type'] == 'RING') {                        // inbound call
            // start test case injection (if you use the -t option)
            empty($testNumbers) ?: $callRouter->getTestInjection();
            $callRouter->runInboundValidation();    // central validation routine
        } elseif ($values['type'] == 'CALL') {
            $callRouter->runOutboundValidation();
        } elseif ($values['type'] != null) {
            $callRouter->setLogging(99, [$values['type']]);
        }
        $callRouter->sendMail();
        $callRouter->refreshSocket();           // do life support during idle
        $callRouter->refreshPhonebooks();
        $callRouter->setCallMonitorValues();
    }
}
