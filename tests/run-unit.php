<?php
/**
 * Minimal test runner for pure unit tests (no WordPress environment needed).
 * Run: php tests/run-unit.php
 */

$phpunit = __DIR__ . '/../vendor/phpunit/phpunit/phpunit';
$config  = __DIR__ . '/../phpunit.xml.dist';
$filter  = 'ContainerTest|PremiumTest|HeadingLocatorTest|BlockBuilderTest|InsertionServiceTest|NormalizerTest|MatcherTest|FeaturedImageServiceTest|SanitizerExcludedSlugsTest';

$cmd = sprintf(
    'php %s --configuration %s --filter %s --no-coverage',
    escapeshellarg( $phpunit ),
    escapeshellarg( $config ),
    escapeshellarg( $filter )
);

passthru( $cmd, $exit );
exit( $exit );
