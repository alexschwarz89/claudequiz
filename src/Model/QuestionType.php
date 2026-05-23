<?php

declare(strict_types=1);

namespace App\Model;

enum QuestionType: string
{
    case TrueFalse = 'true_false';
    case SongGuess = 'song_guess';
    case ImageReveal = 'image_reveal';
    case LocationGuess = 'location';
    case FlagMc = 'flag_mc';
    case MultipleChoice = 'multiple_choice';
    case Kopfrechnen = 'kopfrechnen';
    case Gedaechtnisspiel = 'gedaechtnisspiel';
    case FilmScene = 'film_scene';
    case YouTubeCreator = 'youtube_creator';
}
