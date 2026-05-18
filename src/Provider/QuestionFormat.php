<?php

declare(strict_types=1);

namespace App\Provider;

enum QuestionFormat: string
{
    case TrueFalse      = 'true_false';
    case MultipleChoice = 'multiple_choice';
}
