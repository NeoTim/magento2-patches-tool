<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\SetPatches;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Illuminate\Container\Container;
use Magento\SetPatches\Command\Apply;
use Magento\SetPatches\Command\BackupLock;
use Magento\SetPatches\Command\BackupPatch;
use Magento\SetPatches\Command\CheckMerged;
use Magento\SetPatches\Command\FindRequirePatches;
use Magento\SetPatches\Command\Remove;
use Magento\SetPatches\Command\RestoreLock;
use Magento\SetPatches\Patch\Action\ApplyAction;
use Magento\SetPatches\Patch\Action\RevertAction;
use Magento\SetPatches\Patch\JsonStorage;
use Psr\Container\ContainerExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * @inheritdoc
 */
class Application extends \Symfony\Component\Console\Application
{
    /**
     * @var ContainerExceptionInterface
     */
    private $container;

    /**
     * @var array
     */
    private $commands = [
        Apply::class,
        RestoreLock::class,
        BackupLock::class,
        FindRequirePatches::class,
        CheckMerged::class,
        BackupPatch::class,
        Remove::class
    ];

    /**
     * @inheritdoc
     */
    public function __construct()
    {
        if (!defined('PACKAGE_BP')) {
            define('PACKAGE_BP', dirname(__DIR__));
        }
        if (!defined('BASE_DIR')) {
            define('BASE_DIR', realpath(__DIR__ . '/../../../..'));
        }

        $container = new Container();
        $this->container = $container;
        $this->container->instance(Container::class, $container);
        $this->container->singleton(ApplyAction::class);

        $composerFactory = new \Composer\Factory();

        $composer = $composerFactory->createComposer(
            new \Composer\IO\BufferIO(),
            BASE_DIR . '/composer.json',
            false,
            BASE_DIR
        );
        $this->container->singleton(Composer::class, function () use ($composer) {return $composer;});
        $localComposer = Factory::create(
            new BufferIO(),
            PACKAGE_BP . '/composer.json'
        );

        $this->container->singleton(Apply::class, function () use ($container) {
            return new Apply(
                $this->container->make(Composer::class),
                $this->container->make(\Magento\SetPatches\Instance\InstanceProvider::class),
                $this->container->make(\Magento\SetPatches\Patch\PatchesProvider::class),
                [
                    \Magento\SetPatches\Data\Patch::ACTION_APPLY => $this->container->make(\Magento\SetPatches\Patch\Action\ApplyAction::class),
                    \Magento\SetPatches\Data\Patch::ACTION_REVERT => $this->container->make(\Magento\SetPatches\Patch\Action\RevertAction::class),
                ]
            );
        });

        $this->container->singleton(RestoreLock::class, function () use($container) {
            return new RestoreLock(
                $container->makeWith(JsonFile::class, [
                    'path' => BASE_DIR . '/composer.lock'
                ]),
                $container->makeWith(JsonFile::class, [
                    'path' => BASE_DIR . '/composer.lock.tmp'
                ])
            );
        });
        $this->container->singleton(BackupLock::class, function () use($container) {
            return new BackupLock(
                $container->makeWith(JsonFile::class, [
                    'path' => BASE_DIR . '/composer.lock'
                ]),
                $container->makeWith(JsonFile::class, [
                    'path' => BASE_DIR . '/composer.lock.tmp'
                ])
            );
        });

        $this->container->singleton(
            LoggerInterface::class,
            function () {
                $formatter = new \Monolog\Formatter\LineFormatter(
                    "%level_name%: %message% %context% %extra%\n",
                    null,
                    true,
                    true
                );

                return new \Monolog\Logger('default', [
                    (new \Monolog\Handler\StreamHandler('php://stdout'))
                        ->setFormatter($formatter),
                ]);
            }
        );

        parent::__construct(
            $localComposer->getPackage()->getPrettyName(),
            $localComposer->getPackage()->getPrettyVersion()
        );
    }

    /**
     * @inheritdoc
     */
    protected function getDefaultCommands()
    {
        $commands = [];

        foreach ($this->commands as $commandClass) {
            try {
                $commands[] = $this->container->make($commandClass);
            } catch (\Exception $exception) {
                continue;
            }
        }

        return array_merge(parent::getDefaultCommands(), $commands);
    }
}
