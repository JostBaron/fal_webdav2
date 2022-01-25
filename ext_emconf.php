<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'WebDAV driver for FAL',
    'description' => 'TYPO3 FAL storage driver for storing files on a WebDAV directory',
    'category' => 'misc',
    'author' => 'Jost Baron',
    'author_email' => 'j.baron@netzkoenig.de',
    'author_company' => 'Mein Bauernhof GbR',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-10.4.999',
        ]
    ]
];
