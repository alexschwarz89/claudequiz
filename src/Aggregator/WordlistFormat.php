<?php

declare(strict_types=1);

namespace App\Aggregator;

enum WordlistFormat: string
{
    case Newline = 'newline';
    case Csv     = 'csv';
}
