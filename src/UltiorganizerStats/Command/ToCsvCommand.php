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
            ->setName('tocsv')
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
        $scoreList = [['GameID', 'Team', 'Assist', 'Score', 'Started', 'Duration']];
        $timeoutList = [['GameID', 'Team', 'CalledAt']];

        foreach ($data['games'] as $game) {
            $halftime = 0;
            $homeScore = $awayScore = 0;
            foreach ($game['scores'] as $k => $score) {
                if (isset($score['scoringTeam'])) {
                    ${$score['scoringTeam'].'Score'}++;
                    $scoreList[] = [$game['id'], $score['scoringTeam'], $score['assist'], $score['score'], $score['started'], $score['duration']];

                    foreach ($score['timeouts'] as $timeout) {
                        $timeoutList[] = [$game['id'], $timeout['by'], $timeout['at']];
                    }
                } else if ($score == "halftime") {
                    $lastScore = $game['scores'][$k - 1];
                    $halftime = $lastScore['started'] + $lastScore['duration'] + 1;
                }
            }

            $gameList[] = [$game['id'], $game['homeTeam'], $game['awayTeam'], $game['onOffence'], $homeScore, $awayScore, $halftime];
        }

        $this->saveCsv($gameList, 'games');
        $this->saveCsv($scoreList, 'scores');
        $this->saveCsv($timeoutList, 'timeouts');
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