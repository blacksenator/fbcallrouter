<?php

namespace blacksenator\callrouter;

/** class dialercheck
 *
 * provides functions to get rating information from various online directories
 * dedicated to identify spammers. In the vast majority of cases, the query
 * takes place via screen scraping, since these websites do not offer no API
 *
 * @copyright (c) 2019 - 2023 Volker Püschel
 * @license MIT
 */

use \SimpleXMLElement;
use \DOMDocument;

class dialercheck
{
    const WERRUFT = [
        'https://www.werruft.info/telefonnummer/',
        'WERRUFT',
        [
            'Negativ'    => '9',
            'Verwirrend' => '7',
            'Unbekannte' => '5',
            'Egal'       => '3',
            'Positiv'    => '1',
        ],
    ];
    const CLVRDLR = [
        'https://www.cleverdialer.de/telefonnummer/',
        'Clever Dailer',
    ];
    const TELSPIO = [
        'https://www.telefonspion.de/',
        'Telefonspion',
    ];
    //const WRRFTAN = 'https://wer-ruftan.de/Nummer/';                  // offen
    //const WMGEHRT = 'https://www.wemgehoert.de/nummer/'               // offen
    const WRHTANG = [
        'https://www.werhatangerufen.com/',
        'WerHatAngerufen',
    ];
    const TELLOWS = [
        'http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123',
        'https://www.tellows.de/num/',
        'tellows',
    ];
    const DSOERTL = [
        'https://www.dasoertliche.de/rueckwaertssuche/?ph=',
        'https://www.dasoertliche.de/?form_name=search_inv&ph=',
        'Das Örtliche'
    ];

    private $score;                     // rating normalized to tellows (1 - 9)
    private $comments;                  // in most cases number of valuations

    public function __construct(array $filter)
    {
        $this->score = $filter['score'] > 9 ? 9 : $filter['score'];
        $this->comments = $filter['comments'] < 3 ? 3 : $filter['comments'];
    }

    /**
     * assamble the deeplink string
     *
     * @param string $url
     * @param string $label
     * @return string
     */
    private function getDeepLinkString(string $url, string $label)
    {
        return 'Number traced in: <a href="' . $url . '">' . $label . '</a>';
    }

    /**
     * return the tellows rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    private function getTellowsRating(string $number)
    {
        $url = sprintf(self::TELLOWS[0], $number);
        if (($rating = @simplexml_load_file($url)) == false) {
            return false;
        }
        $rating->asXML();
        $url = self::TELLOWS[1] . $number;

        return [
            'score'    => (string)$rating->score,
            'comments' => (string)$rating->comments,
            'url'      => $url,
            'deeplink' => $this->getDeepLinkString($url, self::TELLOWS[2]),
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
     * @return SimpleXMLelement|bool
     */
    private function getWebsiteAsXML(string $url)
    {
        $html = @file_get_contents($url);

        return !$html ? false : $this->convertHTMLtoXML($html);
    }

    /**
     * returns the equivalent from one of/to five stars to the score from one to
     * nine, where five stars are a score of one and one star is the score of nine
     *
     * @param string|float $stars
     * @return float as 1 .. 9
     */
    private function convertStarsToScore($stars)
    {
        return round(intval($stars) * 2) / 2 * -2 + 11;
    }

    /**
     * return the werruft.info rating and number of comments with screen
     * scraping of
     *  <div id="commentstypelist">
     *      <span><i class="comop1">0</i> Negativ</span>
     *      <span><i class="comop2">0</i> Verwirrend</span>
     *      <span><i class="comop3">0</i> Unbekannte</span>
     *      <span><i class="comop4">0</i> Egal</span>
     *      <span><i class="comop5">0</i> Positiv</span>
     *  </div>
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    private function getWerRuftInfoRating(string $number)
    {
        $url = self::WERRUFT[0] . $number . '/';
        if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
            return false;
        }
        if (count($comments = $rawXML->xpath('//i[contains(@class, "comop")]')) != 5) {
            return false;
        }
        $totalComment = 0;
        foreach($comments as $comment) {
            $totalComment += (int)$comment;
        }
        $title = $rawXML->xpath('//title');
        if (preg_match('/\((.*?)\:/', $title[0], $match)) {
            $score = strtr($match[1], self::WERRUFT[2]);
        } else {
            return false;
        }

        return [
            'score'    => intval($score),
            'comments' => $totalComment,
            'url'      => $url,
            'deeplink' => $this->getDeepLinkString($url, self::WERRUFT[1]),
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
        $url = self::CLVRDLR[0] . $number;
        if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
            return false;
        }
        if (count($valuation = $rawXML->xpath('//div[@class = "rating-text"]'))) {
            $stars = str_replace(' von 5 Sternen', '', $valuation[0]->span[0]);
            if (intval($stars) > 0) {
                $comments = preg_replace('/[^0-9]/', '', $valuation[0]->span[1]);
                return [
                    'score'    => $this->convertStarsToScore($stars),
                    'comments' => $comments,
                    'url'      => $url,
                    'deeplink' => $this->getDeepLinkString($url, self::CLVRDLR[1]),
                ];
            }
        }

        return false;
    }

    /**
     * return the telefonspion rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    private function getTelefonSpionRating(string $number)
    {
        $url = self::TELSPIO[0] . $number;
        if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
            return false;
        }
        $valuations = $rawXML->xpath('//div[@class = "count"]');
        if (count($valuations) == 2) {
            return [
                'score'    => -0.8 * intval($valuations[1]) + 9,
                'comments' => (string)$valuations[0],
                'url'      => $url,
                'deeplink' => $this->getDeepLinkString($url, self::TELSPIO[1]),
            ];
        }

        return false;
    }

    /**
     * return the werhatangerufen rating and number of comments
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    private function getWerHatAngerufenRating(string $number)
    {
        $url = self::WRHTANG[0] . $number;
        if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
            return false;
        }
        $valuation = $rawXML->xpath('//span[@class="rating"]');
        if (count($valuation)) {
            $stars = substr(str_replace('Bewertung: ', '', $valuation[0]), 0, 1);
            if (intval($stars) > 0) {
                $titleParts = explode(' // ', $rawXML->xpath('//title')[0]);
                if (count($titleParts) == 2) {
                    return [
                        'score'    => $this->convertStarsToScore($stars),
                        'comments' => str_replace(' Bewertungen', '', $titleParts[1]),
                        'url'      => $url,
                        'deeplink' => $this->getDeepLinkString($url, self::WRHTANG[1]),
                    ];
                }
            }
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
        if ($rating['score'] >= $this->score &&
            $rating['comments'] >= $this->comments) {
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
     * @return array|bool
     */
    public function getRating(string $number)
    {
        if ($rating = $this->getWerRuftInfoRating($number)) {
            if ($this->proofRating($rating)) {
                return $rating;
            }
        }
        if ($rating = $this->getCleverDialerRating($number)) {
            if ($this->proofRating($rating)) {
                return $rating;
            }
        }
        if ($rating = $this->getTelefonSpionRating($number)) {
            if ($this->proofRating($rating)) {
                return $rating;
            }
        }
        if ($rating = $this->getWerHatAngerufenRating($number)) {
            if ($this->proofRating($rating)) {
                return $rating;
            }
        }
        if ($rating = $this->getTellowsRating($number)) {
            return $rating;
        }

        return false;
    }

    /**
     * return the name from Das Örtliche
     *
     * @param string $number phone number
     * @return array|bool $score array of rating and number of comments or false
     */
    public function getDasOertliche(string $number)
    {
        $url = self::DSOERTL[0] . $number;
        if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
            $url = self::DSOERTL[1] . $number;                  // second attemp
            if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
                return false;
            }
        }
        if ($rawXML->xpath('//div[@class="nonumber01"]')) {         // unknown
            return false;
        }
        if ($result = $rawXML->xpath('//div[@class="nonumber"]')) { // alternative
            if (($altNumber = filter_var($result[0]->p, FILTER_SANITIZE_NUMBER_INT)) != '') {
                $url = self::DSOERTL[1] . $altNumber;
                if (($rawXML = $this->getWebsiteAsXML($url)) == false) {
                    return false;
                }
            }
        }
        if ($result = $rawXML->xpath('//a[@class="hitlnk_name"]')) {
            return [
                'name'     => trim($result[0]),
                'url'      => $url,
                'deeplink' => $this->getDeepLinkString($url, self::DSOERTL[2]),
            ];
        }

        return false;
    }
}
