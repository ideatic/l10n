<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

use Exception;

/**
 * A string of this library and its Angular message do not have the same structure, or a translation uses a tag or an
 * ICU expression the source does not have
 */
final class MappingException extends Exception {}
