<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\IsSingleton;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The officials and form details shared by every disbursement voucher.
 */
#[Fillable(['mode_of_payment', 'fund_cluster', 'responsibility_center', 'supervisor_name', 'supervisor_title', 'accountant_name', 'accountant_title', 'approver_name', 'approver_title'])]
class VoucherSetting extends Model
{
    use Audited, IsSingleton;

    /**
     * Values for the row created on a fresh install. The officials are left blank for the office to fill in.
     *
     * @return array<string, mixed>
     */
    protected static function defaultValues(): array
    {
        return [
            'mode_of_payment' => 'Check',
            'supervisor_title' => 'Supervisor, Requesting Unit',
            'accountant_title' => 'Head, Accounting Unit',
            'approver_title' => 'Agency Head',
        ];
    }
}
