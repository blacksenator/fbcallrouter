<?php

namespace blacksenator\callrouter;

/**
 * class infomail delivers a simple function based on PHPMailer
 *
 * Copyright (c) 2022 Volker Püschel
 * @license MIT
 */

use PHPMailer\PHPMailer\PHPMailer;

class infomail
{
    const EMAIL_SBJCT = 'fbcallrouter traced a call from number ';

    private $mail;

    public function __construct($account)
    {
        date_default_timezone_set('Etc/UTC');
        $this->mail = new PHPMailer(true);
        $this->mail->CharSet = 'UTF-8';
        $this->mail->isSMTP();                                  // tell PHPMailer to use SMTP
        $this->mail->SMTPDebug  = $account['debug'];
        $this->mail->Host       = $account['url'];              // set the hostname of the mail server
        $this->mail->Port       = $account['port'];             // set the SMTP port number - likely to be 25, 465 or 587
        $this->mail->SMTPSecure = $account['secure'];
        $this->mail->SMTPAuth   = true;                         // whether to use SMTP authentication
        $this->mail->Username   = $account['user'];             // username to use for SMTP authentication
        $this->mail->Password   = $account['password'];         // password to use for SMTP authentication
        $this->mail->setFrom($account['user'], 'fbcallrouter'); // set who the message is to be sent fromly-to address
        $this->mail->addAddress($account['receiver']);          // set who the message is to be sent to
    }

    /**
     * send mail
     *
     * @param string $number
     * @param array $protocoll
     * @return string|void
     */
    public function sendMail($number, $protocol)
    {
        $body = 'The following results and actions have been recorded:' . PHP_EOL . PHP_EOL;
        foreach ($protocol as $lines) {
            $body .= $lines . PHP_EOL;
        }
        $this->mail->Subject = self::EMAIL_SBJCT . $number;     //Set the subject line
        $this->mail->Body = $body;
        if (!$this->mail->send()) {     // send the message, check for errors
            return 'Mailer Error: ' . $this->mail->ErrorInfo;
        }

        return null;
    }
}
