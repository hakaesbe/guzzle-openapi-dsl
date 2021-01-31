<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Yaml\Yaml;


/**
 * Class GuzzleOpenAPIConverter
 */
final class GuzzleOpenAPIConverter extends BaseCommand
{
    /** @var string */
    const REF_SCHEMAS = 'schemas';

    /** @var string */
    const REF_PARAMETERS = 'parameters';

    /** @var string */
    private static string $name = '';

    /** @var string */
    private static string $defaultResponseLocation = 'json';

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
    private static array $models
        = [
            'getResponse' => [
                'type'                 => 'object',
                'additionalProperties' => [
                    'location' => 'json'
                ]
            ]
        ];

    /**
     * In this method setup command, description, and its parameters
     */
    protected function configure()
    {
        $this->setName('convert-openapi');
        $this->setDescription('Convert openapi.yaml|json file to guzzle service describer.');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path of the openapi file.');
        $this->addArgument('baseUrl', InputArgument::OPTIONAL, 'Base url for endpoint.');
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
            $parsedContent = json_decode($content, true);
        }

        if (!$this->checkDocument($parsedContent)) {
            throw new RuntimeException('Invalid or not supported openapi document.');
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
            self::writelnError("Invalid openAPI document: content cannot be parsed");
            return false;
        }

        /** check version */
        if (!isset($document['openapi']) || $document['openapi'] < 3) {
            self::writelnError("Invalid openAPI document: incompatible version detected");
            return false;
        }

        /** check paths */
        if (!isset($document['paths']) || count($document['paths']) === 0) {
            self::writelnError("Invalid openAPI document: missing paths part");
            return false;
        }

        /** check components */
        if (!isset($document['components'][self::REF_SCHEMAS]) || count($document['components'][self::REF_SCHEMAS]) === 0) {
            self::writelnError("Invalid openAPI document: missing components/schemas part");
        }

        /** check components */
        if (!isset($document['components'][self::REF_PARAMETERS]) || count($document['components'][self::REF_PARAMETERS]) === 0) {
            self::writelnComment("Missing components/parameters part in openAPI file.");
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
        $url                = (isset($document['servers'][0]['url'])) ? $document['servers'][0]['url'] : '';
        if (str_starts_with($url, '/')) {
            self::$basePath = $url;
        } else {
            self::$baseUrl = $url;
        }
        if (self::$input->hasArgument('baseUrl')) {
            self::$baseUrl = (string)self::$input->getArgument('baseUrl');
        }
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
                        /** detecting ref parameters */
                        if (is_string($pathItemObjectParameter)) {
                            $parameters[$operationId] = [
                                '$ref' => $this->convertRefToModel($pathItemObjectParameter)
                            ];
                            continue;
                        }
                        if (!isset($pathItemObjectParameter['schema']['type'])) {
                            self::writelnError("Missing schema type $httpMethod $path");
                            continue;
                        }
                        $parameters[$pathItemObjectParameter['name']] = [
                            'type'        => $pathItemObjectParameter['schema']['type'],
                            'location'    => ('path' == $pathItemObjectParameter['in']) ? 'uri' : 'query',
                            'description' => (isset($pathItemObjectParameter['description'])) ? $pathItemObjectParameter['description'] : '',
                            'required'    => (isset($pathItemObjectParameter['required'])) ? $pathItemObjectParameter['required'] : false,
                        ];
                    }
                }

                if (isset($pathItemObject['requestBody']['content'])) {
                    foreach ($pathItemObject['requestBody']['content'] as $location => $content) {
                        /** application/x-www-form-urlencoded */
                        $location = 'body';
                        if ('application/json' == $location) {
                            $location = 'json';
                        } elseif ('application/xml' == $location) {
                            $location = 'xml';
                        }
                        if (isset($content['schema']['$ref'])) {
                            $parameters['$ref'] = [
                                '$ref'     => $this->convertRefToModel($content['schema']['$ref']),
                                'location' => $location,
                            ];
                            break;
                        }
                    }
                }

                /** parse responses */
                $responseModel  = 'getResponse';
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
                    'name'             => $operationId,
                    'httpMethod'       => strtoupper($httpMethod),
                    'uri'              => ('' != self::$basePath) ? rtrim(self::$basePath, '/') . '/' . ltrim($path, '/') : $path,
                    'responseModel'    => $responseModel,
                    'notes'            => (isset($pathItemObject['summary'])) ? $pathItemObject['summary'] : null,
                    'summary'          => (isset($pathItemObject['summary'])) ? $pathItemObject['summary'] : null,
                    'documentationUrl' => null,
                    'deprecated'       => false,
                    'parameters'       => $parameters,
                    'errorResponses'   => $errorResponses,
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
        if (str_contains($ref, '#/components/' . self::REF_SCHEMAS . '/')) {
            $ref = str_replace('#/components/' . self::REF_SCHEMAS . '/', '', $ref);
        } elseif (str_contains($ref, '#/components/' . self::REF_PARAMETERS . '/')) {
            $ref = str_replace('#/components/' . self::REF_PARAMETERS . '/', '', $ref);
            $ref .= 'Parameter';
        }

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
        foreach ([self::REF_SCHEMAS, self::REF_PARAMETERS] as $componentType) {
            if (!isset($document['components'][$componentType])) {
                continue;
            }
            foreach ($document['components'][$componentType] as $refName => $ref) {
                $modelName = $refName;
                if (self::REF_PARAMETERS === $componentType) {
                    $modelName .= 'Parameter';
                }
                foreach ($ref as &$property) {
                    if (is_array($property)) {
                        $property = $this->parseRecursiveItem($property);
                    }
                }
                if (!isset($ref['location'])) {
                    $ref['location'] = self::$defaultResponseLocation;
                }
                if (isset($ref['type']) && isset($ref['properties'])) {
                    self::$models[$modelName] = [
                        'type'       => $ref['type'],
                        'properties' => $ref['properties'],
                        'location'   => $ref['location'],
                    ];
                } else {
                    self::$models[$modelName] = $ref;
                }
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
        foreach ($item as &$subItem) {
            if (is_array($subItem)) {
                $subItem = $this->parseRecursiveItem($subItem);
            }
        }
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
            return (!is_numeric($uriPart) && '' !== $uriPart && !str_contains($uriPart, '{'));
        });
        $paths       = array_map(function ($uriPart) {
            return $this->normalizeOperationId($uriPart);
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
        $operationId                = $operationId . $i;
        self::$models[$operationId] = [];
        self::writelnInfo("Missing operationId for: $httpCode $path now using operationId $operationId");

        return $operationId;
    }

    /**
     * @param string $operationId
     *
     * @return string
     */
    private function normalizeOperationId(string $operationId)
    : string
    {
        $operationId = strtolower(trim(str_replace(['{', '}', '-', '_'], ['', '', ' ', ' '], $operationId)));
        $operationId = ucwords($operationId);

        return str_replace(['', "\n", "\t"], '', $operationId);
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
        self::writelnInfo('Writing guzzle service describer to: ' . $path);
        file_put_contents($path, json_encode($guzzleServiceDescriber));
    }

}
