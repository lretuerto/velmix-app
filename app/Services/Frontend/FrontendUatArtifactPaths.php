<?php

namespace App\Services\Frontend;

class FrontendUatArtifactPaths
{
    public static function baseDirectory(): string
    {
        if (app()->environment('testing')) {
            return storage_path('framework/testing/frontend-uat');
        }

        return storage_path('app/frontend-uat');
    }
}
