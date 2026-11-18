<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Base controller for the application, providing authorization helpers (AuthorizesRequests)
 * to all controllers that extend it.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
}
