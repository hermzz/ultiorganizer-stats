<?php

namespace UltiorganizerStats\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateStatsCommand extends Command
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
            ->setName('generate-stats')
            ->setDescription('Analyses game information and outputs some statistics')
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Input JSON file to generate stats from',
                OUTPUT_FILE
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->data = json_decode(file_get_contents($this->input->getOption('file')), true);

        $this->bestComeback();
        $this->fewestBreaks();
    }

    /**
     * Which team came back from the behind by the largest deficit
     *
     * @param array $data
     */
    protected function bestComeback()
    {
        $comebacks = [];
        foreach ($this->data['games'] as $game) {
            $diff = 0;
            $largestDiff = 0;

            $diff = $homeDiff = $awayDiff = 0;
            foreach ($game['scores'] as $score) {
                if (is_array($score)) {
                    $diff = $diff + ($score['team'] == 'home' ? 1 : -1); 
                }

                if ($diff > 0 && abs($diff) > $homeDiff ) {
                    $homeDiff = abs($diff);
                } else if ($diff < 0 && abs($diff) > $awayDiff) {
                    $awayDiff = abs($diff);
                }
            }

            $comeback = false;
            if ($diff < 0 && $homeDiff > 0) {
                $comeback = [
                    'game' => $game['id'],
                    'team' => $game['awayTeam'],
                    'opponent' => $game['homeTeam'],
                    'deficit' => $homeDiff
                ];
            } elseif ($diff > 0 && $awayDiff > 0) {
                $comeback = [
                    'game' => $game['id'],
                    'team' => $game['homeTeam'],
                    'opponent' => $game['awayTeam'],
                    'deficit' => $awayDiff
                ];
            }

            if (!empty($comeback)) {
                $division = $this->getTeamDivision($game['homeTeam']);

                if (!isset($comebacks[$division]) || $comebacks[$division]['deficit'] < $comeback['deficit']) {
                    $comebacks[$division] = $comeback;
                }
            }
        }

        $this->output->writeln('##################');
        $this->output->writeln('# Best comebacks #');
        $this->output->writeln('##################');
        $this->output->writeln('');

        foreach ($comebacks as $division => $comeback) {
            $team = $this->getTeam($comeback['team']);
            $opponent = $this->getTeam($comeback['opponent']);

            $this->output->writeln(
                sprintf(
                    "%s - %s beat %s after being %d points down",
                    $division,
                    $team['name'],
                    $opponent['name'],
                    $comeback['deficit']
                )
            );
        }

        $this->output->writeln('');
    }

    protected function fewestBreaks()
    {
        $fewest = [];

        foreach ($this->data['games'] as $game) {
            $breaks = 0;

            foreach ($game['scores'] as $k => $score) {
                if (
                    ($k === 0 && $score['team'] !== $game['onOffence']) ||
                    ($k > 0 && !is_array($game['scores'][$k-1]) && $score['team'] === $game['onOffence']) ||
                    ($k > 0 && is_array($score) && is_array($game['scores'][$k-1]) && $score['team'] === $game['scores'][$k-1]['team'])
                ) {
                    $breaks++;
                }
            }

            $division = $this->getTeamDivision($game['homeTeam']);

            if (!isset($fewest[$division])) {
                $fewest[$division] = [
                    'breaks' => INF,
                    'games' => []
                ];
            }

            if ($breaks <= $fewest[$division]['breaks']) {
                if ($breaks < $fewest[$division]['breaks']) {
                    $fewest[$division] = [
                        'breaks' => $breaks,
                        'games' => [$game]
                    ];
                } else if ($fewest[$division]['breaks'] === $breaks) {
                    $fewest[$division]['games'][] = $game;
                }
            }
        }

        $this->output->writeln('#################');
        $this->output->writeln('# Fewest breaks #');
        $this->output->writeln('#################');
        $this->output->writeln('');

        foreach ($fewest as $division => $few) {
            $this->output->writeln(sprintf("%s: %s breaks", $division, $few['breaks']));

            foreach ($few['games'] as $game) {
                $team = $this->getTeam($game['homeTeam']);
                $opponent = $this->getTeam($game['awayTeam']);

                $this->output->writeln(
                    sprintf(
                        "\t%s vs. %s",
                        $team['name'],
                        $opponent['name']
                    )
                );
            }
        }

        $this->output->writeln('');
    }

    /**
     * @param int $teamId
     * @return string
     */
    protected function getTeamDivision($teamId)
    {
        foreach ($this->data['divisions'] as $division => $teams) {
            foreach ($teams as $team) {
                if ($team['id'] === $teamId) {
                    return $division;
                }
            }
        }

        return false;
    }

    /**
     * @param int $teamId
     * @param array
     */
    protected function getTeam($teamId)
    {
        foreach ($this->data['divisions'] as $division => $teams) {
            foreach ($teams as $team) {
                if ($team['id'] === $teamId) {
                    return $team;
                }
            }
        }

        return false;
    }
}