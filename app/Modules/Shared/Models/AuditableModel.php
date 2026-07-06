<?php

namespace App\Modules\Shared\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableConcern;
use OwenIt\Auditing\Contracts\Auditable;

abstract class AuditableModel extends Model implements Auditable
{
    use AuditableConcern;
}
