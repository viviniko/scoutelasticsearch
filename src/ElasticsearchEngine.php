<?php

namespace Viviniko\Scoutelasticsearch;

use Closure;
use Elasticsearch\Client as Elastic;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ElasticsearchEngine extends Engine
{
    /**
     * Index where the models will be saved.
     *
     * @var string
     */
    protected $index;

    /**
     * @var array
     */
    protected static $modelResolvers = [];

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elastic
     * @return void
     */
    public function __construct(Elastic $elastic, $index)
    {
        $this->elastic = $elastic;
        $this->index = $index;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $params['body'] = [];
        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'update' => [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => '_doc',
                ]
            ];
            $params['body'][] = [
                'doc' => $model->toSearchableArray(),
                'doc_as_upsert' => true
            ];
        });
        $this->elastic->bulk($params);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $params['body'] = [];
        $models->each(function($model) use (&$params)
        {
            $params['body'][] = [
                'delete' => [
                    '_id' => $model->getKey(),
                    '_index' => $this->index,
                    '_type' => '_doc',
                ]
            ];
        });
        $this->elastic->bulk($params);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from' => (($page * $perPage) - $perPage),
            'size' => $perPage,
        ]);
        $result['nbPages'] = $this->getTotalCount($result)/$perPage;
        return $result;
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $this->elastic->delete([
            'index' => $this->index,
            'type' => '_doc'
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type' => '_doc',
            'body' => [
                'query' => [
                    'bool' => [
                    ]
                ]
            ]
        ];
        if ($builder->query) {
            $params['body']['query']['bool']['must'] = [['query_string' => [ 'query' => "*{$builder->query}*"]]];
        }
        if ($sort = $this->sort($builder)) {
            $params['body']['sort'] = $sort;
        }
        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }
        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }
        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['must'] = array_merge($params['body']['query']['bool']['must'] ?? [],
                $options['numericFilters']);
        }
        if (property_exists($builder, 'rawBody')) {
            $params['body'] = array_merge_recursive($params['body'], $builder->rawBody);
        }
        if (property_exists($builder, 'rawFilters')) {
            $params['body']['query'] = array_merge_recursive($params['body']['query'], $builder->rawFilters);
        }
        $params['body']['_source'] = false;
        if (property_exists($builder, 'fields')) {
            $params['body']['_source'] = $builder->fields;
        }
        if (empty($params['body']['query']['bool'])) {
            unset($params['body']['query']);
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $filters = [];

        foreach ($builder->wheres as $key => $value) {
            if (Str::contains($key, ':')) {
                $key = explode(':',$key)[0];
            }
            if (is_array($value)) {
                if (Str::contains($key, '.')) {
                    list ($filter, $leftKey) = explode('.', $key, 2);
                    if ($filter == 'term') {
                        foreach ($value as $v) {
                            $filters[] = ['term' => [$leftKey => $v]];
                        }
                    } else if ($filter == 'range') {
                        $range = [];
                        if (!empty($value[0]) || $value[0] == 0) {
                            $range['gte'] = $value[0];
                        }
                        if (!empty($value[1]) || $value[1] == 0) {
                            $range['lte'] = $value[1];
                        }
                        $filters[] = ['range' => [$leftKey => $range]];
                    }
                } else {
                    $filters[] = ['terms' => [$key => array_values($value)]];
                }
            } else {
                $filters[] = ['match_phrase' => [$key => $value]];
            }
        }

        return $filters;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param Builder $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) === 0) {
            return Collection::make();
        }

        return call_user_func($this->getModelResolver($model), $results, $model);
    }

    public function getModelResolver($model)
    {
        foreach (array_keys(self::$modelResolvers) as $class) {
            if ($model instanceof $class) {
                return self::$modelResolvers[$class];
            }
        }

        return function ($results, $model) {
            $keys = collect($results['hits']['hits'])->pluck('_id')->values();
            $models = collect([]);
            if ($keys->isNotEmpty()) {
                $models = $model->whereIn($model->getKeyName(), $keys)->get()->keyBy($model->getKeyName());;
            }

            return collect($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
                return isset($models[$hit['_id']]) ? $models[$hit['_id']] : false;
            })->filter()->values();
        };
    }

    public static function registerModelResolver($model, Closure $resolver)
    {
        self::$modelResolvers[$model] = $resolver;
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'];
    }

    /**
     * Set index.
     *
     * @param $index
     * @return $this
     */
    public function setIndex($index)
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Get index
     *
     * @return string
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * Generates the sort if theres any.
     *
     * @param  Builder $builder
     * @return array|null
     */
    protected function sort($builder)
    {
        if (count($builder->orders) == 0) {
            return null;
        }
        return collect($builder->orders)->map(function($order) {
            return [$order['column'] => $order['direction']];
        })->toArray();
    }
}