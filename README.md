# Laravel OpenSearch Engine
## Installation

`composer require romangrinev/laravel-opensearch-engine`

Update your `App\Providers\AppServiceProvider`

```php
<?php

namespace App\Providers;

// ...

use Grinev\LaravelOpenSearchEngine\OpenSearchEngine;
use Laravel\Scout\EngineManager;

class AppServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // ...

        resolve(EngineManager::class)->extend(config('scout.driver'), function () {
            return new OpenSearchEngine;
        });

    }
}
```

Update `config\scout.php`

```php
<?php

return [

    //

    'driver' => env('SCOUT_DRIVER', 'opensearch'),

    'opensearch' => [
        'host' => env('OPENSEACH_HOST', 'http://localhost:9200'),
        'user' => env('OPENSEACH_USER', 'admin'),
        'pass' => env('OPENSEACH_PASS', 'admin'),
    ],

    //
];
```

## Usage
### Search by keyphrase
```php
$posts = Post::search('Key phrase')->get();
```

### Order search results
```php
$posts = Post::search()->orderBy('posted_at', 'desc')->get();
```

### Search by term
```php
$posts = Post::search()->where('category_id', '48')->get();
```

### Search by range
```php
$posts = Post::search()->where('range', [
    'price' => [
        'gte' => 100,
        'lte' => 200
    ]
])->get();
```
Learn more about [OpenSearch Range Queries](https://opensearch.org/docs/2.0/opensearch/supported-field-types/range/#range-query)

### Seach by geo location
Here is more about [OpenSearch Geo-bounding box queries](https://opensearch.org/docs/2.5/opensearch/query-dsl/geo-and-xy/geo-bounding-box/)
```php
$posts = Post::search()->->where('geo_bounding_box', [
    "location" => [
        "top_left" => [
            "lat" => 48.0,
            "lon" => -123.0
        ],
        "bottom_right" => [
            "lat" => 46.0,
            "lon" => -121.0
        ]
    ]
])->get();
```
In order to perform this type of seary make sure the mapping for the `location` field is set as `geo_point` in your `config/scout.php` config file.

#### config/scout.php
```php
<?php
return [
    //
    'opensearch' => [
        //
        'mappings' => [
            'posts_index' =>[
                "mappings" => [
                    "properties" => [
                        "location" => [
                            "type" => "geo_point"
                        ]
                    ]
                ]
            ]
        ]
    ],
];
```

#### app/models/Post.php
```php
    public function searchableAs(){
        return 'posts_index';
    }
	public function toSearchableArray(){
		$data = [
            //
			'location' => "{$this->lat},{$this->lng}",
		];

		return $data;
	}
```

After changing `scout.php` for mapping and updating the `toSearchableArray()` make sure to update your OpenSearch index like this:

```bash
php artisan scout:flush App\\Models\\Post
php artisan scout:index App\\Models\\Post
php artisan scout:import App\\Models\\Post
```
### Group By / Aggregate results
This example shows how to group by / aggregate open search results. 
```php
$raw = Post::search('Key phrase')
    ->whereRaw([
        'aggs' => [
            'categories' => [
                'terms' => [
                    'field' => 'category_id'
                ]
            ]
        ]
    ])->raw();
$categories = collect(data_get($raw, 'aggregations.categories.buckets', []))->pluck('key')->map(fn($id) => Category::find($id));
```
Learn more about [OpenSearch Aggregations](https://opensearch.org/docs/latest/aggregations/)
---
Learn more about [Laravel Scout](https://laravel.com/docs/10.x/scout)
