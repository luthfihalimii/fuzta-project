<?php

return [
    'timezone' => 'Asia/Jakarta',

    'early_open_minutes' => 15,

    'reminder_minutes' => 30,

    'auto_close_grace_minutes' => 15,

    'windows' => [
        [
            'key' => 'morning',
            'label' => 'Shift 1',
            'start' => '09:00',
            'end' => '15:00',
        ],
        [
            'key' => 'evening',
            'label' => 'Shift 2',
            'start' => '15:00',
            'end' => '21:00',
        ],
    ],
];
