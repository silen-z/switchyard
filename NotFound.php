<?php

declare(strict_types=1);

namespace SilenZ\Segmatch\Http;

/**
 * Result of {@see Dispatcher::match()}: no route applies to the path (404).
 */
final readonly class NotFound {}
