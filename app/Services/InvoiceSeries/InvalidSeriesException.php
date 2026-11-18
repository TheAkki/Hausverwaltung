<?php

namespace App\Services\InvoiceSeries;

/**
 * Eine Terminfolge, die fachlich nicht stimmt – z. B. ein Ende vor dem Beginn.
 */
final class InvalidSeriesException extends \InvalidArgumentException {}
