# An extended call routing for AVM FRITZ!Box

The programm is listen to the FRITZ!Box callmonitor.
For an incoming call, it is checked whether the number is already known in the telephone book.
If not, it is checked at tellows if this unknown number has received a bad score (> 5) and more than 3 comments.
If this is the case, the number will be transferred to the corresponding phonebook  (spam) for future rejections.

## Requirements

  * PHP 7.0 (`apt-get install php7.0 php7.0-curl php7.0-mbstring php7.0-xml`)
  * callmonitor (port 1012) is open - dial `#96*5*` to open it

## Installation

Install requirements

    git clone https://github.com/BlackSenator/fbcallrouter.git
    cd fbcallrouter

edit `fbcallrouter.php`  and save it:

    'password'     => 'xxxxxxxx',         // your Fritz!Box user password

## Usage

Test:

    php fbcallrouter.php

Background processing:

    php fbcallrouter.php &

## License
This script is released under MIT license.

## Author
Copyright (c) 2018 Volker Püschel
