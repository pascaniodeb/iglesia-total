<?php

namespace App\Modules\Treasury\Domain\Rates;

use RuntimeException;

/**
 * An official rate source did not answer or answered something invalid: that day is not
 * updated.
 */
final class RatesUnavailable extends RuntimeException {}
