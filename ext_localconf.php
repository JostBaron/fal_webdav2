<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

/** @var \TYPO3\CMS\Core\Resource\Driver\DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
    \TYPO3\CMS\Core\Resource\Driver\DriverRegistry::class
);
$driverRegistry->registerDriverClass(
    \JostBaron\FalWebdav\Driver\WebdavDriver::class,
    \JostBaron\FalWebdav\Driver\WebdavDriver::DRIVER_KEY,
    'Database driver for FAL',
    'FILE:EXT:fal_webdav2/Configuration/FlexForm/DriverFlexForm.xml'
);
