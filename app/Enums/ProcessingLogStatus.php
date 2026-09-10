<?php

namespace App\Enums;

enum ProcessingLogStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Retried = 'retried';
}
