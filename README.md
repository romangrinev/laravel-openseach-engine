# Laravel OpenSearch Engine
## Installation

`composer require romangrinev/laravel-opensearch-engine`

```
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