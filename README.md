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
Learn more about [OpenSearch Geo-bounding box queries](https://opensearch.org/docs/2.5/opensearch/query-dsl/geo-and-xy/geo-bounding-box/)

Learn more about [Laravel Scout](https://laravel.com/docs/10.x/scout)
