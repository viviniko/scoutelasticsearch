<?php

namespace Viviniko\Scoutelasticsearch\Console\Commands;

use Elasticsearch\ClientBuilder as ElasticBuilder;
use Illuminate\Console\Command;

class RebuildCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:rebuild {model} {--mapping} {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Elasticsearch rebuild index of the given model.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $model = $this->argument('model');

        $mapping = $this->option('mapping');

        $force = $this->option('force');

        $config = config('scoutelasticsearch');

        $client = ElasticBuilder::create()->setHosts($config['hosts'])->build();

        $index = $config['index'];

        $taskFile = storage_path('app/public') . '/.elastic_building_' . $index;

        if ($force) {
            @unlink($taskFile);
        }

        if (file_exists($taskFile)) {
            $this->error('Rebuild running. Retry --force option.');
            return ;
        }

        touch($taskFile);
        if ($client->indices()->exists(['index' => $index])) {
            if ($mapping) {
                $tmpIndex = $index . '_tmp';

                if ($client->indices()->exists(['index' => $tmpIndex])) {
                    $this->info('Delete index ' . $tmpIndex);
                    $this->info('<comment>' . json_encode($client->indices()->delete(['index' => $tmpIndex])) . '</comment>');
                }

                $this->info('Mapping model ' . $model);
                $this->call('scout:mapping', ['model' => $model, '--index' => $tmpIndex]);

                $engine = app(\Laravel\Scout\EngineManager::class)->engine();
                $engineIndex = $engine->getIndex();
                $engine->setIndex($tmpIndex);
                $this->info('Import model ' . $model);
                $this->call('scout:import', ['model' => $model]);
                $engine->setIndex($engineIndex);

                $this->info('Delete index ' . $index);
                $this->info('<comment>' . json_encode($client->indices()->delete(['index' => $index])) . '</comment>');
                $this->call('scout:mapping', ['model' => $model, '--index' => $index]);

                $client->indices()->refresh(['index' => $tmpIndex]);
                $size = data_get($client->count(['index' => $tmpIndex]), 'count', 0);
                if ($size > 0) {
                    $this->info("Rename index {$tmpIndex} -> {$index}, Total Size: {$size}");
                    $this->info('<comment>' . json_encode($client->reindex(['body' => ['size' => $size + 1, 'source' => ['index' => $tmpIndex], 'dest' => ['index' => $index, 'version_type' => 'external']]])) . '</comment>');
                }

                $this->info('Delete index ' . $tmpIndex);
                $this->info('<comment>' . json_encode($client->indices()->delete(['index' => $tmpIndex])) . '</comment>');
            } else {
                $this->info('Import model ' . $model);
                $this->call('scout:import', ['model' => $model]);
            }
        } else {
            $this->info('Mapping model ' . $model);
            $this->call('scout:mapping', ['model' => $model]);

            $this->info('Import model ' . $model);
            $this->call('scout:import', ['model' => $model]);
        }
        @unlink($taskFile);

        $this->info('Elastic rebuild completed.');
    }
}
