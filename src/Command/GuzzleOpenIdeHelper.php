<?php

declare(strict_types=1);

namespace App\Command;


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
    }

    /**
     * Executes the current command.
     *
     * @return int
     */
    protected function executeCommand()
    : int
    {

        return self::SUCCESS;
    }

}
