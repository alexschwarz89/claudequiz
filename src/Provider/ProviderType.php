<?php

declare(strict_types=1);

namespace App\Provider;

enum ProviderType: string
{
    case Api       = 'API';
    case Wikipedia = 'WIKIPEDIA';
    case File      = 'FILE';
}
