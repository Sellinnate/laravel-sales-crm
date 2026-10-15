<?php

namespace Sellinnate\SalesCrm\Exceptions;

use RuntimeException;

/**
 * An import stopped by its file (unreadable, unsupported format): the message is safe to show.
 */
class ImportException extends RuntimeException {}
