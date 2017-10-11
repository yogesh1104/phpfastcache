<?php
/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> http://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */

namespace phpFastCache\Drivers\Riak;

use phpFastCache\Core\Pool\DriverBaseTrait;
use phpFastCache\Core\Pool\ExtendedCacheItemPoolInterface;
use phpFastCache\Entities\DriverStatistic;
use phpFastCache\EventManager;
use phpFastCache\Exceptions\phpFastCacheDriverCheckException;
use phpFastCache\Exceptions\phpFastCacheDriverException;
use phpFastCache\Exceptions\phpFastCacheInvalidArgumentException;
use phpFastCache\Exceptions\phpFastCacheLogicException;
use phpFastCache\Util\ArrayObject;
use Psr\Cache\CacheItemInterface;
use Basho\Riak\Riak;

/**
 * Class Driver
 * @package phpFastCache\Drivers
 * @property Riak $instance Instance of driver service
 */
class Driver implements ExtendedCacheItemPoolInterface
{
    const RIAK_DEFAULT_BUCKET_NAME = 'phpfastcache';

    /**
     * @var string
     */
    protected $bucketName = self::RIAK_DEFAULT_BUCKET_NAME;

    use DriverBaseTrait;

    /**
     * Driver constructor.
     * @param array $config
     * @throws phpFastCacheDriverException
     */
    public function __construct(array $config = [])
    {
        $this->setup($config);

        if (!$this->driverCheck()) {
            throw new phpFastCacheDriverCheckException(sprintf(self::DRIVER_CHECK_FAILURE, $this->getDriverName()));
        } else {
            $this->driverConnect();
        }
    }

    /**
     * @return bool
     */
    public function driverCheck(): bool
    {
        return class_exists('Basho\Riak\Riak');
    }


    /**
     * @return bool
     * @throws phpFastCacheLogicException
     */
    protected function driverConnect(): bool
    {
        if ($this->instance instanceof Riak) {
            throw new phpFastCacheLogicException('Already connected to Riak server');
        } else {
            $clientConfig = $this->getConfig();
            $this->bucketName = $clientConfig[ 'bucketName' ];

            $this->instance = new Riak($clientConfig[ 'host' ], $clientConfig[ 'port' ], $clientConfig[ 'prefix' ]);

            return true;
        }
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return null|array
     */
    protected function driverRead(CacheItemInterface $item)
    {
        return $this->decode($this->instance->bucket($this->bucketName)->getBinary($item->getKey())->getData());
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return mixed
     * @throws phpFastCacheInvalidArgumentException
     */
    protected function driverWrite(CacheItemInterface $item): bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return $this->instance
              ->bucket($this->bucketName)
              ->newBinary($item->getKey(), $this->encode($this->driverPreWrap($item)))
              ->store();
        } else {
            throw new phpFastCacheInvalidArgumentException('Cross-Driver type confusion detected');
        }
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return bool
     * @throws phpFastCacheInvalidArgumentException
     */
    protected function driverDelete(CacheItemInterface $item): bool
    {
        /**
         * Check for Cross-Driver type confusion
         */
        if ($item instanceof Item) {
            return $this->instance->bucket($this->bucketName)->get($item->getKey())->delete();
        } else {
            throw new phpFastCacheInvalidArgumentException('Cross-Driver type confusion detected');
        }
    }

    /**
     * @return bool
     */
    protected function driverClear(): bool
    {
        $bucket = $this->instance->bucket($this->bucketName);
        foreach ($bucket->getKeys() as $key) {
            $bucket->get($key)->delete();
        }
        return true;
    }

    /********************
     *
     * PSR-6 Extended Methods
     *
     *******************/

    /**
     * @return DriverStatistic
     */
    public function getStats(): DriverStatistic
    {
        $info = $this->instance->bucket($this->bucketName)->getProperties();

        return (new DriverStatistic())
          ->setData(implode(', ', array_keys($this->itemInstances)))
          ->setRawData($info)
          ->setSize(false)
          ->setInfo('Riak does not provide size/date information att all :(');
    }

    /**
     * @return ArrayObject
     */
    public function getDefaultConfig(): ArrayObject
    {
        $defaultConfig = new ArrayObject();

        $defaultConfig[ 'host' ] = '127.0.0.1';
        $defaultConfig[ 'port' ] = 8098;
        $defaultConfig[ 'prefix' ] = 'riak';
        $defaultConfig[ 'bucketName' ] = self::RIAK_DEFAULT_BUCKET_NAME;

        return $defaultConfig;
    }
}