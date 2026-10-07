<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'calendar#confirm',
            'url' => '/confirm',
            'verb' => 'GET',
        ],
        [
            'name' => 'calendar#create',
            'url' => '/create',
            'verb' => 'POST',
        ],
    ],
];