<?php

namespace L5Swagger;

use Exception;
use File;
use InvalidArgumentException;
use L5Swagger\Exceptions\L5SwaggerException;
use Swagger\Annotations\Swagger;
use Swagger\Util;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Dumper as YamlDumper;
use Symfony\Component\Yaml\Yaml;

class Generator {
    /**
     * @var string
     */
    protected $appDir;

    /**
     * @var string
     */
    protected $docDir;

    /**
     * @var string
     */
    protected $docsFile;

    /**
     * @var string
     */
    protected $yamlDocsFile;

    /**
     * @var array
     */
    protected $excludedDirs;

    /**
     * @var array
     */
    protected $constants;

    /**
     * @var \OpenApi\Annotations\OpenApi
     */
    protected $swagger;

    /**
     * @var bool
     */
    protected $yamlCopyRequired;

    public function __construct() {
        $this->appDir = config('l5-swagger.paths.annotations');
        $this->docDir = config('l5-swagger.paths.docs');
        $this->docsFile = $this->docDir . '/' . config('l5-swagger.paths.docs_json', 'api-docs.json');
        $this->yamlDocsFile = $this->docDir . '/' . config('l5-swagger.paths.docs_yaml', 'api-docs.yaml');
        $this->excludedDirs = config('l5-swagger.paths.excludes');
        $this->constants = config('l5-swagger.constants') ?: [];
        $this->yamlCopyRequired = config('l5-swagger.generate_yaml_copy', false);
    }

    public static function generateDocs() {
        (new static)->prepareDirectory()
            ->defineConstants()
            ->scanFilesForDocumentation()
            ->populateServers()
            ->saveJson()
            ->makeYamlCopy();
    }

    /**
     * Check directory structure and permissions.
     *
     * @return Generator
     */
    protected function prepareDirectory() {
        if (File::exists($this->docDir) && !is_writable($this->docDir)) {
            throw new L5SwaggerException('Documentation storage directory is not writable');
        }

        // delete all existing documentation
        if (File::exists($this->docDir)) {
            File::deleteDirectory($this->docDir);
        }

        File::makeDirectory($this->docDir);

        return $this;
    }

    /**
     * Define constant which will be replaced.
     *
     * @return Generator
     */
    protected function defineConstants() {
        if (!empty($this->constants)) {
            foreach ($this->constants as $key => $value) {
                defined($key) || define($key, $value);
            }
        }

        return $this;
    }

    /**
     * Scan directory and create Swagger.
     *
     * @return Generator
     */
    protected function scanFilesForDocumentation() {
        if ($this->isOpenApi()) {
            $this->swagger = \OpenApi\scan(
                $this->appDir,
                ['exclude' => $this->excludedDirs]
            );
        }

        if (!$this->isOpenApi()) {
            $this->swagger = \Swagger\scan(
                $this->appDir,
                ['exclude' => $this->excludedDirs]
            );
        }

        return $this;
    }

    /**
     * Generate servers section or basePath depending on Swagger version.
     *
     * @return Generator
     */
    protected function populateServers() {
        if (config('l5-swagger.paths.base') !== null) {
            if ($this->isOpenApi()) {
                $this->swagger->servers = [
                    new \OpenApi\Annotations\Server(['url' => config('l5-swagger.paths.base')]),
                ];
            }

            if (!$this->isOpenApi()) {
                $this->swagger->basePath = config('l5-swagger.paths.base');
            }
        }

        return $this;
    }

    /**
     * Save documentation as json file.
     *
     * @return Generator
     */
    protected function saveJson() {
        $this->swagger->saveAs($this->docsFile);

        // 兼容yaml
        self::loadYaml($this->docsFile);

        $security = new SecurityDefinitions();
        $security->generate($this->docsFile);

        return $this;
    }

    public static function getYamlData() {
        $excludeDirs = config('l5-swagger.paths.excludes');

        // 读取注释目录并解析，支持数组
        $yamlDirs = config('l5-swagger.paths.yamlAnnotations', base_path('apps'));
        $yamlData = [];
        if (is_string($yamlDirs)) {
            $yamlDirs = [$yamlDirs];
        }
        if (is_array($yamlDirs)) {
            foreach ($yamlDirs as $yamlDir) {
                $finder = self::finder($yamlDir, $excludeDirs);
                foreach ($finder as $file) {
                    try {
                        $fileData = Yaml::parse(file_get_contents($file));
                        $yamlData = self::mergeData($yamlData, $fileData);
                    } catch (\Exception $e) {
                        throw new Exception('Failed to parse file("' . $file . '"):' . $e->getMessage());
                    }
                }
            }
        }
        return $yamlData;
    }

    private static function finder($directory, $exclude = null) {
        if ($directory instanceof Finder) {
            return $directory;
        } else {
            $finder = new Finder();
            $finder->sortByName();
        }
        $finder->files()->followLinks()->name('*.yaml');
        if (is_string($directory)) {
            if (is_file($directory)) { // Scan a single file?
                $finder->append([$directory]);
            } else { // Scan a directory
                $finder->in($directory);
            }
        } elseif (is_array($directory)) {
            foreach ($directory as $path) {
                if (is_file($path)) { // Scan a file?
                    $finder->append([$path]);
                } else {
                    $finder->in($path);
                }
            }
        } else {
            throw new InvalidArgumentException('Unexpected $directory value:' . gettype($directory));
        }
        if ($exclude !== null) {
            if (is_string($exclude)) {
                $finder->notPath(Util::getRelativePath($exclude, $directory));
            } elseif (is_array($exclude)) {
                foreach ($exclude as $path) {
                    $finder->notPath(Util::getRelativePath($path, $directory));
                }
            } else {
                throw new InvalidArgumentException('Unexpected $exclude value:' . gettype($exclude));
            }
        }
        return $finder;
    }


    /**
     * @param                 $filename
     * @param Swagger|OpenApi $swagger
     * @throws Exception
     */
    private static function loadYaml($filename) {
        $yamlData = self::getYamlData();

        // 迁移PHP解析出来的数据
        $phpData = json_decode(file_get_contents($filename), true);

        // 保存文件
        self::saveAs($filename, json_encode(self::mergeData($phpData, $yamlData)));
    }

    private static function saveAs($filename, $data)
    {
        if (file_put_contents($filename, $data) === false) {
            throw new Exception('Failed to saveAs("' . $filename . '")');
        }
    }


    private static function mergeData($oldData, $newData) {
        if (!empty($newData)) {
            $keys = array_keys($newData);
            foreach ($keys as $key) {
                // 该字段已存在，且为数组则合并数据；否则则覆盖原有数据
                if (isset($oldData[$key]) && is_array($newData[$key])) {
                    $oldData[$key] = array_merge((array)$oldData[$key], $newData[$key]);
                } else {
                    $oldData[$key] = $newData[$key];
                }
            }
        }
        return $oldData;
    }

    /**
     * Save documentation as yaml file.
     *
     * @return Generator
     */
    protected function makeYamlCopy() {
        if ($this->yamlCopyRequired) {
            file_put_contents(
                $this->yamlDocsFile,
                (new YamlDumper(2))->dump(json_decode(file_get_contents($this->docsFile), true), 20)
            );
        }
    }

    /**
     * Check which documentation version is used.
     *
     * @return bool
     */
    protected function isOpenApi() {
        return version_compare(config('l5-swagger.swagger_version'), '3.0', '>=');
    }
}
