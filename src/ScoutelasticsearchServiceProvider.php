<?php

namespace Viviniko\Scoutelasticsearch;

use Viviniko\Scoutelasticsearch\Console\Commands\MappingCommand;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Scout\EngineManager;
use Elasticsearch\ClientBuilder as ElasticBuilder;
use Viviniko\Scoutelasticsearch\Console\Commands\RebuildCommand;

class ScoutelasticsearchServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        // Publish config files
        if ($this->app->runningInConsole()) {
            $this->commands([
                MappingCommand::class,
                RebuildCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scoutelasticsearch.php' => config_path('scoutelasticsearch.php'),
            ]);
        }

        app(EngineManager::class)->extend('elasticsearch', function($app) {
            $config = $app['config']['scoutelasticsearch'];
            return new ElasticsearchEngine(
                ElasticBuilder::create()->setHosts($config['hosts'])->build(),
                $config['index']
            );
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scoutelasticsearch.php', 'scoutelasticsearch');
    }
}