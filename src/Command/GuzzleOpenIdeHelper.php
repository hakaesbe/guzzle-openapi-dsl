<?php

declare(strict_types=1);

namespace App\Command;


use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Class GuzzleOpenIdeHelper
 */
final class GuzzleOpenIdeHelper extends BaseCommand
{

    /** @var string */
    const PHP_METHOD
        = <<<'EOD'
    /**
     {{phpDoc}}
     */
    public function {{method}}({{parameters}})
    : void
    {
        return $this->call({{bindParameters}})
    }
EOD;

    /** @var array */
    private static array $methods = [];

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

        $path     = $this->getPath();
        $document = $this->parseFile($path);

        $this->parseMethods($document['operations']);


        print_r(self::$methods);

        return self::SUCCESS;
    }

    /**
     * Parse openapi document, actually support yaml and json file type.
     *
     * @param $path
     *
     * @return array
     */
    private function parseFile($path)
    : array
    {

        $pathInfo = pathinfo($path);

        /** only support yaml or json openapi extension */
        if (!in_array($pathInfo['extension'], ['json'])) {
            throw new RuntimeException('File does not exist.');
        }
        $content = file_get_contents($path);

        return json_decode($content, true);
    }

    /**
     * @param array $operations
     */
    private function parseMethods(array $operations)
    : void
    {
        foreach ($operations as $operation) {
            self::$methods[] = $this->convertToPhpMethod($operation);
        }
    }

    /**
     * @param array $operation
     *
     * @return string
     */
    private function convertToPhpMethod(array $operation)
    : string
    {
        $php            = self::PHP_METHOD;
        $php            = str_replace('{{method}}', $operation['name'], $php);
        $phpDoc     = '';
        $parameters     = '';
        $bindParameters = '[';
        foreach ($operation['parameters'] as $parameterName => $parameter) {
            $parameters     .= trim($this->convertTypeToPhpType($parameter['type']) . ' $' . $parameterName) . ', ';
            $bindParameters .= "'" . $parameterName . "' => " . '$' . $parameterName . ',';
        }
        $bindParameters = rtrim($bindParameters, ',');
        $bindParameters .= ']';
        // todo missing body data params
        $php = str_replace('{{parameters}}', rtrim($parameters, ' ,'), $php);

        // todo add method description
        // todo add phpdoc param typed + description
        // todo prepare class client with method call
        // todo fix missing data from guzzle service

        // notes
        $phpDoc .= '* '.$operation['notes'] . "\n";

        print_r($operation);
        die;

//
//        if ('getOrderById' === $operation['name']) {
//            print_r($operation);
//            print_r($php);
//            print_r($bindParameters);
//            die;
//        }

        $php = str_replace('{{bindParameters}}', $bindParameters, $php);
        $php = str_replace('{{phpDoc}}', rtrim($phpDoc), $php);

        return $php;
    }

    /**
     * @param string $type
     *
     * @return string
     */
    private function convertTypeToPhpType(string $type)
    : string
    {
        switch ($type) {
            case 'integer':
                return 'int';
            case 'number':
                return 'float';

        }

        return '';
    }

}
