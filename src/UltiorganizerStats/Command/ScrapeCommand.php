<?php

namespace UltiorganizerStats\Command;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Strategy\GreedyCacheStrategy;
use Kevinrob\GuzzleCache\Storage\FlysystemStorage;
use League\Flysystem\Adapter\Local;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScrapeCommand extends Command
{
    /**
     * @var InputInterface $input
     */
    protected $input;

    /**
     * @var OutputInterface $output
     */
    protected $output;

    /**
     * @var Client $httpClient
     */
    protected $httpClient;

    /**
     * @var array $urlStructure   Return value of parse_url() for the `url` argument
     */
    protected $urlStructure;

    protected function configure()
    {
        $this
            ->setName('scrape')
            ->setDescription('Scrape a Ultiorganizer instance')
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'URL of the event homepage by divison'
            )
            ->addOption(
                'cache',
                null,
                InputOption::VALUE_OPTIONAL,
                'Cache the requested HTML',
                true
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $stack = null;
        if ($this->input->getOption('cache') === true) {
            $stack = HandlerStack::create();
            $stack->push(
                new CacheMiddleware(
                    new GreedyCacheStrategy(
                        new FlysystemStorage(
                            new Local(__DIR__.'/cache/')
                        ),
                        60 * 60 * 24
                    )
                ),
                'cache'
            );
        }

        $this->httpClient = new Client(['handler' => $stack]);

        $this->urlStructure = parse_url($this->input->getArgument('url'));

        $doc = $this->get($this->input->getArgument('url'));
        $divisions = $this->scrapeDivisions($doc);
        $players = $this->scrapePlayers($divisions);
        $games = $this->scrapeGames($divisions);

        file_put_contents(
            __DIR__.'/output.json',
            json_encode(
                [
                    'divisions' => $divisions,
                    'players' => $players,
                    'games' => $games
                ],
                JSON_PRETTY_PRINT
            )
        );
    }

    /**
     * Generates a list of the divisions and the teams in them
     *
     * @param \DOMDocument
     * @return array
     */
    protected function scrapeDivisions(\DOMDocument $doc)
    {
        $xpath = new \DOMXpath($doc);
        $query = $xpath->query('//td[@class="tdcontent"]/div/table/tr/th');

        $divisions = [];
        $divisionCount = $teamCount = 0;
        foreach ($query as $node) {
            $divisionName = $node->textContent;

            $teamRow = $node->parentNode->nextSibling;
            while (!is_null($teamRow)) {
                $teamLink = $teamRow->firstChild->firstChild;
                preg_match('/team=([0-9]+)/i', $teamLink->getAttribute('href'), $matches);

                $divisions[$divisionName][] = [
                    'id' => (int) $matches[1],
                    'name' => $teamLink->textContent
                ];

                $teamRow = $teamRow->nextSibling;

                $teamCount++;
            }

            $divisionCount++;
        }

        $this->output->writeln('Found ' . $divisionCount . ' divisions and ' . $teamCount . ' teams');

        return $divisions;
    }

    /**
     * Goes through each team's roster page and
     * generates a list of players and their IDs
     *
     * @param array $divisions
     * @return array
     */
    protected function scrapePlayers(array $divisions)
    {
        $players = [];

        foreach ($divisions as $teams) {
            foreach ($teams as $team) {
                $doc = $this->get($this->generateRosterUrl($team['id']));

                $xpath = new \DOMXpath($doc);
                $query = $xpath->query('//td[@class="tdcontent"]/div/table/tr/td/a');

                foreach ($query as $node) {
                    preg_match('/player=([0-9]+)/i', $node->getAttribute('href'), $matches);

                    $players[] = [
                        'id' => (int) $matches[1],
                        'name' => $node->textContent
                    ];
                }
            }
        }

        return $players;
    }

    /**
     * Goes through each team's games list and
     * generates a list of their games
     *
     * @param array $divisions
     * @return array
     */
    protected function scrapeGames(array $divisions)
    {
        $games = [];

        foreach ($divisions as $teams) {
            foreach ($teams as $team) {
                $doc = $this->get($this->generateTeamUrl($team['id']));

                $xpath = new \DOMXpath($doc);
                $query = $xpath->query('//td[@class="tdcontent"]/div/table');

                // Skip the first TR header
                foreach ($query as $node) {
                    $gameLinks = $xpath->query('tr/td[last()]/span/a', $node);
                    foreach ($gameLinks as $gameLink) {
                        preg_match('/game=([0-9]+)/i', $gameLink->getAttribute('href'), $matches);

                        if (count($matches) > 0 && !$this->gameExists($games, (int) $matches[1])) {
                            $games[] = $this->scrapeGame((int) $matches[1]);
                        }
                    }
                }
            }
        }

        return $games;
    }

    /**
     * Goes through a game page and extracts
     * the following info:
     *   game ID (id)
     *   teams participating (homeTeam|awayTeam)
     *   who started on offence (onOffence)
     *   a list of scores: (scores)
     *     what team scored (scoringTeam)
     *     who scored (score)
     *     who threw the assist (assist)
     *     when it started (start)
     *     how long it lasted (duration)
     *     timeouts called (timeouts)
     *       by whom (by)
     *       when (at)
     *
     * @param int $gameId
     * @return array
     */
    protected function scrapeGame($gameId)
    {
        $doc = $this->get($this->generateGameUrl($gameId));

        $xpath = new \DOMXpath($doc);

        /* Figure out home/away team IDs */
        $query = $xpath->query('//td[@class="tdcontent"]/div/p[last()]/a');
        
        preg_match('/team1=([0-9]+)&team2=([0-9]+)/i', $query->item(0)->getAttribute('href'), $matches);
        $homeTeamId = (int) $matches[1];
        $awayTeamId = (int) $matches[2];

        /* Map player names to IDs */
        $players = [];
        $query = $xpath->query('//td[@class="tdcontent"]/div/table/tr/td/table/tr/td[2]/a');
        foreach ($query as $node) {
            preg_match('/player=([0-9]+)/', $node->getAttribute('href'), $matches);
            $players[$this->cleanText($node->textContent)] = (int) $matches[1];
        }

        /* Store score/assists */
        $query = $xpath->query('//td[@class="tdcontent"]/div/table[3]');
        $scoreTable = $query->item(0);

        $scoreRows = $xpath->query('tr', $scoreTable);
        $scores = [];
        foreach ($scoreRows as $row) {
            $children = $row->childNodes;
            if ($children->item(0)->tagName == 'th') {
                continue;
            }

            if ($children->item(0)->getAttribute('colspan') == "6") {
                $scores[] = 'halftime';
                continue;
            }

            /**
             * Start/duration of points
             * Bizarrely, ultiorganizer shows when the point ended
             * not when it started, hence why the subtraction for the
             * `started` key in `$scores`
             */
            $time = $this->parseTime($children->item(3)->textContent);
            $duration = $this->parseTime($children->item(4)->textContent);

            $timeouts = [];
            $query = $xpath->query('td[6]/div[contains(., "Timeout")]', $row);
            if ($query->length > 0) {
                foreach ($query as $div) {
                    preg_match('/([0-9\.]+)$/', $div->textContent, $matches);
                    $timeouts[] = [
                        'at' => $this->parseTime($matches[1]),
                        'by' => $div->getAttribute('class') == 'guest' ? 'away' : 'home'
                    ];
                }
            }

            $assistName = $this->cleanText($children->item(1)->textContent);
            $assist = isset($players[$assistName]) ? $players[$assistName] : null ;

            if ($children->item(1)->getAttribute('class') == 'callahan') {
                $assist = 'callahan';
            }

            $scoreName = $this->cleanText($children->item(2)->textContent);
            $score = isset($players[$scoreName]) ? $players[$scoreName] : null ;

            $scores[] = [
                'scoringTeam' => $children->item(0)->getAttribute('class') == 'guest' ? 'away' : 'home',
                'assist' => $assist,
                'score' => $score,
                'started' => $time - $duration,
                'duration' => $duration,
                'timeouts' => $timeouts
            ];
        }

        /* Figure out who started on offence */
        $div = $xpath->query('tr/td/div', $scoreTable);
        if ($div->length > 0) {
            $onOffence = $div->item(0)->getAttribute('class') == 'guest' ? 'away' : 'home';
        } else {
            // No offence notification, just assume offence was whoever scored first
            $onOffence = $scores[0]['scoringTeam'];
        }

        $game = [
            'id' => $gameId,
            'homeTeam' => $homeTeamId,
            'awayTeam' => $awayTeamId,
            'onOffence' => $onOffence,
            'scores' => $scores
        ];

        return $game;
    }

    /**
     * Returns true if `$gameId` belongs to an entry in `$games`
     *
     * @param array $games
     * @param int $gameId
     * @return bool
     */
    protected function gameExists(array $games, $gameId)
    {
        foreach ($games as $game) {
            if ($game['id'] === $gameId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes a `minute.second` format and converts it into seconds
     *
     * @param string $time
     * @return int
     */
    protected function parseTime($time)
    {
        list($minutes, $seconds) = explode('.', $time);

        return ((int) $minutes * 60) + (int) $seconds;
    }

    /**
     * Ultiorganizer likes to put &nbsp; in weird places.
     * The DOM parser likes to convert those into strange spaces.
     * This function turns them back into normal spaces and
     * trim()'s for good measure
     *
     * @param string $text
     * @return string
     */
    protected function cleanText($text)
    {
        return trim(str_replace("\xC2\xA0", ' ', $text));
    }

    /**
     * Performs a GET request for `$url`, returns the body
     *
     * @param string $url
     * @return string
     */
    protected function get($url)
    {
        $this->output->writeln('Fetching: ' . $url, OutputInterface::VERBOSITY_VERBOSE);

        $request = $this->httpClient->request('GET', $url);

        if ($request->getStatusCode() !== 200) {
            $this->output->writeln('Could not fetch '.$url);
            exit();
        }

        $doc = new \DOMDocument;
        @$doc->loadHTML($request->getBody());

        return $doc;
    }

    /**
     * Generates a team page URL
     *
     * @param int $teamId
     * @return string
     */
    protected function generateTeamUrl($teamId)
    {
        return sprintf(
            '%s://%s%s?view=games&team=%d',
            $this->urlStructure['scheme'],
            $this->urlStructure['host'],
            $this->urlStructure['path'],
            $teamId
        );
    }

    /**
     * Generates a roster page URL
     *
     * @param int $teamId
     * @return string
     */
    protected function generateRosterUrl($teamId)
    {
        return sprintf(
            '%s://%s%s?view=playerlist&team=%d',
            $this->urlStructure['scheme'],
            $this->urlStructure['host'],
            $this->urlStructure['path'],
            $teamId
        );
    }

    /**
     * Generates a game page URL
     *
     * @param int $gameId
     * @return string
     */
    protected function generateGameUrl($gameId)
    {
        return sprintf(
            '%s://%s%s?view=gameplay&game=%d',
            $this->urlStructure['scheme'],
            $this->urlStructure['host'],
            $this->urlStructure['path'],
            $gameId
        );
    }
}