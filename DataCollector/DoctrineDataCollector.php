<?php

namespace Doctrine\Bundle\DoctrineBundle\DataCollector;

use Doctrine\ORM\Cache\CacheConfiguration;
use Doctrine\ORM\Cache\Logging\CacheLoggerChain;
use Doctrine\ORM\Cache\Logging\StatisticsCacheLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector as BaseCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function array_map;
use function array_sum;
use function assert;
use function count;
use function usort;

class DoctrineDataCollector extends BaseCollector
{
    /** @var ManagerRegistry */
    private $registry;

    /** @var int|null */
    private $invalidEntityCount;

    /** @var string[] */
    private $groupedQueries;

    /** @var bool */
    private $shouldValidateSchema;

    public function __construct(ManagerRegistry $registry, bool $shouldValidateSchema = true)
    {
        $this->registry             = $registry;
        $this->shouldValidateSchema = $shouldValidateSchema;

        parent::__construct($registry);
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Request $request, Response $response, ?Throwable $exception = null)
    {
        parent::collect($request, $response, $exception);

        $errors   = [];
        $entities = [];
        $caches   = [
            'enabled' => false,
            'log_enabled' => false,
            'counts' => [
                'puts' => 0,
                'hits' => 0,
                'misses' => 0,
            ],
            'regions' => [
                'puts' => [],
                'hits' => [],
                'misses' => [],
            ],
        ];

        foreach ($this->registry->getManagers() as $name => $em) {
            assert($em instanceof EntityManagerInterface);
            if ($this->shouldValidateSchema) {
                $entities[$name] = [];

                $factory   = $em->getMetadataFactory();
                $validator = new SchemaValidator($em);

                foreach ($factory->getLoadedMetadata() as $class) {
                    assert($class instanceof ClassMetadataInfo);
                    if (isset($entities[$name][$class->getName()])) {
                        continue;
                    }

                    $classErrors                        = $validator->validateClass($class);
                    $entities[$name][$class->getName()] = $class->getName();

                    if (empty($classErrors)) {
                        continue;
                    }

                    $errors[$name][$class->getName()] = $classErrors;
                }
            }

            $emConfig = $em->getConfiguration();
            assert($emConfig instanceof Configuration);
            $slcEnabled = $emConfig->isSecondLevelCacheEnabled();

            if (! $slcEnabled) {
                continue;
            }

            $caches['enabled'] = true;

            $cacheConfiguration = $emConfig->getSecondLevelCacheConfiguration();
            assert($cacheConfiguration instanceof CacheConfiguration);
            $cacheLoggerChain = $cacheConfiguration->getCacheLogger();
            assert($cacheLoggerChain instanceof CacheLoggerChain || $cacheLoggerChain === null);

            if (! $cacheLoggerChain || ! $cacheLoggerChain->getLogger('statistics')) {
                continue;
            }

            $cacheLoggerStats = $cacheLoggerChain->getLogger('statistics');
            assert($cacheLoggerStats instanceof StatisticsCacheLogger);
            $caches['log_enabled'] = true;

            $caches['counts']['puts']   += $cacheLoggerStats->getPutCount();
            $caches['counts']['hits']   += $cacheLoggerStats->getHitCount();
            $caches['counts']['misses'] += $cacheLoggerStats->getMissCount();

            foreach ($cacheLoggerStats->getRegionsPut() as $key => $value) {
                if (! isset($caches['regions']['puts'][$key])) {
                    $caches['regions']['puts'][$key] = 0;
                }

                $caches['regions']['puts'][$key] += $value;
            }

            foreach ($cacheLoggerStats->getRegionsHit() as $key => $value) {
                if (! isset($caches['regions']['hits'][$key])) {
                    $caches['regions']['hits'][$key] = 0;
                }

                $caches['regions']['hits'][$key] += $value;
            }

            foreach ($cacheLoggerStats->getRegionsMiss() as $key => $value) {
                if (! isset($caches['regions']['misses'][$key])) {
                    $caches['regions']['misses'][$key] = 0;
                }

                $caches['regions']['misses'][$key] += $value;
            }
        }

        // Might be good idea to replicate this block in doctrine bridge so we can drop this from here after some time.
        // This code is compatible with such change, because cloneVar is supposed to check if input is already cloned.
        foreach ($this->data['queries'] as &$queries) {
            foreach ($queries as &$query) {
                $query['params'] = $this->cloneVar($query['params']);
                // To be removed when the required minimum version of symfony/doctrine-bridge is >= 4.4
                $query['runnable'] = $query['runnable'] ?? true;
            }
        }

        $this->data['entities'] = $entities;
        $this->data['errors']   = $errors;
        $this->data['caches']   = $caches;
        $this->groupedQueries   = null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getEntities()
    {
        return $this->data['entities'];
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function getMappingErrors()
    {
        return $this->data['errors'];
    }

    /**
     * @return int
     */
    public function getCacheHitsCount()
    {
        return $this->data['caches']['counts']['hits'];
    }

    /**
     * @return int
     */
    public function getCachePutsCount()
    {
        return $this->data['caches']['counts']['puts'];
    }

    /**
     * @return int
     */
    public function getCacheMissesCount()
    {
        return $this->data['caches']['counts']['misses'];
    }

    /**
     * @return bool
     */
    public function getCacheEnabled()
    {
        return $this->data['caches']['enabled'];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getCacheRegions()
    {
        return $this->data['caches']['regions'];
    }

    /**
     * @return array<string, int>
     */
    public function getCacheCounts()
    {
        return $this->data['caches']['counts'];
    }

    /**
     * @return int
     */
    public function getInvalidEntityCount()
    {
        if ($this->invalidEntityCount === null) {
            $this->invalidEntityCount = array_sum(array_map('count', $this->data['errors']));
        }

        return $this->invalidEntityCount;
    }

    /**
     * @return string[]
     */
    public function getGroupedQueries()
    {
        if ($this->groupedQueries !== null) {
            return $this->groupedQueries;
        }

        $this->groupedQueries = [];
        $totalExecutionMS     = 0;
        foreach ($this->data['queries'] as $connection => $queries) {
            $connectionGroupedQueries = [];
            foreach ($queries as $i => $query) {
                $key = $query['sql'];
                if (! isset($connectionGroupedQueries[$key])) {
                    $connectionGroupedQueries[$key]                = $query;
                    $connectionGroupedQueries[$key]['executionMS'] = 0;
                    $connectionGroupedQueries[$key]['count']       = 0;
                    $connectionGroupedQueries[$key]['index']       = $i; // "Explain query" relies on query index in 'queries'.
                }

                $connectionGroupedQueries[$key]['executionMS'] += $query['executionMS'];
                $connectionGroupedQueries[$key]['count']++;
                $totalExecutionMS += $query['executionMS'];
            }

            usort($connectionGroupedQueries, static function ($a, $b) {
                if ($a['executionMS'] === $b['executionMS']) {
                    return 0;
                }

                return $a['executionMS'] < $b['executionMS'] ? 1 : -1;
            });
            $this->groupedQueries[$connection] = $connectionGroupedQueries;
        }

        foreach ($this->groupedQueries as $connection => $queries) {
            foreach ($queries as $i => $query) {
                $this->groupedQueries[$connection][$i]['executionPercent'] =
                    $this->executionTimePercentage($query['executionMS'], $totalExecutionMS);
            }
        }

        return $this->groupedQueries;
    }

    private function executionTimePercentage(int $executionTimeMS, int $totalExecutionTimeMS): float
    {
        if ($totalExecutionTimeMS === 0.0 || $totalExecutionTimeMS === 0) {
            return 0;
        }

        return $executionTimeMS / $totalExecutionTimeMS * 100;
    }

    /**
     * @return int
     */
    public function getGroupedQueryCount()
    {
        $count = 0;
        foreach ($this->getGroupedQueries() as $connectionGroupedQueries) {
            $count += count($connectionGroupedQueries);
        }

        return $count;
    }
}
