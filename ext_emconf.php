<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'WebDAV driver for FAL',
    'description' => 'TYPO3 FAL storage driver for storing files on a WebDAV directory',
    'category' => 'misc',
    'author' => 'Jost Baron',
    'author_email' => 'j.baron@netzkoenig.de',
    'author_company' => 'Mein Bauernhof GbR',
    'state' => 'alpha',
    'version' => '2.0.0',
    'constraints' => [
        'depends' => [
            'php' => '8.1.0-8.99.99',
            'typo3' => '12.4.0-12.4.99',
        ]
    ]
];
