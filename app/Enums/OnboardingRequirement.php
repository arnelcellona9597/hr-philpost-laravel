<?php

namespace App\Enums;

enum OnboardingRequirement: string
{
    case Pds = 'req_pds';
    case SpecimenSign = 'req_specimen_sign';
    case SwornDeclaration = 'req_sworn_declaration';
    case TinVerification = 'req_tin_verification';
    case PoliceClearance = 'req_police_clearance';
    case MedicalLab = 'req_medical_lab';
    case LandbankAccount = 'req_landbank_account';
    case AssumptionOfDuty = 'req_assumption_of_duty';
    case DriverLicense = 'req_driver_license';
    case OrCr = 'req_or_cr';

    public function label(): string
    {
        return match ($this) {
            self::Pds => 'PDS',
            self::SpecimenSign => 'Specimen Sign',
            self::SwornDeclaration => 'Sworn Declaration',
            self::TinVerification => 'TIN Verification',
            self::PoliceClearance => 'Police Clearance',
            self::MedicalLab => 'Medical Lab',
            self::LandbankAccount => 'Landbank Account',
            self::AssumptionOfDuty => 'Assumption of Duty',
            self::DriverLicense => 'Driver License',
            self::OrCr => 'OR/CR',
        };
    }
}
