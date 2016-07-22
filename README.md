# Ultiorganizer stats scraper

Scrapes a Ultiorganizer instance and generates some statistics.

## Usage

### To install

    git clone git@github.com:hermzz/ultiorganizer-stats.git
    cd ultiorganizer-stats/
    composer update

### Commands

#### scrape

The URL provided to scrape needs to be the list of all teams by division.

    bin/ultistats scrape "http://scores.wugc2016.com/?view=teams&season=WUGC16&list=allteams"

* Disable caching

      --cache=false

* Change output file

      --file=someotherfile.json

* Skip divisions

      --skip=Guts --skip=Men

* Verbose logging

      -v