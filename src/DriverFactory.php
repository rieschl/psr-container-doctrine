<?php

declare(strict_types=1);

namespace Roave\PsrContainerDoctrine;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Persistence\Mapping\Driver\AnnotationDriver;
use Doctrine\Common\Persistence\Mapping\Driver\FileDriver;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Common\Proxy\Exception\OutOfBoundsException;
use Psr\Container\ContainerInterface;
use function array_key_exists;
use function class_exists;
use function is_array;
use function is_subclass_of;

/**
 * @method MappingDriver __invoke(ContainerInterface $container)
 */
final class DriverFactory extends AbstractFactory
{
    /** @var bool */
    private static $isAnnotationLoaderRegistered = false;

    /**
     * {@inheritdoc}
     */
    protected function createWithConfig(ContainerInterface $container, $configKey)
    {
        $config = $this->retrieveConfig($container, $configKey, 'driver');

        if (! array_key_exists('class', $config)) {
            throw new OutOfBoundsException('Missing "class" config key');
        }

        if (! is_array($config['paths'])) {
            $config['paths'] = [$config['paths']];
        }

        if ($config['class'] === AnnotationDriver::class || is_subclass_of($config['class'], AnnotationDriver::class)) {
            $this->registerAnnotationLoader();

            $driver = new $config['class'](
                new CachedReader(
                    new AnnotationReader(),
                    $this->retrieveDependency($container, $config['cache'], 'cache', CacheFactory::class)
                ),
                $config['paths']
            );
        }

        if ($config['extension'] !== null
            && ($config['class'] === FileDriver::class || is_subclass_of($config['class'], FileDriver::class))
        ) {
            $driver = new $config['class']($config['paths'], $config['extension']);
        }

        if (! isset($driver)) {
            $driver = new $config['class']($config['paths']);
        }

        if (array_key_exists('global_basename', $config) && $driver instanceof FileDriver) {
            $driver->setGlobalBasename($config['global_basename']);
        }

        if ($driver instanceof MappingDriverChain) {
            if ($config['default_driver'] !== null) {
                $driver->setDefaultDriver($this->createWithConfig($container, $config['default_driver']));
            }

            foreach ($config['drivers'] as $namespace => $driverName) {
                if ($driverName === null) {
                    continue;
                }

                $driver->addDriver($this->createWithConfig($container, $driverName), $namespace);
            }
        }

        return $driver;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig($configKey) : array
    {
        return [
            'paths' => [],
            'extension' => null,
            'drivers' => [],
        ];
    }

    /**
     * Registers the annotation loader
     */
    private function registerAnnotationLoader() : void
    {
        if (self::$isAnnotationLoaderRegistered) {
            return;
        }

        AnnotationRegistry::registerLoader(
            static function ($className) {
                return class_exists($className);
            }
        );

        self::$isAnnotationLoaderRegistered = true;
    }
}
