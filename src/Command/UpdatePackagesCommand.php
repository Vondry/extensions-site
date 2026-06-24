<?php

namespace App\Command;

use App\PackagistExtension;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdatePackagesCommand extends Command
{
    protected static $defaultName = 'app:update';

    /**
     * @var PackagistExtension
     */
    private $packagist;

    public function __construct(PackagistExtension $packagist)
    {
        $this->packagist = $packagist;
        parent::__construct();
    }


    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Update only this extension / theme')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $updated = $this->packagist->updatePackages($input->getOption('name'));

        $io->table(['Package', 'version', 'status'], $updated);

        $io->success('Done.');

        return Command::SUCCESS;
    }
}
