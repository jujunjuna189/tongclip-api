<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'description', 'image_url', 'duration', 'level', 'url'])]
class Course extends Model {}
