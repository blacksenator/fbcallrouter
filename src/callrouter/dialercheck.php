<?php

namespace blacksenator\callrouter;

/** class phone
 *
 * Copyright (c) 2019 - 2022 Volker Püschel
 * @license MIT
 */

use \SimpleXMLElement;
use \DOMDocument;

class dialercheck
{
    const TELLOWS = 'http://www.tellows.de/basic/num/%s?xml=1&partner=test&apikey=test123';
    const CLVRDLR = 'https://www.cleverdialer.de/telefonnummer/';
    const WRRFTAN = 'https://www.werruft.info/telefonnummer/';
    const DSORTL1 = 'https://www.dasoertliche.de/rueckwaertssuche/?ph=';
    const DSORTL2 = 'https://www.dasoertliche.de/?form_name=search_inv&ph=';

    private $score;
    private $comments;

    public function __construct(array $filter)
    {
        $this->score = $filter['score'] > 9 ? 9 : $filter['score'];
        $this->comments = $filter['comments'] < 3 ? 3 : $filter['comments'];
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
     * @return SimpleXMLelement|bool
     */
    private function getWebsiteAsXML(string $url)
    {
        $html = @file_get_contents($url);
        /*
        if (!$html) {
            return false;
        }

        return $this->convertHTMLtoXML($html);
        */
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
        return round($stars * 2) / 2 * -2 + 11;
    }

    /**
     * return the werruft.info rating and number of comments with screen
     * scraping of
     *  <div id="commentstypelist">
     *      <span><i class="comop1">10</i> Negativ</span>
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
        if ($rating = $this->getCleverDialerRating($number)) {
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
        if (!$rawXML = $this->getWebsiteAsXML(self::DSORTL1 . $number)) {
            if (!$rawXML = $this->getWebsiteAsXML(self::DSORTL2 . $number)) {
                return false;
            }
        }
        if ($rawXML->xpath('//div[@class="nonumber01"]')) {         // unbekannt
            return false;
        }
        if ($result = $rawXML->xpath('//div[@class="nonumber"]')) { // alternative
            if (($altNumber = filter_var($result[0]->p, FILTER_SANITIZE_NUMBER_INT)) != '') {
                if (!$rawXML = $this->getWebsiteAsXML(self::DSORTL2 . $altNumber)) {
                    return false;
                }
            }
        }
        if ($result = $rawXML->xpath('//a[@class="hitlnk_name"]')) {
            return trim($result[0]);
        }

        return false;
    }
}
