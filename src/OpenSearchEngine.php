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
        $this->options = config('scout.opensearch.options');
    }

    public function putRequest($uri, $data){
        $url = $this->url . $uri;
        $response = Http::withBasicAuth(
            config('scout.opensearch.user'),
            config('scout.opensearch.pass')
        )->put($url, $data);
        self::errors($response);

        return $response->json();
    }

    public function postRequest($uri, $data){
        $url = $this->url . $uri;
        $response = Http::withBasicAuth(
            config('scout.opensearch.user'),
            config('scout.opensearch.pass')
        )->post($url, $data);
        self::errors($response);

        return $response->json();
    }

    public function getRequest($uri, $data = []){
        $url = $this->url . $uri;
        $response = Http::withBasicAuth(
            config('scout.opensearch.user'),
            config('scout.opensearch.pass')
        )->get($url, $data);
        self::errors($response);

        return $response->json();
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

        self::errors($response, $options);

        return $response->json();
    }

    public static function optionsBy(Builder $builder, $limit = null, $page = null){
        $size = $limit ? $limit : ($builder->limit ? $builder->limit : 10);
        $from = ($page && $size) ? (($page - 1) * $size) : 0;
        $query = self::filters($builder);

        $options = array_filter([
            '_source' => true,
            'size' => $size,
            'from' => $from
        ]);

        if($query){
            $options['query'] = $query;
        }

        if($builder->orders && is_array($builder->orders)){
            $sort = [];
            foreach($builder->orders as $item){
                $sort[] = [ data_get($item, 'column') => [ 'order' => data_get($item, 'direction') ] ];
            }
            $sort = array_filter($sort);
            if(count($sort) > 0) $options['sort'] = $sort;
        }

        if(isset($builder->rawOptions) && is_array($builder->rawOptions)){
            $options = array_merge($builder->rawOptions, $options);
        }

        return $options;
    }

    public function search(Builder $builder) {
        $options = self::optionsBy($builder);
        return $this->performSearch($builder, $options);
    }

    public function searchRaw(Builder $builder, array $options) {
        return $this->performSearch($builder, $options);
    }

    public function whereRaw(Builder $builder, array $options) {
        $builder->rawOptions = $options;
        return $builder;
    }

    public function paginate(Builder $builder, $limit, $page){
        $options = self::optionsBy($builder, $limit, $page);
        return $this->performSearch($builder, $options);
    }

    protected static function filters(Builder $builder){
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
                                'default_operator' => 'or',
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
                if($key && $value !== null){
                    if(is_array($value)){
                        $query['bool']['must'][] = [
                            $key => $value
                        ];
                    }else{
                        $query['bool']['must'][] = [
                            'term' => [
                                $key => [
                                    'value' => $value
                                ]
                            ]
                        ];
                    }
                }
            }
        }
        return $query;
    }

    public function map(Builder $builder, $results, $model)
    {
        $results = data_get($results, 'hits.hits', []);

        if(count($results) === 0){
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
        $results = data_get($results, 'hits.hits', []);

        if (count($results) === 0) {
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
        return collect($results)->pluck('_id')->values();
    }

    public function getTotalCount($results)
    {
        return (int) Arr::get($results, 'hits.total.value', count(data_get($results, 'hits.hits')));
    }

    public function flush($model)
    {
    	$index = $model->searchableAs();
    	$this->deleteIndex($index);
    }

    public function createIndex($name, array $options = [])
    {
        if(config("scout.opensearch.mappings.$name")){
            $this->putRequest('/' . $name, config("scout.opensearch.mappings.$name"));
        }else{
            throw new Exception('OpenSearch indexes are created automatically upon adding objects.');
        }
    }

    public function deleteIndex($name)
    {
        $response = Http::withBasicAuth(config('scout.opensearch.user'), config('scout.opensearch.pass'))
        	->delete($this->url . '/' . $name);
        self::errors($response);
    }

    public static function errors(Response $response, $options = []){
        if($response->status() != 200){
            $data = $response->json();
            $reason = Arr::has($data, 'error.reason') ? Arr::get($data, 'error.reason') : $response->getReasonPhrase();
            $reason = $reason . print_r($data, true) . print_r($options, true);
            return throw new Exception($reason);
        }
    }

}
