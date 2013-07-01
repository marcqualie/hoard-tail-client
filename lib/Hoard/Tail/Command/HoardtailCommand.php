<?php

namespace Hoard\Tail\Command;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class HoardtailCommand extends Command
{

    protected function configure()
    {
        $this
            ->setName('hoardtail')
            ->setDescription('Run the Hoard Tail client')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Location of configuration file',
                false
            )
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Configuration
        $config = array();
        $config_file = 'hoardtail.json';
        $config_paths = array(
            '/etc/' . $config_file,
            '/usr/local/etc/' . $config_file,
            (isset($_SERVER['HOME']) ? $_SERVER['HOME'] . '/.' . $config_file : false),
            $input->getOption('config')
        );
        foreach ($config_paths as $config_path) {
            $real_path = realpath($config_path);
            if ($config_path && is_file($real_path)) {
                $output->writeln('<info>Loading config file: ' . $real_path . '</info>');
                $include = json_decode(file_get_contents($real_path), true);
                if (is_array($include)) {
                    $config = array_merge($config, $include);
                }
            }
        }
        $output->writeln('Hoard Tail Client');

    }

}
