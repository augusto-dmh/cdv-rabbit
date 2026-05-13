<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentType: string
{
    case Summary = 'summary';
    case Inline = 'inline';
    case Error = 'error';
}
