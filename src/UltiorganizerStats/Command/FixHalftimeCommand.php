<?php

namespace UltiorganizerStats\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FixHalftimeCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('fix-halftime')
            ->setDescription('Adds a halftime to games that don\'t have one')
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Input JSON file to convert',
                OUTPUT_FILE
            )
            ->addOption(
                'score',
                's',
                InputOption::VALUE_OPTIONAL,
                'Halftime score',
                8
            )
            ->addOption(
                'time',
                't',
                InputOption::VALUE_OPTIONAL,
                'Halftime time limit in minutes',
                50
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $scoreLimit = $input->getOption('score');
        $timeLimit = (int) $input->getOption('time') * 60;

        $data = json_decode(file_get_contents($input->getOption('file')), true);

        foreach ($data['games'] as $i => $game) {
            if (!in_array('halftime', $game['scores'])) {
                $output->writeln('Game '.$game['id'].' doesn\'t have a haltime');

                $homeScore = $awayScore = 0;
                foreach ($game['scores'] as $j => $score) {
                    ${$score['scoringTeam'].'Score'}++;

                    if (
                        $homeScore == $scoreLimit ||
                        $awayScore == $scoreLimit ||
                        $score['started'] + $score['duration'] > $timeLimit
                    ) {
                        $output->writeln('Halftime at '.$homeScore.'-'.$awayScore);
                        array_splice($data['games'][$i]['scores'], $j, 0, ['halftime']);
                        break;
                    }
                }
            }
        }

        file_put_contents($input->getOption('file'), json_encode($data));
    }
}