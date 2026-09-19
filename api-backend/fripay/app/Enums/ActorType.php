<?php

namespace App\Enums;

enum ActorType: string
{
    case User = 'user';
    case Staff = 'staff';
    case System = 'system';
}
