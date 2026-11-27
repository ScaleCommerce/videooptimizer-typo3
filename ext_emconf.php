<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'VideoOptimizer',
    'description' => 'List and embed videos from videooptimizer.eu in TYPO3.',
    'category' => 'fe',
    'state' => 'stable',
    'version' => '2.1.0',
    'author' => 'ScaleCommerce GmbH',
    'author_email' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'fluid_styled_content' => '14.3.0-14.99.99',
            'php' => '8.4.0-8.5.99',
        ],
    ],
];
