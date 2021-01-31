<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class BaseCommand
 *
 * @package App\Command
 */
class BaseCommand extends Command
{
    /** @var \Symfony\Component\Console\Input\InputInterface|null */
    protected static ?InputInterface $input = null;

    /** @var \Symfony\Component\Console\Output\OutputInterface|null */
    protected static ?OutputInterface $output = null;


    /**
     * Executes the current command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    : int
    {
        self::$input  = $input;
        self::$output = $output;

        return $this->executeCommand();
    }

    /**
     * Running command.
     *
     * @return int
     */
    protected function executeCommand()
    : int
    {

        return Command::SUCCESS;
    }

    /**
     * @param string $message
     */
    protected static function writeInfo(string $message)
    : void
    {
        self::$output->write("<info>$message</info>");
    }

    /**
     * @param string $message
     */
    protected static function writelnInfo(string $message)
    : void
    {
        self::$output->writeln("<info>$message</info>");
    }

    /**
     * @param string $message
     */
    protected static function writeComment(string $message)
    : void
    {
        self::$output->write("<comment>$message</comment>");
    }

    /**
     * @param string $message
     */
    protected static function writelnComment(string $message)
    : void
    {
        self::$output->writeln("<comment>$message</comment>");
    }

    /**
     * @param string $message
     */
    protected static function writeError(string $message)
    : void
    {
        self::$output->write("<error>$message</error>");
    }

    /**
     * @param string $message
     */
    protected static function writelnError(string $message)
    : void
    {
        self::$output->writeln("<error>$message</error>");
    }


}
