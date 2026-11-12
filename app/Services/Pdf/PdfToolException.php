<?php

namespace App\Services\Pdf;

use RuntimeException;

/** A PDF tool could not do its job; the message is shown to the reader as it is. */
class PdfToolException extends RuntimeException {}
