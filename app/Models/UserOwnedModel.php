<?php

namespace App\Models;

use App\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;

abstract class UserOwnedModel extends Model
{
    use OwnedByUser;
}
