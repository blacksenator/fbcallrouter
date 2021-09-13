# An extended call routing for AVM FRITZ!Box

<img align="right" src="assets/washing.png"/>

The program is trying to identify spam calls. So it is listen to the FRITZ!Box callmonitor and does several wash cycles to figure out whether it is spam or not.

## Release note

This version uses a **configuration file with a modified structure** (see config.example.php)!

## Preconditions

* You need to have a separate spam telefon book beside your phonebook!
* The program only works in the German telephone network!

## Description

For an incoming call a cascaded check takes place:

* First, it is checked, whether the number is already known in one of your telephone books (`'whitelist'`) Even known spam numbers (`'blacklist'`) are not analyzed any further.

* If not, than it is checked if it is a foreign number. If you have set (`'blockForeign'`) the number will be transferred to the corresponding phonebook (`'blacklist'`) for future rejections.

* Than it is checked if a domestic number has a valid area code (ONB*) or celluar code**. Quite often spammers using fake area codes. If so, the number will be transferred to the corresponding phonebook (`'blacklist'`) for future rejections.

* After that it is checked if the subribers number is valid or starting with a zero (only applies to landline numbers). If so, the number will be transferred to the corresponding phonebook (`'blacklist'`) for future rejections.

* If all this passed, it is checked at [tellows](https://www.tellows.de/) if this number has received a bad score (six to nine) and at least more than three comments. You can adapt the values in the configuration file.
The second parameter is for quality purposes: not a single opinion there should block a service provider whose call you might expect.
But if the score is proven bad according to your settings, the number will be transferred to the corresponding phonebook (spam) for future rejections.

If you set `'log'` in your config and the `'logPath'` is valid, the essential process steps for verification are written to the log file `callrouter_logging.txt`. If `'logPath'` is empty the programm directory is default.

*ONB = OrtsNetzBereiche (Vorwahlbereiche/Vorwahlen). The list used is from the [BNetzA](https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/ON_Einteilung_ONB/ON_ONB_ONKz_ONBGrenzen_Basepage.html) and should be valid for a limited period of time. If you want to update them, then download the offered **CSV file** (Vorwahlverzeichnis). Unpack the archive (if necessary in the archive) and save the file as `ONB.csv` in the `./assets` directory.

** The [BNetzA](https://www.bundesnetzagentur.de/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/MobileDienste/zugeteilte%20RNB/MobileDiensteBelegteRNB_Basepage.html) provided no list for download celluar codes (RNB). Those are recently set fix as `$celluarNumbers` in `celluar.csv` in the `./assets` directory.

## Requirements

* PHP >= 7.0 (php-cli, php-curl, php-mbstring, php-soap, php-xml)
* callmonitor (port 1012) is open - if not: dial `#96*5*` to open it
* Composer (follow the installation guide at <https://getcomposer.org/download/)>

## Installation

### Programm

Install requirements are:

```console
git clone https://github.com/blacksenator/fbcallrouter.git
cd fbcallrouter
```

Install composer (see <https://getcomposer.org/download/> for newer instructions):

```console
composer install --no-dev --no-suggest
```

Edit `config.example.php` and save as `config.php` or use an other name of your choice (but than keep in mind to use the -c option to define your renamed file)

### Preconditions on the FRITZ!Box

If you do not have your own phonebook for spam numbers, it is essential to add a new one (e.g. "Spamnummern"). Note that the first phonebook ("Telefonbuch") has the number "0" and the numbers are ascending according to the index tabs. Then you have to link this phonebook for call handling: Telefonie -> Rufbehandlung -> Neue Regel -> ankommende Rufe | Bereich: "Telefonbuch" | Telefonbuch: "Spamnummern"

The programm accessed the Fritz!Box via TR-064. Make sure that your user is granted for this interface. The proposed user `dslf_config` is the default user when logging in with a password and without user selection and has the required rights!

## Usage

### Test

#### Function test

```console
php fbcallrouter run
```

If `On guard...` appears, the program is armed.

Make a function test in which you call your landline from your cell phone: your mobile phone number **should not** end up in the spam phone book!
If the number is in your phone book, nothing should happen anyway (on whitelist).
Otherwise: your mobile phone number is not a foreign number, the ONB/RNB is correct and you certainly do not have a bad entry in tellows. Therefore these tests should not lead to any sorting out.
If you do not receive an error message, then at least all the wash cycles have been run through once.
To cancel, press `CTRL+C`.

If logging is enabled, than `nano callrouter_logging.txt` will show you what happend.

#### Integration test

There are five exemplary `'numbers'` stored in the configuration file with which you can test the wash cycles integratively. You can change these test numbers according to your own ideas and quantity. Starting the programm with the `-t` option, these numbers will be injected as substitutes for the next calls the FRITZ!Box receives and its callmonitor port will broadcast.

```console
php fbcallrouter run -t
```

It is highly recommended to proceed like this:

1. check if none of the substitutes are allready in your phonebook (especially if they repeat the test)!
2. if you want to provoke a tellows query, check that the number actually has the desired score and comments
3. use your celluar phone to call your landline. The incoming mobil number will be replaced with the first/next number from this array and passes through the inspection process. Additional information is output like this:

```console
Starting FRITZ!Box call router...
On guard...
Running test case 1/5
```

So you have to call as many times as there are numbers in the array to check all test cases (or quit programm execution with `CTRL+C`). The program then ends this number replacement.

Check the blacklist phone book whether all numbers have been entered as expected. If logging is enabled, than `nano callrouter_logging.txt` will show you what happend.
To cancel, press `CTRL+C`.

### Permanent background processing

The main concept of this tool is to continuously check incoming calls. Therefore it should ideally run permanently as a background process on a single-board computer (e.g. Raspberry Pi) in the home network:

a) edit `[youruser]` in `fbcallrouter.service` with your device user (e.g. `pi`) and save the file

```console
nano fbcallrouter.service
```

b) copy the file `fbcallrouter.service` into `/etc/systemd/system`

```console
sudo cp fbcallrouter.service /etc/systemd/system/fbcallrouter.service
```

c) enable the service unit:

```console
sudo systemctl enable fbcallrouter.service
```

d) check the status:

```console
sudo systemctl status fbcallrouter.service
```

## License

This script is released under MIT license.

## Author

Copyright© 2019 - 2021 Volker Püschel
