<?php

namespace blacksenator\callrouter;

/** class infomail
 *
 * delivers simple e-mail function based on PHPMailer
 *
 * @copyright (c) 2022 - 2023 Volker Püschel
 * @license MIT
 */

use PHPMailer\PHPMailer\PHPMailer;

class infomail
{
    private $mail;

    public function __construct(array $account)
    {
        date_default_timezone_set('Etc/UTC');
        $this->mail = new PHPMailer(true);
        $this->mail->CharSet = "text/html; charset=UTF-8;";
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
     * send email
     *
     * @param array $callMonitorValues
     * @param array $protocoll
     * @return string|void
     */
    public function sendMail(array $callMonitorValues, array $protocol)
    {
        $number = $callMonitorValues['extern'];
        $way = 'from' ? $callMonitorValues['type'] == 'RING' : 'to';
        $body = nl2br('The following results and actions have been recorded:' . PHP_EOL . PHP_EOL);
        foreach ($protocol as $lines) {
            $body .= nl2br($lines . PHP_EOL);
        }
        $this->mail->Subject = sprintf('fbcallrouter traced a call %s number %s', $way, $number);
        $this->mail->Body = $body;
        $this->mail->isHTML(true);
        if (!$this->mail->send()) {     // send the message, check for errors
            return 'Mailer Error: ' . $this->mail->ErrorInfo;
        }

        return null;
    }
}
