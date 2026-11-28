<?php

namespace App\Modules\Integrity\Domain\Anchoring;

use RuntimeException;

/**
 * No service of the provider accepted the anchor (network down, service offline).
 */
final class AnchorUnavailable extends RuntimeException {}
