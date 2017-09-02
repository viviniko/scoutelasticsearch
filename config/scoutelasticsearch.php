<?php

return [

    'index' => env('ELASTICSEARCH_INDEX', 'viviniko'),

    'hosts' => [
        env('ELASTICSEARCH_HOST', 'http://localhost:9200'),
    ],

];