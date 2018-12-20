<?php

/* fbcallrouter is a spam killer
 *
 * Author: BlackSenator
 * https://github.com/BlackSenator
 * Status: draft
 *
 * This script is an extension for call routing to/from FRITZ!Box
 * Dependency: callmonitor (port 1012) is open - dial #96*5* to open it
 *
 * The programm is listen to the callmonitor.
 * For an incoming call, it is checked whether the number is already known in the telephone book.
 * If not, it is checked at tellows if this unknown number has received a bad score (> 5) and more than 3 comments.
 * If this is the case, the number will be transferred to the corresponding phonebook for future rejections.
 */


$config = [
    'url'          => '192.168.178.1',    // your Fritz!Box IP
    'user'         => 'dslf_config',      // your Fritz!Box user
    'password'     => 'xxxxxxxx',         // your Fritz!Box user password
    'getPhonebook' => 0,                  // phonebook in which you want to check if this number is already known (first = 0!)
    'setPhonebook' => 2,                  // phonebook in which the spam number should be recorded
    'caller'       => 'autom. gesperrt',  // alias for new caller
    'type'         => 'default',          // type of phone line (home, work, mobil, fax etc.)
    ];


# connect to fritzbox callmonitor port (in a case of error dial #96*5* to open it)
$fbSocket = stream_socket_client($config['url'] . ":1012", $errno, $errstr);
if (!$fbSocket) {
    echo "$errstr ($errno)" . PHP_EOL;
    exit;
    }

// get SOAP client for phonebook operations
$contactClient = getClient ($config['url'], 'x_contact', 'X_AVM-DE_OnTel:1', $config['user'], $config['password']);

// get SOAP client for dial operations
$phoneClient = getClient ($config['url'], 'x_voip', 'X_VoIP:1', $config['user'], $config['password']);

// load current phonebook
$result = $contactClient->GetPhonebook(new SoapParam($config['getPhonebook'], 'NewPhonebookID'));
$phoneBook = @simplexml_load_file($result['NewPhonebookURL']);
$phoneBook->asXML();

// extract just all numbers from the phonebook for a quicker search later on
$currentNumbers = getNumbers($phoneBook);
if (count($currentNumbers) == 0) {
    echo 'The phone book against which you want to check is empty!' . PHP_EOL;
}

// now listen to the callmonitor and wait for new lines
echo 'Cocked and rotated...' . PHP_EOL;
while(true) {
    $newLine = fgets($fbSocket);
    if($newLine != null) {
        $values = explode(";", $newLine);                      // [DATE TIME];[STATUS];[0];[NUMBER];;;
        if ($values[1] == "RING") {                            // incomming call
            if (!in_array($values[3], $currentNumbers)) {      // number is unknown
                if (getRating($values[3]) > 5) {               // bad reputation

                    /* the following part does not work yet
                     *
                     * pick up call
                    $phoneClient->{'X_AVM-DE_DialNumber'}(new SoapParam('*09',"NewX_AVM-DE_PhoneNumber"));
                     * disconnect call
                    $phoneClient->{'X_AVM-DE_DialHangup'}();
                     */

                    // assamble minimal contact structure
                    $xmlEntry = newEntry($values[3],$config['caller'], $config['type']);
                    // add the spam call as new phonebook entry
                    $contactClient->SetPhonebookEntry(
                                        new SoapParam($config['setPhonebook'], 'NewPhonebookID'),
                                        new SoapParam(null, 'NewPhonebookEntryID'),
                                        new SoapParam($xmlEntry, 'NewPhonebookEntryData')
                                        );
                }
            }
        }
    }
    else {
        sleep(1);
    }
}


    /**
     * delivers a new SOAP client
     *
     * @param   string $url       Fritz!Box IP
     * @param   string $location  TR-064 area (https://avm.de/service/schnittstellen/)
     * @param   string $service   TR-064 service (https://avm.de/service/schnittstellen/)
     * @param   string $user      Fritz!Box user
     * @param   string $password  Fritz!Box password
     * @return                    SOAP client
     */

    function getclient ($url, $location, $service, $user, $password) {

        $client = new SoapClient(
                        null,
                        array (
                            'location'   => "http://".$url.":49000/upnp/control/".$location,
                            'uri'        => "urn:dslforum-org:service:".$service,
                            'noroot'     => True,
                            'login'      => $user,
                            'password'   => $password
                        )
                    );
        return $client;
    }

    /**
     * delivers an simple array of numbers from a designated phone book
     *
     * @param   xml    $fbphonebook  downloaded phone book
     * @param   array  $types        phonetypes (e.g. home, work, mobil, fax, fax_work)
     * @return  array                phone numbers
     */

    function getNumbers ($fbPhonebook, $types = array()) {
        
        foreach ($fbPhonebook->phonebook->contact as $contact) {
            foreach ($contact->telephony->number as $number) {
                if((substr($number, 0, 1) == '*') || (substr($number, 0, 1) == '#')) {
                    continue;
                }
                if (isset($types[0])) {
                    if(in_array($number['type'], $types)) {
                        $number = $number[0]->__toString();
                    }
                    else {
                        continue;
                    }
                }
                else {
                    $number = $number[0]->__toString();
                }
                $numbers[] = $number;
            }
        }
        return $numbers;
    }

    /**
     * delivers a minimal contact structure for AVMs TR-064 interface
     *
     * @param   string $number  phone number
     * @param   string $caller  callers name or alias
     * @param   string $type    phone type (home, work, fax etc.)
     * @return  xml             SOAP envelope:
     *                          <?xml version="1.0"?>
     *                          <Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">
     *                              <contact>
     *                                  <person>
     *                                      <realName>$caller</realName>
     *                                  </person>
     *                                  <telephony>
     *                                      <number id="0" type=$type>$number</number>
     *                                  </telephony>
     *                              </contact>
     *                          </Envelope>
     */

    function newEntry ($number, $caller, $type) : SimpleXMLElement {

        $envelope = new simpleXMLElement('<Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"></Envelope>');

        $contact = $envelope->addChild('contact');

        $person = $contact->addChild('person');
        $person->addChild('realName', $caller);

        $telephony = $contact->addChild('telephony');

        $phone = $telephony->addChild('number', $number);
        $phone->addAttribute('id', 0);
        $phone->addAttribute('type', $type);

        return $envelope;
    }

    /**
     * delivers the tellows score if it is above 5 (neutral) and got more than 3 comments
     *
     * @param   string $number    phone number
     * @param   int    $comments  must be three or higher (everything else makes no sense)
     * @return                    score
     */

    function getRating ($number, $comments = 3) {

        $score = 5;
        if ($comments < 3) {
            $comments = 3;
        }
        $rating = @simplexml_load_file("http://www.tellows.de/basic/num/$number?xml=1&partner=test&apikey=test123");
        if ($rating != false) {
            $rating->asXML ();
            if ($rating->score > 5 || $rating->comments >= $comments) {
                $score = $rating->score;
            }
        }
        return $score;
    }

?>