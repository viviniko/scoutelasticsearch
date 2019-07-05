<?php

namespace Viviniko\Scoutelasticsearch\Console\Commands;

use Elasticsearch\ClientBuilder as ElasticBuilder;
use Illuminate\Console\Command;

class MappingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:mapping {model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elasticsearch mapping the given model.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $class = $this->argument('model');

        $model = new $class;

        $config = config('scoutelasticsearch');

        $client = ElasticBuilder::create()->setHosts($config['hosts'])->build();

        $index = $config['index'];

        if (method_exists($model, 'searchableMapping')) {
            if ($client->indices()->exists(['index' => $index])) {
                $client->indices()->putMapping([
                    'index' => $index,
                    'type' => $model->searchableAs(),
                    'body' => $model->searchableMapping()
                ]);
            } else {
                $client->indices()->create([
                    'index' => $index,
                    'body' => [
                        'settings' => [
                            'number_of_shards' => 1,
                            'number_of_replicas' => 0,
                        ],
                        'mappings' => $model->searchableMapping(),
                    ],
                ]);
            }
        }

        $this->info('Elastic mapping success.');
    }
}
