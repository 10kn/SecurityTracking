<?php

namespace App\Enums;

/**
 * The part a message plays in the Google Chat thread of an incident and team: the full card that opens it, or a
 * compact reply in it.
 */
enum ChatThreadRole: string
{
    case Opener = 'opener';
    case Reply = 'reply';
}
