<?php

namespace UltiorganizerStats\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ToCsvCommand extends Command
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
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('to-csv')
            ->setDescription('Converts JSON output into a set of CSV files')
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Input JSON file to convert',
                OUTPUT_FILE
            )
            ->addOption(
                'folder',
                null,
                InputOption::VALUE_OPTIONAL,
                'Destination folder for the CSV files',
                ROOT_DIR . 'csv/'
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $data = json_decode(file_get_contents($this->input->getOption('file')), true);

        if (!is_dir($this->input->getOption('folder'))) {
            mkdir($this->input->getOption('folder'));
        }

        $this->exportTeams($data);
        $this->exportPlayers($data);
        $this->exportGames($data);
    }

    /**
     * Export the list of teams to a CSV file
     *
     * @param array $data
     */
    protected function exportTeams($data)
    {
        $teamList = [['ID', 'Division', 'Name']];
        foreach ($data['divisions'] as $division => $teams) {
            foreach ($teams as $team) {
                $teamList[] = [$team['id'], $division, $team['name']];
            }
        }

        $this->saveCsv($teamList, 'teams');
    }

    /**
     * Export the list of players to a CSV file
     *
     * @param array $data
     */
    protected function exportPlayers($data)
    {
        $playerList = [['ID', 'Name', 'Team']];
        foreach ($data['players'] as $player) {
            $playerList[] = $player;
        }

        $this->saveCsv($playerList, 'players');
    }

    /**
     * Export the list of games, scores, and timeouts to a set of CSV files
     *
     * @param array $data
     */
    protected function exportGames($data)
    {
        $gameList = [['ID', 'Home', 'Away', 'Offence', 'HomeScore', 'AwayScore', 'Halftime']];
        $scoreList = [['Game', 'Team', 'Assist', 'Score', 'Started', 'Duration']];
        $timeoutList = [['Game', 'Team', 'CalledAt']];
        $spiritList = [['Game', 'Team', 'Total', 'Rules', 'Fouls', 'Fair', 'Positive', 'Communication']];

        foreach ($data['games'] as $game) {
            $halftime = 0;
            $homeScore = $awayScore = 0;
            foreach ($game['scores'] as $k => $score) {
                if (isset($score['team'])) {
                    ${$score['team'].'Score'}++;
                    $scoreList[] = [
                        $game['id'],
                        $score['team'],
                        $score['assist'],
                        $score['score'],
                        $score['started'],
                        $score['duration']
                    ];

                    foreach ($score['timeouts'] as $timeout) {
                        $timeoutList[] = [$game['id'], $timeout['by'], $timeout['at']];
                    }
                } else if ($score == "halftime") {
                    $lastScore = $game['scores'][$k - 1];
                    $halftime = $lastScore['started'] + $lastScore['duration'] + 1;
                }
            }

            if (isset($game['spirit']['home'])) {
                $spiritList[] = $this->createSpritListItem($game['id'], $game['homeTeam'], $game['spirit']['home']);
            }

            if (isset($game['spirit']['away'])) {
                $spiritList[] = $this->createSpritListItem($game['id'], $game['awayTeam'], $game['spirit']['away']);
            }

            $gameList[] = [$game['id'], $game['homeTeam'], $game['awayTeam'], $game['onOffence'], $homeScore, $awayScore, $halftime];
        }

        $this->saveCsv($gameList, 'games');
        $this->saveCsv($scoreList, 'scores');
        $this->saveCsv($timeoutList, 'timeouts');
        $this->saveCsv($spiritList, 'spirit');
    }

    /**
     * Creates a flat array for a spirit structure
     *
     * @param int $gameId
     * @param int $teamId
     * @param array $spirit
     * @return array
     */
    protected function createSpritListItem($gameId, $teamId, $spirit)
    {
        $item = [
            $gameId,
            $teamId,
            $spirit['total']
        ];

        if (isset($spirit['breakdown'])) {
            $item = array_merge(
                $item,
                [
                    $spirit['breakdown']['rules'],
                    $spirit['breakdown']['fouls'],
                    $spirit['breakdown']['fair'],
                    $spirit['breakdown']['positive'],
                    $spirit['breakdown']['communication']
                ]
            );
        }

        return $item;
    }

    /**
     * Writes an array into a CSV file
     *
     * @param array $data
     * @param string $file  Name of the file to write ti
     */
    protected function saveCsv($data, $file)
    {
        $csvFile = $this->input->getOption('folder').'/'.$file.'.csv';
        $this->output->writeln('Writing to file '.$csvFile);

        $handle = fopen($csvFile, 'w');
        foreach ($data as $fields) {
            fputcsv($handle, $fields);
        }

        fclose($handle);
    }
}