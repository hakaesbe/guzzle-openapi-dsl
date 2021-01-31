<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;


/**
 * Class GuzzleOpenAPIConverter
 */
final class GuzzleOpenAPIConverter extends Command
{
    /** @var \Symfony\Component\Console\Input\InputInterface|null */
    private static ?InputInterface $input = null;

    /** @var \Symfony\Component\Console\Output\OutputInterface|null */
    private static ?OutputInterface $output = null;

    /** @var string */
    private static string $name = '';

    /** @var string */
    private static string $apiVersion = '';

    /** @var string */
    private static string $baseUrl = '';

    /** @var string */
    private static string $basePath = '';

    /** @var string */
    private static string $_description = '';

    /** @var array */
    private static array $operations = [];

    /** @var array */
    private static array $models = [];

    /**
     * In this method setup command, description, and its parameters
     */
    protected function configure()
    {
        $this->setName('convert-openapi');
        $this->setDescription('Convert openapi.yaml|json file to guzzle service describer.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path of the openapi file.');
    }

    /**
     * Here all logic happens
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

        $path = $input->getArgument('path');

        /** check file exists */
        if (!file_exists($path)) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
            if (!file_exists($path)) {
                throw new RuntimeException('File does not exist.');
            }
        }

        $document = $this->parseFile($path);

        if (!$this->checkDocument($document)) {
            throw new RuntimeException('Invalid or not supported openapi document.');
        }

        $this->parseTopLevelAttributes($document);
        $this->parseOperations($document);
        $this->parseModels($document);
        $this->writeGuzzleServiceDescriber();


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
        if (!in_array($pathInfo['extension'], ['yaml', 'json'])) {
            throw new RuntimeException('File does not exist.');
        }

        $content = file_get_contents($path);
        if ('yaml' === $pathInfo['extension']) {
            $parsedContent = Yaml::parse($content);
        } else {
            $parsedContent = json_decode($content);
        }

        return $parsedContent;
    }

    /**
     * Quick and fast openapi document check.
     *
     * @param array $document
     *
     * @return bool
     */
    private function checkDocument(array $document)
    : bool
    {

        /** check type */
        if (!is_array($document) || count($document) === 0) {
            self::$output->writeln("<error>Invalid openAPI document: content cannot be parsed</error>");
            return false;
        }

        /** check version */
        if (!isset($document['openapi']) || $document['openapi'] < 3) {
            self::$output->writeln("<error>Invalid openAPI document: incompatible version detected</error>");
            return false;
        }

        /** check paths */
        if (!isset($document['paths']) || count($document['paths']) === 0) {
            self::$output->writeln("<error>Invalid openAPI document: missing paths part</error>");
            return false;
        }

        /** check components */
        if (!isset($document['components']['schemas']) || count($document['components']['schemas']) === 0) {
            self::$output->writeln("<error>Invalid openAPI document: missing components/schemas part</error>");
            return false;
        }

        return true;
    }

    /**
     * Parse and convert openapi operations to guzzle service operations.
     *
     * @param array $document
     */
    private static function parseTopLevelAttributes(array $document)
    : void
    {
        self::$name         = (isset($document['info']['title'])) ? $document['info']['title'] : '';
        self::$apiVersion   = (isset($document['info']['version'])) ? $document['info']['version'] : '';
        self::$_description = (isset($document['info']['description'])) ? $document['info']['description'] : '';
        self::$baseUrl      = (isset($document['servers'][0]['url'])) ? $document['servers'][0]['url'] : '';
    }

    /**
     * Parse and convert openapi operations to guzzle service operations.
     *
     * @param array $document
     */
    private function parseOperations(array $document)
    : void
    {

        foreach ($document['paths'] as $path => $operations) {
            foreach ($operations as $httpMethod => $pathItemObject) {
                $operationId = (isset($pathItemObject['operationId'])) ? $pathItemObject['operationId'] : $this->generateOperationId($httpMethod, $path);

                /** parse parameters */
                $parameters = [];
                if (isset($pathItemObject['parameters'])) {
                    foreach ($pathItemObject['parameters'] as $pathItemObjectParameter) {
                        if (!isset($pathItemObjectParameter['schema']['type'])) {
                            self::$output->writeln("<error>Missing schema type $httpMethod $path</error>");
                            continue;
                        }
                        $parameters[$operationId] = [
                            'type'        => $pathItemObjectParameter['schema']['type'],
                            'location'    => ('path' === $pathItemObjectParameter['in']) ? 'in' : 'query',
                            'description' => (isset($pathItemObjectParameter)) ? $pathItemObjectParameter : '',
                            'required'    => (isset($pathItemObjectParameter['required'])) ? $pathItemObjectParameter['required'] : false,
                        ];
                    }
                }

                /** parse responses */
                $responseModel  = null;
                $errorResponses = [];
                if (isset($pathItemObject['responses'])) {
                    foreach ($pathItemObject['responses'] as $responseCode => $response) {
                        if ((int)$responseCode >= 400) {
                            $errorResponses[] = [
                                'code'        => $responseCode,
                                'description' => $response['description'],
                            ];
                        }
                    }
                    if (isset($pathItemObject['responses'][200]['content'])) {
                        foreach ($pathItemObject['responses'][200]['content'] as $successContent) {
                            if (isset($successContent['schema']['$ref'])) {
                                $responseModel = $this->convertRefToModel($successContent['schema']['$ref']);
                                break;
                            }
                        }
                    }

                }

                self::$operations[$operationId] = [
                    'name'                 => $operationId,
                    'httpMethod'           => strtoupper($httpMethod),
                    'uri'                  => $path,
                    'responseModel'        => $responseModel,
                    'notes'                => (isset($pathItemObject['summary'])) ? $pathItemObject['summary'] : null,
                    'summary'              => (isset($pathItemObject['summary'])) ? $pathItemObject['summary'] : null,
                    'documentationUrl'     => null,
                    'deprecated'           => false,
                    'data'                 => [],
                    'parameters'           => $parameters,
                    'additionalParameters' => null,
                    'errorResponses'       => $errorResponses,
                ];

            }
        }

    }

    /**
     * Convert openAPI ref to Guzzle fqdn class.
     *
     * @param string $ref
     *
     * @return string
     */
    private function convertRefToModel(string $ref)
    : string
    {
        $ref = str_replace('#/components/schemas/', '', $ref);

        return $ref;
    }

    /**
     * Parse and convert openapi responses to guzzle service models.
     *
     * @param array $document
     */
    private function parseModels(array $document)
    : void
    {
        foreach ($document['components']['schemas'] as $refName => $ref) {
            if (isset($ref['properties'])) {
                foreach ($ref['properties'] as &$property) {
                    $property = $this->parseRecursiveItem($property);
                }
                self::$models[$this->convertRefToModel($refName)] = [
                    'type'       => $ref['type'],
                    'properties' => $ref['properties'],
                ];
            }
        }
    }

    /**
     * @param array $item
     *
     * @return array
     */
    private function parseRecursiveItem(array $item)
    : array
    {
        if (isset($item['example'])) {
            unset($item['example']);
        }
        if (isset($item['format'])) {
            unset($item['format']);
        }
        if (isset($item['xml'])) {
            unset($item['xml']);
        }
        if (isset($item['$ref'])) {
            $item['$ref'] = $this->convertRefToModel($item['$ref']);
        }
        if (isset($item['items'])) {
            $item['items'] = $this->parseRecursiveItem($item['items']);
        }
        return $item;
    }

    /**
     * Generate operation identifier.
     *
     * @param string $httpCode
     * @param string $path
     *
     * @return string
     */
    private function generateOperationId(string $httpCode, string $path)
    : string
    {
        $paths       = explode('/', $path);
        $paths       = array_filter($paths, function ($uriPart) {
            return (!is_numeric($uriPart) && '' !== $uriPart && strpos($uriPart, '{') === false);
        });
        $paths       = array_map(function ($uriPart) {
            $uriPart = strtolower(trim(str_replace(['{', '}'], '', $uriPart)));
            $uriPart = ucfirst($uriPart);
            return $uriPart;
        }, $paths);
        $operationId = strtolower($httpCode) . implode('', $paths);
        $i           = '';
        while (array_key_exists($operationId . $i, self::$models)) {
            if ('' === $i) {
                $i = 1;
            } else {
                $i++;
            }
        }
        $operationId = $operationId . $i;
        self::$models[$operationId] = [];
        self::$output->writeln("<info>Missing operationId for: $httpCode $path now using operationId $operationId</info>");

        return $operationId;
    }

    /**
     * Write to file Guzzle service describer.
     */
    private function writeGuzzleServiceDescriber()
    : void
    {
        $guzzleServiceDescriber = [
            'name'         => self::$name,
            'apiVersion'   => self::$apiVersion,
            'baseUrl'      => self::$baseUrl,
            'basePath'     => self::$basePath,
            '_description' => self::$_description,
            'operations'   => self::$operations,
            'models'       => self::$models,
        ];
        $path                   = getcwd() . DIRECTORY_SEPARATOR . 'guzzle_service.json';
        self::$output->writeln('<info>Writing guzzle service describer to: ' . $path . '</info>');
        file_put_contents($path, json_encode($guzzleServiceDescriber));
    }

}
