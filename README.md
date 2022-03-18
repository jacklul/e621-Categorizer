# e621 Categorizer

This script will categorize your art collection based on rating (explicit, questionable, safe) and character interaction tags (male/female etc.). Optionally allows to require certain tags to be present (like species tags).

## Requirements

- **PHP** - https://www.php.net/downloads.php
- **Composer** - https://getcomposer.org

## Installation

- Clone this repository
- Run `composer install`
- Run `composer global require clue/phar-composer`
- Run `composer build`
- Copy `config.example.cfg` into `config.cfg` and fill out the login details inside and change any settings to your liking
- Run the script with `php e621categorizer.phar C:/artcollection` command
