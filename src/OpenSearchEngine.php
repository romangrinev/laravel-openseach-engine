<?php

namespace Grinev\LaravelOpenSearchEngine;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Builder;
use Exception;
use Arr;

class OpenSearchEngine extends Engine
{

    protected $softDelete;
    protected $url;

    public function __construct($softDelete = false)
    {
        $this->softDelete = $softDelete;
        $this->url = config('scout.opensearch.host');
    }

    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $models->first()->searchableAs();

        if(in_array(SoftDeletes::class, class_uses_recursive($models->first())) && $this->softDelete){
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }
            return array_merge(
                [
                	'id' => $model->getScoutKey()
                ],
                $searchableData
            );
        })->filter()->values()->all();

        if (! empty($objects)) {
            foreach($objects as $object){
                $response = Http::withBasicAuth(config('scout.opensearch.user'), config('scout.opensearch.pass'))
                	->post($this->url . '/' . $index . '/_doc/' . $object['id'], $object);
                $res = json_decode($response, true);
            }
        }
    }

    public function delete($models){

        $index = $models->first()->searchableAs();

        $ids = $models->map(function ($model) {
            return $model->getScoutKey();
        })->values()->all();

        foreach($ids as $id){
            $response = Http::withBasicAuth(config('scout.opensearch.user'), config('scout.opensearch.pass'))
            	->delete($this->url . '/' . $index . '/_doc/' . $id);
        }
    }

    protected function performSearch(Builder $builder, array $options = [], bool $skipCallback = false)
    {
        if ($builder->callback && !$skipCallback) {
            return call_user_func(
                $builder->callback,
                $this,
                $builder,
                $options
            );
        }

        $url = $this->url . '/' . $builder->model->searchableAs() . '/_search';

        $response = Http::withBasicAuth(
            config('scout.opensearch.user'),
            config('scout.opensearch.pass')
        )->post($url, $options);
        self::errors($response);

        return $response->json('hits');
    }

    public function search(Builder $builder) {
        $options = [
            '_source' => true,
            'size' => $builder->limit ? $builder->limit : 10,
            'from' => 0,
        ];
        $options['query'] = $this->filters($builder);

        return $this->performSearch($builder, $options);
    }

    public function searchRaw(Builder $builder, array $options) {
        return $this->performSearch($builder, $options, true);
    }

    public function paginate(Builder $builder, $limit, $page){
        return $this->performSearch($builder, array_filter([
            '_source' => true,
            'query' => $this->filters($builder),
            'size' =>  $limit ? $limit : 10,
            'from' => ($page - 1) * $limit
        ]));
    }

    protected function filters(Builder $builder){
        $query = null;
        if($builder->query){
            $fields = $builder->model->searchableFields();
            $query = [
                'bool' => [
                    'must' => [
                        [
                            'simple_query_string' => [
                                'query' => $builder->query,
                                'fields' => $fields,
                                'default_operator' => 'and',
                            ]
                        ]
                    ]
                ]
            ];
        }

        if(count($builder->wheres) > 0){
            if(!isset($query)) $query = [];
            if(!isset($query['bool'])) $query['bool'] = [];
            foreach($builder->wheres as $key => $value){
                if($key && $value){
                    $query['bool']['filter'][] = [
                        'match' => [
                            $key => [
                                'query' => $value,
                                'operator' => 'and'
                            ]
                        ]
                    ];
                }
            }
        }

        return $query;
    }

    public function map(Builder $builder, $results, $model)
    {
        if (!is_array($results) || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $ids = $this->mapIds($results);
        $positions = array_flip($ids->toArray());

        return $model->getScoutModelsByIds($builder, $ids->toArray())->sortBy(function ($model) use ($positions) {
            return $positions[$model->getScoutKey()];
        })->values();
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $ids = $this->mapIds($results);
        $positions = array_flip($ids->toArray());

        return $model->queryScoutModelsByIds(
                $builder, $ids
            )->cursor()->sortBy(function ($model) use ($objectIdPositions) {
                return $positions[$model->getScoutKey()];
            })->values();
    }

    public function mapIds($results){
        return collect($results['hits'])->pluck('_id')->values();
    }

    public function getTotalCount($results)
    {
        return (int) Arr::get($results, 'total.value', count($results['hits']));
    }

    public function flush($model)
    {
    	$index = $model->searchableAs();
    	$this->deleteIndex($index);
    }

    public function createIndex($name, array $options = [])
    {
        throw new Exception('OpenSearch indexes are created automatically upon adding objects.');
    }

    public function deleteIndex($name)
    {
        $response = Http::withBasicAuth(config('scout.opensearch.user'), config('scout.opensearch.pass'))
        	->delete($this->url . '/' . $name);
        if($response->status() != 200){
        	return throw new Exception($response->reason());
        }
    }

    public static function errors(Response $response){
        if($response->status() != 200){
            $data = $response->json();
            $reason = Arr::has($data, 'error.reason') ? Arr::get($data, 'error.reason') : $response->getReasonPhrase();
            return throw new Exception($reason);
        }
    }

}
