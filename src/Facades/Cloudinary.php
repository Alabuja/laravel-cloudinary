<?php

namespace Unicodeveloper\Cloudinary\Facades;

use Illuminate\Support\Facades\Facade;
use Unicodeveloper\Cloudinary\CloudinaryEngine;

class Cloudinary extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return CloudinaryEngine::class;
    }
}
