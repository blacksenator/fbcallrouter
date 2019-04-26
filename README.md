# An extended call routing for AVM FRITZ!Box

The programm is listen to the FRITZ!Box callmonitor.
For an incoming call, it is checked whether the number is already known in the telephone book.
If not, it is checked at tellows if this unknown number has received a bad score (> 5) and more than 3 comments.
If this is the case, the number will be transferred to the corresponding phonebook  (spam) for future rejections.

## Requirements

  * PHP 7.0 (`apt-get install php7.0 php7.0-curl php7.0-mbstring php7.0-xml`)
  * callmonitor (port 1012) is open - dial `#96*5*` to open it
  * Composer (follow the installation guide at https://getcomposer.org/download/)

## Installation

Install requirements are:

    git clone https://github.com/BlackSenator/fbcallrouter.git
    cd fbcallrouter

Install composer (see https://getcomposer.org/download/ for newer instructions):

    composer install

Edit the `['config']` section in `fbcallrouter.php` and save it:

    sudo nano fbcallrouter.php

The least essential adaptation is setting your FRITZ!Box password:

    'password'     => 'xxxxxxxx',         // your Fritz!Box user password

## Usage

### Test:

    php fbcallrouter.php

### Permanent background processing:

a) copy the file `fbcallrouter.service` into `/etc/systemd/system`

b) enable the service unit:

    sudo systemctl enable fbcallrouter.service

c) check the status:

    sudo systemctl status fbcallrouter.service

## License
This script is released under MIT license.

## Author
Copyright (c) 2019 Volker Püschel