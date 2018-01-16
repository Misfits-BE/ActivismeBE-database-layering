<?php

namespace ActivismeBE\DatabaseLayering\Repositories\Traits;

use ActivismeBE\DatabaseLayering\Repositories\Contracts\CriteriaInterface;
use ActivismeBE\DatabaseLayering\Repositories\Helpers\CacheKeys;
use Exception;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ReflectionObject;

/**
 * Trait CachableRepository
 *
 * @package ActivismeBE\DatabaseLayering\Repositories\Traits
 */
trait CachableRepository
{
    /**
     * @var CacheRepository
     */
    protected $cacheRepository = null;

    /**
     * Set Cache Repository
     *
     * @param  CacheRepository $repository
     * @return $this
     */
    public function setCacheRepository(CacheRepository $repository)
    {
        $this->cacheRepository = $repository;
        return $this;
    }

    /**
     * Return instance of Cache Repository
     *
     * @return CacheRepository
     */
    public function getCacheRepository()
    {
        if (is_null($this->cacheRepository)) {
            $this->cacheRepository = app(config('repositories.cache.repository', 'cache'));
        }

        return $this->cacheRepository;
    }

    /**
     * Skip Cache
     *
     * @param  bool $status
     * @return $this
     */
    public function skipCache($status = true)
    {
        $this->cacheSkip = $status;
        return $this;
    }

    /**
     * @return bool
     */
    public function isSkippedCache()
    {
        $skipped        = isset($this->cacheSkip) ? $this->cacheSkip : false;
        $request        = app('Illuminate\Http\Request');
        $skipCacheParam = config('repositories.cache.params.skipCache', 'skipCache');

        if ($request->has($skipCacheParam) && $request->get($skipCacheParam)) {
            $skipped = true;
        }

        return $skipped;
    }

    /**
     * @param  $method
     * @return bool
     */
    public function allowedCache($method)
    {
        $cacheEnabled = config('repositories.cache.enabled', true);

        if (! $cacheEnabled) {
            return false;
        }

        $cacheOnly   = isset($this->cacheOnly) ? $this->cacheOnly : config('repositories.cache.aloowed.only', null);
        $cacheExcept = isset($this->cacheExcept) ? $this->cacheExcept : config('repositories.cache.allowed.except', null);

        if (is_array($cacheOnly)) {
            return ! in_array($method, $cacheExcept);
        }

        if (is_null($cacheOnly) && is_null($cacheExcept)) {
            return true;
        }

        return false;
    }

    /**
     * Get Cache key for the method.
     *
     * @param  $method
     * @param  args
     * @return string
     */
    public function getCacheKey($method, $args = null)
    {
        $request  = app('Illuminate\Http\Request');
        $args     = serialize($args);
        $criteria = $this->serializeCriteria();
        $key      = sprintf('%s@%s-%s', get_called_class(), $method, md5($args . $criteria . $request->fullUrl()));

        CacheKeys::putKey(get_called_class(), $key);

        return $key;
    }

    /**
     * Serialize the criteria making sure the Closures are taken care of.
     *
     * @return string
     */
    protected function serializeCriteria()
    {
        try {
            return serialize($this->getCriteria());
        } catch (Exception $exception) {
            return serialize($this->getCriteria()->map(function ($criterion) {
                return $this->serializeCriterion($criterion);
            }));
        }
    }

    /**
     * Serialie single criterion with customized serialization of Closures.
     *
     * @param  \ActivismeBE\DatabaseLayering\Repositories\Contracts\CriteriaInterface $criterion
     *
     * @return \ActivismeBE\DatabaseLayering\Repositories\Contracts\CriteriaInterface|array
     *
     * @throws \Exception
     */
    protected function serializeCriterion($criterion)
    {
        try {
            serialize($criterion);
            return $criterion;
        } catch (Exception $exception) {
            // We want to take care of the closure serialization errors.
            // other than that we wll re-throw the exception.
            if ($exception->getMessage() !== "Serialization of 'Closure' is not allowed") {
                throw $exception;
            }

            $r = new ReflectionObject($criterion);

            return [
                'hash'       => md5((string)$r),
                'properties' => $r->getProperties(),
            ];
        }
    }

    /**
     * Get cache minutes
     *
     * @return integer
     */
    public function getCacheMinutes()
    {
        $cacheMinutes = isset($this->cacheMinutes) ? $this->cacheMinutes : config('repositories.cache.minutes', 30);
        return $cacheMinutes;
    }

    /**
     * Retrieve all data pf the repository model.
     *
     * @param  array $columns
     * @return mixed
     */
    public function all($columns = ['*'])
    {
        if (! $this->allowedCache('all') || $this->isSkippedCache()) {
            return parent::all($columns);
        }

        $key     = $this->getCacheKey('all', func_get_args());
        $minutes = $this->getCacheMinutes();
        $value   = $this->getCacheRepository()->remember($key, $minutes, function () use ($columns) {
            return parent::all($columns);
        });

        return $value;
    }

    /**
     * Retrieve all data of repository, paginated
     *
     * @param  null  $limit
     * @param  array $columns
     * @return mixed
     */
    public function paginate($limit = null, $columns = ['*'])
    {
        if (! $this->allowedCache('paginate') || $this->isSkippedCache()) {
            return parent::paginate($limit, $columns);
        }

        $key     = $this->getCacheKey('paginate', func_get_args());
        $minutes = $this->getCacheMinutes();
        $value   = $this->getCacheRepository()->remember($key, $minutes, function () use ($limit, $columns) {
            return parent::paginate($limit, $columns);
        });

        return $value;
    }

    /**
     * Find data by id
     *
     * @param  int   $id
     * @param  array $columns
     * @return mixed
     */
    public function find($id, $columns = ['*'])
    {
        if (! $this->allowedCache('find') || $this->isSkippedCache()) {
            return parent::find($id, $columns);
        }

        $key     = $this->getCacheKey('find', func_get_args());
        $minutes = $this->getCacheMinutes();
        $value   = $this->getCacheRepository()->remember($key, $minutes, function () use ($id, $columns) {
            return parent::find($id, $columns);
        });

        return $value;
    }
    /**
     * Find data by field and value
     *
     * @param  string $field
     * @param  string $value
     * @param  array  $columns
     * @return mixed
     */
    public function findByField($field, $value = null, $columns = ['*'])
    {
        if (! $this->allowedCache('findByField') || $this->isSkippedCache()) {
            return parent::findByField($field, $value, $columns);
        }

        $key     = $this->getCacheKey('findByField', func_get_args());
        $minutes = $this->getCacheMinutes();
        $value   = $this->getCacheRepository()->remember($key, $minutes, function () use ($field, $value, $columns) {
            return parent::findByField($field, $value, $columns);
        });

        return $value;
    }
    /**
     * Find data by multiple fields
     *
     * @param array $where
     * @param array $columns
     *
     * @return mixed
     */
    public function findWhere(array $where, $columns = ['*'])
    {
        if (! $this->allowedCache('findWhere') || $this->isSkippedCache()) {
            return parent::findWhere($where, $columns);
        }
        
        $key     = $this->getCacheKey('findWhere', func_get_args());
        $minutes = $this->getCacheMinutes();
        $value   = $this->getCacheRepository()->remember($key, $minutes, function () use ($where, $columns) {
            return parent::findWhere($where, $columns);
        });

        return $value;
    }
    /**
     * Find data by Criteria
     *
     * @param CriteriaInterface $criteria
     *
     * @return mixed
     */
    public function getByCriteria(CriteriaInterface $criteria)
    {
        if (! $this->allowedCache('getByCriteria') || $this->isSkippedCache()) {
            return parent::getByCriteria($criteria);
        }

        $key     = $this->getCacheKey('getByCriteria', func_get_args());
        $minutes = $this->getCacheMinutes();
        $value   = $this->getCacheRepository()->remember($key, $minutes, function () use ($criteria) {
            return parent::getByCriteria($criteria);
        });

        return $value;
    }
}
