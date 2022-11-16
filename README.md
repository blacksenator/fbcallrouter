# An extended call routing for AVM FRITZ!Box

<img align="right" src="assets/washing.png"/>

The program is **trying to identify spam calls**. So it is listen to the FRITZ!Box callmonitor and does several wash cycles to figure out whether it is spam or not.

## Release notes

### Brand new

At the request of a user, an **e-mail notification** has been added. If the call number is unknown, you can get the logging information as an email. Could the number be researched on the web (see next paragraph) - as a spammer or by reverse search - even with the info where it was found as a **deep link**: so you can dive into detailed information to that phone number direct from the e-mail.

If you use allready a previous version please refer to the [update section](#update)!

### Recently added

Instead of just one website, **up to three online directories are now queried** whether the number is listed as spam.
Unfortunately, two of them do not offer an interface and can therefore only be queried via screen scraping. If the providers make changes to the websites and **errors occur as a result, please open an issue here so that the coding can be adapted to the changed websites immediately!**

In addition, a reverse search has now been implemented via the [Das Örtliche](https://www.dasoertliche.de/rueckwaertssuche/) website.

If you already use an older version be aware that the **structure of the configuration file differ** (see [`config.example.php`](/config.example.php)).

## Preconditions

* You use a Fritz!Box for landline telephoning
* You need to have a [separate spam telefon](https://avm.de/service/wissensdatenbank/dok/FRITZ-Box-7590/142_Rufsperren-fur-ankommende-und-ausgehende-Anrufe-z-B-0900-Nummern-einrichten/) book beside your phonebook!
* The program only works in the German telephone network!

## Description

For an incoming call a cascaded check takes place:

* First, it is checked, whether the number is **already known** in one or more of your telephone books (`'whitelist'`). Even known spam numbers (`'blacklist'`) are not analyzed any further.

* If not, than it is checked if it is a **foreign number**. If you have set (`'blockForeign'`) the number will be transferred to the corresponding phonebook (`'blacklist'`) for future rejections.

* Than it is checked if a domestic number has a **valid area code** (ONB*) or celluar code**. Quite often spammers using fake area codes. If so, the number will be transferred to the corresponding phonebook (`'blacklist'`) for future rejections.

* After that it is checked if the **subscribers number is valid** (starting with a zero -> only applies to landline numbers). If so, the number will be transferred to the corresponding phonebook (`'blacklist'`) for future rejections.

* If all this passed, it is checked at various scoring sites (currently three: [tellows](https://www.tellows.de/), [werruft](https://www.werruft.info) and [cleverdialer](https://www.cleverdialer.de)) if this number has received a **bad online rating**. If so, the number will be transferred to the corresponding phonebook (spam) for future rejections.

* Finally, of course, there is the possibility that the caller is known in a positive sense and can be identified via a public telephone book (e.g. [Das Örtliche](https://www.dasoertliche.de/rueckwaertssuche/)). Then he/she/it is optionally entered in a dedicated phone book (`'newlist'`) with the determined name.

The configuration file (default `config.php`) offers various customization options. For example: Adapted to the tellows rating model a bad score is determined as six to nine and needs at least more than three comments. The second parameter is for quality purposes: not a single opinion should block a service provider whose call you might expect.
You can adapt the values in the configuration file. **But be carefull! If you choose score values equal or smaler then five (5), than numbers with impeccable reputation where written to your rejection list!**
Because the rating websites use different scales, they are normalized to the tellows scale.
For example: if we have a rating with five stars, than the `score = -2 * round (stars * 2) / 2 + 11`.

If you set `'log'` in your configuration and the `'logPath'` is valid, the essential process steps for verification are written to the log file `callrouter_logging.txt`. If `'logPath'` is empty the programm directory is default.

*ONB = OrtsNetzBereiche (Vorwahlbereiche/Vorwahlen). The list used is from the [BNetzA](https://www.bundesnetzagentur.de/DE/Fachthemen/Telekommunikation/Nummerierung/ONRufnr/ON_Einteilung_ONB/ON_ONB_ONKz_ONBGrenzen_node.html) and should be valid for a limited period of time. If you want to update them, then download the offered **CSV file** [Vorwahlverzeichnis](https://www.bundesnetzagentur.de/SharedDocs/Downloads/DE/Sachgebiete/Telekommunikation/Unternehmen_Institutionen/Nummerierung/Rufnummern/ONRufnr/Vorwahlverzeichnis_ONB.zip.zip?__blob=publicationFile&v=298). Unpack the archive and save the file as `ONB.csv` in the `./assets` directory.

** The BNetzA do not provide a list for download with celluar codes [(RNB)](https://www.bundesnetzagentur.de/DE/Fachthemen/Telekommunikation/Nummerierung/MobileDienste/zugeteilte%20RNB/MobileDiensteBelegteRNB_Basepage.html?nn=397488#download=1). The currently used ones were transferred to the `./assets` directory as `$cellular Numbers` in `cellular.csv`.

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

The main concept of this tool is to continuously check incoming calls. Therefore it should ideally run permanently as a background process on a single-board computer (e.g. Raspberry Pi) in the home network. A corresponding definition file is prepared: [`fbcallrouter.service`](/fbcallrouter.service)

a) edit `[youruser]` in this file with your device user (e.g. `pi`) and save the file

```console
nano fbcallrouter.service
```

b) copy the file into `/etc/systemd/system`

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

## Update

Change to the installation directory:

```console
cd /home/[youruser]/fbcallrouter
```

Stop the service:

```console
sudo systemctl stop fbcallrouter.service
```

Delete the old logging file (if you used it here):

```console
rm callrouter_logging.txt
```

Get the latest version from:

```console
git pull https://github.com/blacksenator/fbcallrouter.git
```

Bring all used libraries up to date:

```console
composer update --no-dev
```

Check for changes in the configuration file...

```console
nano config.example.php
```

...and eventually make necessary changes/additions in your configuration:

```console
nano config.example.php
```

Restart the service...

```console
sudo systemctl start fbcallrouter.service
```

...and wait few seconds before you check if the service is running:

```console
sudo systemctl status fbcallrouter.service
```

## Does the programm works propperly?

First of all: of course the program can contain bugs and not work as expected. If you are convinced, please open an issue here.
In addition, it can of course be more due to the selected settings for the configuration of the `filter`.
Last but not least I myself have made some observations that led me to suspect that the program would not work correctly. In principle, it is advisable in such cases to **switch on logging** (`'log' => true,`) or to compare the active logging with the call list of the FRITZ!Box. You may need to go a step further and correlate dates with reverse search sites.
An example:
I had calls that should have been identified as spam at first glance through web research. A closer look showed that this spammer only appeared in the lists at exactly that time and had not yet received a sufficient number of negative comments at the time of the call.

## Privacy

No private data from this software will be passed on to me or third parties. The program only transmits incoming telephone numbers to third parties in this exception: when using [tellows](https://www.tellows.de/c/about-tellows-de/datenschutz/), [cleverdialer](https://www.cleverdialer.de/datenschutzerklaerung-website) and [werruft](https://www.werruft.info/bedingungen/), the incoming number is transmitted to the provider. Their data protection information must be observed!

## License

This script is released under MIT license.

## Author

Copyright© 2019 - 2022 Volker Püschel
