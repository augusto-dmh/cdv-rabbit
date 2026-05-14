<?php

namespace App\Support;

enum RetryDecision
{
    case Terminal;
    case RetryWithBackoff;
    case PauseWorkspace;
}
