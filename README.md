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

#### fix-halftime

Parses the games in the scraped JSON and attempts to guess where halftime should be for games that are missing it.

    bin/ultistats fix-halftime

* Change input file

        --file=output.json

* Set a halftime score limit

        --score=8

* Set a halftime time limit in minutes

        --time=50

#### tocsv

Exports the JSON into a series of flat CSV files

    bin/ultistats tocsv

* Change input file

        --file=output.json

* Change output folder

        --folder=csv