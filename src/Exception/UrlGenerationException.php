<?php

declare(strict_types=1);

namespace SilenZ\Switchyard\Exception;

use InvalidArgumentException;

/**
 * Thrown when a URL can't be generated: the route name is unknown or a parameter is missing or invalid.
 */
final class UrlGenerationException extends InvalidArgumentException {}
