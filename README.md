# An extended call routing for AVM FRITZ!Box

The programm is trying to identify spam calls. So it is listen to the FRITZ!Box callmonitor and does several washes.

**The program only works in the German telephone network!**

For an incoming call a cascaded check takes place:
First, it is checked, whether the number is already known in one of your telephone books (`'getPhonebook'`).

If not,  than it is checked if the caller used a valid area code (ONB*) or celluar code**. Quite often spammers using fake area codes. If so, the number will be transferred to the corresponding phonebook (`'setPhonebook'`) for future rejections.

If the area code is valid, it is checked at [tellows](https://www.tellows.de/) if this number has received a bad score ( six to ten) and at least more than three comments. You can adapt the values in the configuration file.
The second parameter is for quality purposes: not a single opinion there should block a service provider whose call you might expect.
But if the score is proven bad, the number will be transferred to the corresponding phonebook (spam) for future rejections.

If you´re setting `'logging'` with a valid path the essential process steps for verification are written to the log file `callrouter_logging.txt`. E.g. `'./'` will write the logging file in the program directory.

*ONB = OrtsNetzBereiche (Vorwahlbereiche/Vorwahlen). The list used is from the [BNetzA](https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/ON_Einteilung_ONB/ON_ONB_ONKz_ONBGrenzen_Basepage.html) and should be valid for a limited period of time. If you want to update them, then download the CSV file offered. Unpack the archive (if necessary in the archive) and save the file as ONB.csv in the `./assets` directory.

**celluar codes are recently set fix as const `CELLUAR` in `callrouter.php`. The [BNetzA](https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/MobileDienste/zugeteilte%20RNB/MobileDiensteBelegteRNB_Basepage.html) provided no list for download.

## Requirements

  * PHP >= 7.0
  * callmonitor (port 1012) is open - if not: dial `#96*5*` to open it
  * Composer (follow the installation guide at https://getcomposer.org/download/)

## Installation

### Programm

Install requirements are:

    git clone https://github.com/blacksenator/fbcallrouter.git
    cd fbcallrouter

Install composer (see https://getcomposer.org/download/ for newer instructions):

    composer install

Edit `config.example.php` and save as `config.php` or use an other name of your choice (but than keep in mind to use the -c option to define your renamed file)
The least essential adaptation is setting your FRITZ!Box password:

    'password'     => 'xxxxxxxxx',         // your Fritz!Box user password

### FRITZ!Box

If you do not have your own phonebook for spam numbers, add a new one (e.g. "Spamnummern"). Note that the first phonebook ("Telefonbuch") has the number "0" and the numbers are ascending according to the index tabs. Then you have to link this phonebook for call handling: Telefonie -> Rufbehandlung -> Neue Regel -> ankommende Rufe | Bereich: "Telefonbuch" | Telefonbuch: "Spamnummern"

## Usage

### Test:

    php fbcallrouter run

If `Cocked and unlocked...` appears, the program is armed. To cancel, press CTRL+C.

### Permanent background processing:

a) edit `user=` in `fbcallrouter.service` and save it

    nano fbcallrouter.service

b) copy the file `fbcallrouter.service` into `/etc/systemd/system`

    sudo cp fbcallrouter.service /etc/systemd/system/fbcallrouter.service

c) enable the service unit:

    sudo systemctl enable fbcallrouter.service

d) check the status:

    sudo systemctl status fbcallrouter.service

## License
This script is released under MIT license.

## Author
Copyright (c) 2019 Volker Püschel