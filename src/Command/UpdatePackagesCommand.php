<?php

namespace App\Command;

use App\PackagistExtension;
use Bolt\Configuration\Config;
use Bolt\Extension\ExtensionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class UpdatePackagesCommand extends Command
{
    protected static $defaultName = 'app:update';

    /**
     * @var ExtensionRegistry
     */
    private $extensionRegistry;

    public function __construct(ExtensionRegistry $extensionRegistry, Config $config)
    {
        $this->extensionRegistry = $extensionRegistry;
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

        $packagist = $this->extensionRegistry->getExtension(PackagistExtension::class);

        $updated = $packagist->updatePackages($input->getOption('name'));

        $io->table(['Package', 'version', 'status'], $updated);

        $io->success('Done.');

        return Command::SUCCESS;
    }
}
