<?php

declare(strict_types=1);

namespace App\Command;


use Symfony\Component\Console\Input\InputArgument;

/**
 * Class GuzzleOpenIdeHelper
 */
final class GuzzleOpenIdeHelper extends BaseCommand
{

    /**
     * In this method setup command, description, and its parameters
     */
    protected function configure()
    {
        $this->setName('generate-ide-helper');
        $this->setDescription('Generate guzzle service ide helper.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path of the guzzle service file.');
    }

    /**
     * Executes the current command.
     *
     * @return int
     */
    protected function executeCommand()
    : int
    {

        $path = self::$input->getArgument('path');

        return self::SUCCESS;
    }

}
